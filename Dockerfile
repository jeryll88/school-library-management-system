# ====================================================================
# Dockerfile for School Library Management System (LIBRARY_MS)
# Target Cloud Host: Render (Web Service - Docker Runtime)
# Base Image: PHP 8.2 with Apache Web Server
# ====================================================================

FROM php:8.2-apache

# Install system dependencies and PostgreSQL development libraries
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set Apache DocumentRoot
ENV APACHE_DOCUMENT_ROOT=/var/www/html

# Configure Apache DocumentRoot in virtual hosts
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Configure custom ServerName
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Copy project files into web root
COPY . /var/www/html/

# Set file permissions for Apache user
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Write an Apache startup script that reads $PORT from Render's environment
# Render injects PORT at runtime (default 10000); Apache must listen on it
RUN echo '#!/bin/bash\n\
PORT="${PORT:-10000}"\n\
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf\n\
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf\n\
exec apache2-foreground' > /start.sh && chmod +x /start.sh

# Expose default port (Render overrides at runtime via $PORT)
EXPOSE 10000

# Use the startup script so Apache binds to Render's $PORT
CMD ["/start.sh"]
