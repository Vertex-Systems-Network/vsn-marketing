FROM php:8.5-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        git \
        libonig-dev \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install bcmath mbstring pcntl pdo_pgsql posix zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /workspace
EXPOSE 8000
