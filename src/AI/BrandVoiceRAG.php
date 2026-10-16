<?php

declare(strict_types=1);

namespace SocialBot\AI;

use SocialBot\Support\Config;
use SocialBot\Support\Logger;

/**
 * Local brand-voice retrieval (RAG without an embedding service).
 *
 * Brand voice files live in prompts/brand_voice/*.md.  The top-k most relevant
 * chunks for a query are injected into the USER message, never into the
 * system prompt - the system prompts stay byte-identical so DeepSeek's prompt
 * cache keeps hitting (token-budget rule 4).
 *
 * Retrieval is TF-IDF-ish lexical scoring: deterministic, offline, free, and
 * good enough for a handful of brand documents.  Swap in vectors later by
 * replacing retrieve().
 */
final class BrandVoiceRAG
{
    /** @var array<int, array{source:string, text:string}>|null */
    private ?array $chunks = null;

    public function __construct(
        private readonly string $path,
        private readonly int $topK = 3,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) Config::get('brand_voice.path'),
            (int) Config::get('brand_voice.top_k', 3)
        );
    }

    /**
     * @return array<int, string>
     */
    public function files(): array
    {
        if (!is_dir($this->path)) {
            return [];
        }

        $files = glob(rtrim($this->path, '/\\') . '/*.{md,txt}', GLOB_BRACE) ?: [];
        sort($files);

        return $files;
    }

    /**
     * @return array<int, array{source:string, text:string}>
     */
    public function chunks(): array
    {
        if ($this->chunks !== null) {
            return $this->chunks;
        }

        $chunks = [];

        foreach ($this->files() as $file) {
            $content = trim((string) file_get_contents($file));

            if ($content === '') {
                continue;
            }

            foreach (preg_split('/\n\s*\n/', $content) ?: [] as $paragraph) {
                $paragraph = trim($paragraph);

                if (mb_strlen($paragraph) < 30) {
                    continue;
                }

                $chunks[] = ['source' => basename($file), 'text' => $paragraph];
            }
        }

        return $this->chunks = $chunks;
    }

    public function indexSize(): int
    {
        return count($this->chunks());
    }

    /**
     * @return array<int, array{source:string, text:string, score:float}>
     */
    public function retrieve(string $query, ?int $k = null): array
    {
        $k ??= $this->topK;
        $chunks = $this->chunks();

        if ($chunks === []) {
            return [];
        }

        $scored = [];

        foreach ($chunks as $chunk) {
            $score = $this->score($query, $chunk['text']);

            if ($score <= 0.0) {
                continue;
            }

            $scored[] = ['source' => $chunk['source'], 'text' => $chunk['text'], 'score' => round($score, 4)];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, max(1, $k));
    }

    /**
     * Formatted block for the dynamic (user) part of a prompt.
     */
    public function guidance(string $query, ?int $k = null): string
    {
        $matches = $this->retrieve($query, $k);

        if ($matches === []) {
            return '';
        }

        $lines = ['Brand voice reference (follow this tone and these constraints):'];

        foreach ($matches as $match) {
            $lines[] = '- [' . $match['source'] . '] ' . preg_replace('/\s+/u', ' ', $match['text']) ?? '';
        }

        return implode("\n", $lines);
    }

    /**
     * Cosine-ish lexical similarity with IDF weighting.
     */
    private function score(string $query, string $text): float
    {
        $queryTerms = $this->terms($query);

        if ($queryTerms === []) {
            return 0.0;
        }

        $textTerms = array_count_values($this->terms($text));

        if ($textTerms === []) {
            return 0.0;
        }

        $idf = $this->idf();

        $dot = 0.0;
        $queryWeight = 0.0;
        $textWeight = 0.0;

        foreach ($queryTerms as $term) {
            $weight = $idf[$term] ?? 1.0;
            $queryWeight += $weight ** 2;

            if (isset($textTerms[$term])) {
                $dot += $weight * $weight * min(2, $textTerms[$term]);
            }
        }

        foreach ($textTerms as $term => $count) {
            $weight = $idf[$term] ?? 1.0;
            $textWeight += ($weight ** 2) * min(2, $count);
        }

        if ($queryWeight <= 0.0 || $textWeight <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($queryWeight) * sqrt($textWeight));
    }

    /**
     * @return array<int, string>
     */
    private function terms(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $stop = ['the', 'and', 'for', 'with', 'that', 'this', 'from', 'your', 'you', 'are', 'our', 'all', 'any', 'not', 'but', 'can', 'will', 'have', 'has', 'was', 'were', 'its', 'it\'s', 'a', 'an', 'of', 'to', 'in', 'on', 'is', 'be', 'as', 'or', 'at', 'by', 'we', 'us', 'if', 'do', 'does'];

        $terms = [];

        foreach ($words as $word) {
            if ($word === '' || mb_strlen($word) < 3 || in_array($word, $stop, true)) {
                continue;
            }

            $terms[] = $word;
        }

        return $terms;
    }

    /**
     * @return array<string, float>
     */
    private function idf(): array
    {
        $chunks = $this->chunks();
        $total = max(1, count($chunks));
        $documentFrequency = [];

        foreach ($chunks as $chunk) {
            foreach (array_unique($this->terms($chunk['text'])) as $term) {
                $documentFrequency[$term] = ($documentFrequency[$term] ?? 0) + 1;
            }
        }

        $idf = [];

        foreach ($documentFrequency as $term => $frequency) {
            $idf[$term] = log(($total + 1) / ($frequency + 1)) + 1.0;
        }

        return $idf;
    }

    /**
     * Persist a new brand-voice note (used by the dashboard "add guidance" action).
     */
    public function append(string $source, string $text): bool
    {
        $source = preg_replace('/[^A-Za-z0-9._-]/', '', $source) ?: 'notes.md';

        if (!str_ends_with($source, '.md') && !str_ends_with($source, '.txt')) {
            $source .= '.md';
        }

        if (!is_dir($this->path) && !@mkdir($this->path, 0775, true) && !is_dir($this->path)) {
            Logger::error('cannot create brand voice directory', ['path' => $this->path]);

            return false;
        }

        $file = rtrim($this->path, '/\\') . '/' . $source;
        $written = @file_put_contents($file, "\n\n" . trim($text) . "\n", FILE_APPEND | LOCK_EX);

        $this->chunks = null;

        return $written !== false;
    }
}
