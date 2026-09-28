FROM php:8.5-cli-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    PATH=/workspace/vendor/bin:${PATH}

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        git \
        libonig-dev \
        libpq-dev \
        libzip-dev \
        python3 \
        unzip \
    && docker-php-ext-install -j"$(nproc)" bcmath mbstring pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /workspace

# The benchmark image is intentionally source-containing rather than bind-mounted.
# Railway-hosted source identity is attested through immutable deployment metadata;
# local/non-Railway execution continues to require real checkout Git metadata.
COPY . /workspace

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --classmap-authoritative \
    && python3 --version \
    && chmod 0755 /workspace/docker/benchmark/entrypoint.sh

ENTRYPOINT ["/workspace/docker/benchmark/entrypoint.sh"]

# Deployment only keeps the isolated runner alive. Benchmark capture is always
# an explicit operator action after source/database/Redis preflight succeeds.
CMD ["sleep", "infinity"]
