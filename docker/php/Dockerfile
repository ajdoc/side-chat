# Shared PHP runtime for the backend: the API webserver (FrankenPHP) plus the
# reverb and worker containers, which reuse this image (see docker/reverb, docker/worker).
FROM dunglas/frankenphp:php8.5-alpine

# Match the host developer so bind-mounted files stay writable (no root-owned files in dev).
ARG UID=1000
ARG GID=1000

# PHP extensions via FrankenPHP's bundled helper.
RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        redis \
        bcmath \
        pcntl \
        intl \
        zip \
        opcache

# Composer, pulled from the official image.
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Project config.
COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY Caddyfile /etc/frankenphp/Caddyfile

# Unprivileged user matching the host uid/gid.
RUN addgroup -g ${GID} app 2>/dev/null || true \
    && adduser -D -u ${UID} -G app app 2>/dev/null || true \
    && chown -R app:app /app /data /config

WORKDIR /app

USER app

# Non-privileged port so FrankenPHP can bind while running as a non-root user.
EXPOSE 8000

# Classic mode: Caddy serves public/index.php just like php-fpm did, but in one process.
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
