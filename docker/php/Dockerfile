# Circle — PHP 8.4 runtime for the Laravel API, queue workers and media pipeline.
FROM php:8.4-cli-bookworm

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
      pdo_pgsql pgsql redis gd exif zip bcmath pcntl intl opcache sockets

# Media + extraction toolchain used by the derived-artifact jobs:
#   ffmpeg        -> video/audio metadata, frame + audio extraction
#   poppler-utils -> pdftotext (per-page text), pdftoppm (page previews)
#   tesseract     -> image OCR
RUN apt-get update && apt-get install -y --no-install-recommends \
      ffmpeg \
      poppler-utils \
      tesseract-ocr tesseract-ocr-eng \
      git unzip ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

WORKDIR /app
