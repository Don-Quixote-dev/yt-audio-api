FROM php:8.2-apache

# Só o necessário para baixar o binário do yt-dlp
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl \
 && update-ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# Baixa yt-dlp binário oficial
RUN curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
    -o /usr/local/bin/yt-dlp \
 && chmod a+rx /usr/local/bin/yt-dlp

# Copia sua app
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Porta (Render faz binding automático)
EXPOSE 10000
CMD ["apache2-foreground"]
