FROM php:8.2-apache

# Install Docker CLI
RUN apt-get update && apt-get install -y \
    docker.io \
    curl \
    nano \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable mod_rewrite
RUN a2enmod rewrite

# Argument for the host's docker group GID
ARG DOCKER_GID=1002
# Create a group with that GID and add www-data to it
RUN groupadd -g $DOCKER_GID docker_host && \
    usermod -a -G docker_host www-data

WORKDIR /var/www/html
COPY . /var/www/html

# Ensure Apache can read the files
RUN chown -R www-data:www-data /var/www/html


EXPOSE 80
CMD ["apache2-foreground"]