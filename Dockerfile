FROM php:8.2-apache

# Instala dependências e yt-dlp
RUN apt-get update && apt-get install -y python3 python3-pip ffmpeg wget \
    && pip install --upgrade yt-dlp

# Copia os arquivos do projeto
COPY . /var/www/html/

# Permissões
RUN chown -R www-data:www-data /var/www/html
