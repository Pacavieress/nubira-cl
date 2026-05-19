# Usa una imagen oficial de PHP con Apache
FROM php:8.2-apache

# Instala la extensión mysqli (para MySQL)
RUN docker-php-ext-install mysqli

# Activa el módulo de reescritura de Apache
RUN a2enmod rewrite

# Instala poppler-utils (para convertir PDF a imágenes)
RUN apt-get update && apt-get install -y poppler-utils

# Instala dependencias necesarias para GD y SOAP
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libxml2-dev

# Configura y compila GD con soporte para JPEG y Freetype
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install gd soap

# Configura el directorio de trabajo
WORKDIR /var/www/html

# (Opcional) Copiar archivo .htaccess
# COPY .htaccess /var/www/html/.htaccess

RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' /etc/apache2/sites-available/000-default.conf
