FROM php:8.4-cli-alpine

# Install system dependencies and PHP extensions
RUN apk add --no-cache \
    openssh-client \
    busybox-suid \
    git \
    unzip \
    sqlite-dev \
    libzip-dev \
    && docker-php-ext-install pdo_sqlite zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy application files
COPY . .

# Install production dependencies only, then compile the DI container.
#
# Platform requirements are deliberately NOT ignored: the lock file targets
# PHP >= 8.4 and a mismatch should fail the build, not the first cron run.
# The warm cache itself is shadowed by the ./var bind mount at runtime, but
# compiling here still turns a broken service definition into a build error.
RUN APP_ENV=prod APP_SECRET=build composer install \
        --no-interaction \
        --no-dev \
        --optimize-autoloader \
    && APP_ENV=prod APP_SECRET=build php bin/console cache:warmup --no-debug

# Make entrypoint executable
RUN chmod +x docker-entrypoint.sh

# Expose Dashboard API port
EXPOSE 8000

# Set entrypoint
ENTRYPOINT ["/app/docker-entrypoint.sh"]
