FROM php:8.4-cli-alpine

# Install system dependencies and PHP extensions
RUN apk add --no-cache \
    openssh-client \
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

# Install dependencies (ignoring platform reqs during build to avoid issues with host/builder PHP differences)
RUN composer install --no-interaction --optimize-autoloader --ignore-platform-reqs

# Make entrypoint executable
RUN chmod +x docker-entrypoint.sh

# Expose Dashboard API port
EXPOSE 8000

# Set entrypoint
ENTRYPOINT ["/app/docker-entrypoint.sh"]
