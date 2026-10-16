<?php

declare(strict_types=1);

namespace SocialBot\Support;

use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * Message bus used to decouple ingestion from processing.
 *
 * When symfony/messenger is installed the dispatch goes through a real
 * MessageBus (handlers + middleware), which is the seam for moving work onto an
 * async transport later.  Without the package (or when explicitly disabled) the
 * registered handler is invoked directly, so behaviour never changes silently.
 */
final class Bus
{
    private mixed $messageBus = null;

    private bool $messageBusResolved = false;

    /** @var array<string, callable> */
    private array $handlers = [];

    public function __construct(private readonly bool $preferMessenger = true)
    {
    }

    /**
     * Register the handler for a message class.
     *
     * Handlers may be registered after construction: the Symfony bus (and its
     * HandlersLocator, which takes a by-value array snapshot) is built lazily on
     * the first dispatch.
     */
    public function register(string $messageClass, callable $handler): void
    {
        $this->handlers[$messageClass] = $handler;
    }

    public function dispatch(object $message): mixed
    {
        $bus = $this->messageBus();

        if ($bus !== null) {
            $envelope = $bus->dispatch($message);

            // Symfony returns the Envelope, not the handler's return value.
            $handled = method_exists($envelope, 'last') && class_exists(HandledStamp::class)
                ? $envelope->last(HandledStamp::class)
                : null;

            if ($handled instanceof HandledStamp) {
                return $handled->getResult();
            }

            return $envelope;
        }

        $class = $message::class;

        if (isset($this->handlers[$class])) {
            return ($this->handlers[$class])($message);
        }

        if (isset($this->handlers['*'])) {
            return ($this->handlers['*'])($message);
        }

        Logger::warning('no handler registered for message', ['message' => $class]);

        return null;
    }

    public function usesMessenger(): bool
    {
        return $this->messageBus() !== null;
    }

    /**
     * @return array<int, string>
     */
    public function handlers(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Dispatch and swallow transport failures, keeping webhook latency low.
     */
    public function dispatchSafely(object $message): bool
    {
        try {
            $this->dispatch($message);

            return true;
        } catch (Throwable $e) {
            Logger::error('message dispatch failed', [
                'message' => $message::class,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function messageBus(): mixed
    {
        if ($this->messageBusResolved) {
            return $this->messageBus;
        }

        $this->messageBusResolved = true;

        if (!$this->preferMessenger
            || !class_exists(\Symfony\Component\Messenger\MessageBus::class)
            || !class_exists(\Symfony\Component\Messenger\Middleware\HandleMessageMiddleware::class)
        ) {
            return $this->messageBus = null;
        }

        // HandlersLocator expects class => list-of-callables.
        $locatorHandlers = [];

        foreach ($this->handlers as $messageClass => $handler) {
            $locatorHandlers[$messageClass] = [$handler];
        }

        return $this->messageBus = new \Symfony\Component\Messenger\MessageBus([
            new \Symfony\Component\Messenger\Middleware\HandleMessageMiddleware(
                new \Symfony\Component\Messenger\Handler\HandlersLocator($locatorHandlers)
            ),
        ]);
    }
}
