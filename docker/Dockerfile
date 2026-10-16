# Development image for the bot: PHP 8.3 CLI with the extensions this project
# actually needs (pdo_mysql for the database, bcmath/intl for the vendor SDKs).
#
#   docker build -f docker/Dockerfile -t social-bot:dev .
#   docker run --rm -v "$PWD:/app" -w /app social-bot:dev php worker.php --once
#
# docker-compose.yml uses the same file for the app container.

FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev libonig-dev default-mysql-client \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl zip mbstring \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

WORKDIR /app

CMD ["php", "-v"]
