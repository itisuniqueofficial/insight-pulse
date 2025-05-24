FROM php:8.2-apache

# Enable mod_rewrite for Apache
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . .

# Make sure data dir is writable
RUN mkdir -p data && chmod -R 777 data users.json
