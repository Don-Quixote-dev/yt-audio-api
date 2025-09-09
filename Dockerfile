FROM php:8.2-apache

# Instala Python, pip, ffmpeg e wget
RUN apt-get update && apt-get install -y \
    python3 \
    python3-pip \
    ffmpeg \
    wget \
 && rm -rf /var/lib/apt/lists/*

# Instala yt-dlp de forma segura
RUN python3 -m pip install --no-cache-dir --upgrade yt-dlp

# Copia os arquivos para o Apache
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Expõe a porta padrão do Render
EXPOSE 10000

# Inicia Apache
CMD ["apache2-foreground"]
