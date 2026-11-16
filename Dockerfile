FROM php:8.2-cli

# تثبيت أدوات النظام وإضافات PHP كاملة و Node.js
RUN apt-get update && apt-get install -y \
    git curl unzip zip libzip-dev libcurl4-openssl-dev libicu-dev \
    libpng-dev libonig-dev libxml2-dev libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite mbstring xml bcmath gd zip curl intl \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# تثبيت Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# نسخ ملفات التطبيق من مجلد platform
COPY platform/ /app/

# تثبيت اعتماديات PHP وبناء واجهة Vue + Vite PWA
RUN composer update --no-dev --optimize-autoloader --no-interaction \
    && npm install \
    && npm run build

# تجهيز مجلدات التخزين وقاعدة بيانات SQLite
RUN mkdir -p /app/database /app/storage/framework/sessions /app/storage/framework/views /app/storage/framework/cache /app/storage/logs \
    && touch /app/database/database.sqlite \
    && chmod -R 775 /app/storage /app/bootstrap/cache /app/database

# سكربت التشغيل التلقائي عند بدء الحاوية على Render
RUN printf '#!/bin/sh\nset -e\nmkdir -p /app/database\ntouch /app/database/database.sqlite\nphp artisan migrate --force\nphp artisan db:seed --force || true\nphp artisan config:cache\nphp artisan route:cache\nphp artisan view:cache\nexec php artisan serve --host=0.0.0.0 --port=${PORT:-10000}\n' > /start.sh \
    && chmod +x /start.sh

EXPOSE 10000
CMD ["/start.sh"]