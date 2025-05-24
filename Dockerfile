FROM php:8.1-apache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy all files
WORKDIR /var/www/html
COPY . .

# Create writable folders and files
RUN mkdir -p data && \
    touch users.json && \
    chmod -R 777 data users.json

# Expose port
EXPOSE 80
