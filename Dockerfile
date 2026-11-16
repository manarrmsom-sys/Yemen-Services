FROM serversideup/php:8.2-cli

USER root

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_MEMORY_LIMIT=-1

# تثبيت Node.js 20 لبناء واجهة Vue + Vite PWA
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs sqlite3 libsqlite3-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# نسخ ملفات التطبيق من مجلد platform
COPY platform/ /app/

# حذف composer.lock القديم لتجنب أي تعارض وتثبيت الحزم مباشرة
RUN rm -f composer.lock \
    && composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --prefer-dist

# بناء واجهة Vue + Vite PWA
RUN npm ci --no-audit --no-fund || npm install --no-audit --no-fund
RUN npm run build

# تجهيز مجلدات التخزين وقاعدة بيانات SQLite
RUN mkdir -p /app/database /app/storage/framework/sessions /app/storage/framework/views /app/storage/framework/cache /app/storage/logs \
    && touch /app/database/database.sqlite \
    && chmod -R 775 /app/storage /app/bootstrap/cache /app/database

# سكربت التشغيل التلقائي عند بدء الحاوية على Render
RUN printf '#!/bin/sh\nset -e\nmkdir -p /app/database\ntouch /app/database/database.sqlite\nphp artisan package:discover --ansi || true\nphp artisan migrate --force\nphp artisan db:seed --force || true\nphp artisan config:cache\nphp artisan route:cache\nphp artisan view:cache\nexec php artisan serve --host=0.0.0.0 --port=${PORT:-10000}\n' > /start.sh \
    && chmod +x /start.sh

EXPOSE 10000
CMD ["/start.sh"]