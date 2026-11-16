FROM php:8.2-cli

# تثبيت Node.js 20 وإضافة SQLite
RUN apt-get update && apt-get install -y \
    curl unzip libpng-dev libonig-dev libxml2-dev libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite mbstring xml bcmath gd \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# نسخ كامل التطبيق شاملاً مجلد vendor الجاهز
COPY platform/ /app/

# بناء واجهة Vue + Vite PWA فقط
RUN npm ci --no-audit --no-fund || npm install --no-audit --no-fund
RUN npm run build

# تجهيز مجلدات التخزين وقاعدة بيانات SQLite
RUN mkdir -p /app/database /app/storage/framework/sessions /app/storage/framework/views /app/storage/framework/cache /app/storage/logs \
    && touch /app/database/database.sqlite \
    && chmod -R 775 /app/storage /app/bootstrap/cache /app/database

# سكربت التشغيل التلقائي عند بدء الحاوية على Render
RUN printf '#!/bin/sh\nset -e\nmkdir -p /app/database\ntouch /app/database/database.sqlite\nphp artisan migrate --force\nphp artisan db:seed --force || true\nphp artisan config:cache\nphp artisan route:cache\nphp artisan view:cache\nexec php artisan serve --host=0.0.0.0 --port=${PORT:-10000}\n' > /start.sh \
    && chmod +x /start.sh

EXPOSE 10000
CMD ["/start.sh"]