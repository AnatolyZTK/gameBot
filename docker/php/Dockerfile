FROM php:8.4-fpm-bookworm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libpng-dev \
    libonig-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    intl \
    zip \
    opcache \
    dom \
    xml \
    mbstring \
    curl

RUN pecl install redis \
    && docker-php-ext-enable redis

# Chrome + ChromeDriver из одного релиза Chrome for Testing (с 115+ старый chromedriver.storage не работает)
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    unzip \
    fonts-liberation \
    libasound2 \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libcairo2 \
    libcups2 \
    libdbus-1-3 \
    libdrm2 \
    libgbm1 \
    libglib2.0-0 \
    libgtk-3-0 \
    libnspr4 \
    libnss3 \
    libpango-1.0-0 \
    libx11-6 \
    libxcb1 \
    libxcomposite1 \
    libxdamage1 \
    libxext6 \
    libxfixes3 \
    libxkbcommon0 \
    libxrandr2 \
    xdg-utils \
    && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    CHROME_VERSION="$(curl -fsSL https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json \
        | php -r 'echo json_decode(stream_get_contents(STDIN), true)["channels"]["Stable"]["version"];')"; \
    echo "Chrome for Testing version: ${CHROME_VERSION}"; \
    curl -fsSL "https://storage.googleapis.com/chrome-for-testing-public/${CHROME_VERSION}/linux64/chrome-linux64.zip" -o /tmp/chrome.zip; \
    curl -fsSL "https://storage.googleapis.com/chrome-for-testing-public/${CHROME_VERSION}/linux64/chromedriver-linux64.zip" -o /tmp/chromedriver.zip; \
    unzip -q /tmp/chrome.zip -d /opt; \
    unzip -q /tmp/chromedriver.zip -d /opt; \
    ln -sf /opt/chrome-linux64/chrome /usr/local/bin/google-chrome; \
    ln -sf /opt/chrome-linux64/chrome /usr/local/bin/chromium; \
    ln -sf /opt/chrome-linux64/chrome /usr/local/bin/chromium-browser; \
    ln -sf /opt/chromedriver-linux64/chromedriver /usr/local/bin/chromedriver; \
    chmod +x /usr/local/bin/google-chrome /usr/local/bin/chromedriver; \
    rm -f /tmp/chrome.zip /tmp/chromedriver.zip; \
    google-chrome --version; \
    chromedriver --version; \
    CHROME_MAJOR="$(google-chrome --version | grep -oE '[0-9]+' | head -1)"; \
    DRIVER_MAJOR="$(chromedriver --version | grep -oE '[0-9]+' | head -1)"; \
    test "${CHROME_MAJOR}" = "${DRIVER_MAJOR}"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Composer вызывает git в смонтированном томе (uid хоста ≠ uid контейнера)
RUN git config --global --add safe.directory /var/www/html \
    && git config --global --add safe.directory '*'

WORKDIR /var/www/html

COPY docker/php/docker-entrypoint.sh /usr/local/bin/docker-php-custom-entrypoint
RUN chmod +x /usr/local/bin/docker-php-custom-entrypoint

ENTRYPOINT ["docker-php-custom-entrypoint"]
CMD ["php-fpm"]

FROM base AS dev

RUN apt-get update && apt-get install -y --no-install-recommends \
    xvfb \
    xauth \
    $PHPIZE_DEPS \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/conf.d/xdebug.ini /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

FROM base AS prod

COPY docker/php/conf.d/opcache.prod.ini /usr/local/etc/php/conf.d/99-opcache.ini

COPY composer.json symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

USER www-data
