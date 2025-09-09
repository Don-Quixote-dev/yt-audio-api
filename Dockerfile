FROM php:8.2-apache

# Dependências mínimas
RUN apt-get update && apt-get install -y --no-install-recommends \
    python3 \
    curl \
    ca-certificates \
 && update-ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# Baixa o yt-dlp (NIGHTLY) do repositório correto
# ATENÇÃO: é yt-dlp-nightly-builds, não o repo principal
RUN curl -fsSL https://github.com/yt-dlp/yt-dlp-nightly-builds/releases/latest/download/yt-dlp \
    -o /usr/local/bin/yt-dlp \
 && chmod a+rx /usr/local/bin/yt-dlp \
 # Fail-fast se o download não for válido
 && head -c 4 /usr/local/bin/yt-dlp | od -An -t x1 \
 && /usr/local/bin/yt-dlp --version

# Copia a app
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 10000
CMD ["apache2-foreground"]
