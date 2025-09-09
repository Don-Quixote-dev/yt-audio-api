FROM php:8.2-apache

# Instala apenas o essencial: python3 (para rodar yt-dlp), curl e ffmpeg
RUN apt-get update && apt-get install -y --no-install-recommends \
    python3 \
    curl \
    ffmpeg \
    ca-certificates \
 && update-ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# Baixa yt-dlp binário oficial
RUN curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
    -o /usr/local/bin/yt-dlp \
 && chmod a+rx /usr/local/bin/yt-dlp

# Copia app
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 10000
CMD ["apache2-foreground"]

