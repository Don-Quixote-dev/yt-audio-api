FROM php:8.2-apache

# Instala dependências básicas
RUN apt-get update && apt-get install -y \
    curl \
    ffmpeg \
    ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# Baixa o yt-dlp binário oficial
RUN curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
    -o /usr/local/bin/yt-dlp \
 && chmod a+rx /usr/local/bin/yt-dlp

# Copia os arquivos da aplicação
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 10000

CMD ["apache2-foreground"]
