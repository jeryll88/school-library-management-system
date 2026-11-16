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

# Set Apache DocumentRoot and Port
ENV APACHE_DOCUMENT_ROOT=/var/www/html
ENV PORT=80

# Configure Apache DocumentRoot and Port in virtual hosts
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
RUN sed -ri -e 's!80!${PORT}!g' /etc/apache2/ports.conf /etc/apache2/sites-available/*.conf

# Configure custom ServerName
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Copy project files into web root
COPY . /var/www/html/

# Set file permissions for Apache user
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Expose port
EXPOSE 80

# Run Apache in foreground
CMD ["apache2-foreground"]

