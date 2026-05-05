# Use PHP with Apache
FROM php:8.2-apache

# Install Docker CLI (to control host Docker)
RUN apt-get update && apt-get install -y \
    docker.io \
    curl \
    nano \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite (optional but nice)
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy your webhook script (optional if you mount volume)
COPY . /var/www/html

# Fix permissions
RUN chown -R www-data:www-data /var/www/html



# Expose port
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]