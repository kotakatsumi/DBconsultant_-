FROM php:8.3-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*
# root ではなく一般ユーザーで動かす（単元1-2）
RUN useradd -m -u 1000 app
USER app
WORKDIR /app
