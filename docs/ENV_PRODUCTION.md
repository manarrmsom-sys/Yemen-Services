# متغيرات `.env` عند الرفع على السيرفر

انسخي `platform/.env.example` إلى `.env` على السيرفر ثم عبّئي ما يلي.
كل ما لم يُذكر هنا له قيمة افتراضية صالحة في `.env.example`.
للخطوات الكاملة: [DEPLOYMENT.md](DEPLOYMENT.md) و [PRODUCTION_CHECKLIST.md](PRODUCTION_CHECKLIST.md).

## 1. أساسيات (إلزامية)

| المتغير | القيمة |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | يُولَّد مرة واحدة: `php artisan key:generate` |
| `APP_URL` | رابط الموقع الحقيقي مع `https://` |
| `DB_DATABASE` `DB_USERNAME` `DB_PASSWORD` | بيانات MySQL |
| `REDIS_PASSWORD` | كلمة مرور Redis |
| `SESSION_SECURE_COOKIE` | `true` (القيمة في المثال `false` للتطوير المحلي) |
| `SESSION_DOMAIN` | `.نطاقك` مثل `.example.com` |
| `SANCTUM_STATEFUL_DOMAINS` | نطاق الموقع بدون `https://` |

## 2. الإيميل (رموز الدخول)

الدخول الافتراضي برمز يُرسل بالإيميل، فبدون هذه القيم **لا يستطيع أحد تسجيل الدخول، ومنهم المدير**.

| المتغير | القيمة |
|---|---|
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` `MAIL_PORT` | من مزوّد البريد (غالباً المنفذ `587`) |
| `MAIL_USERNAME` `MAIL_PASSWORD` | بيانات حساب الإرسال |
| `MAIL_ENCRYPTION` | `tls` |
| `MAIL_FROM_ADDRESS` | عنوان المرسل، من نطاق موثّق لدى المزوّد |
| `SEED_ADMIN_EMAIL` | **بريد حقيقي تستقبلين عليه** — هو حساب المدير الأول |

## 3. واتساب (Meta Cloud API)

| المتغير | القيمة | يلزم لـ |
|---|---|---|
| `WHATSAPP_BUSINESS_NUMBER` | `967774274848` (مضبوط) | روابط `wa.me` والدعم |
| `WHATSAPP_PHONE_NUMBER_ID` | من لوحة Meta | إرسال الرسائل |
| `WHATSAPP_ACCESS_TOKEN` | توكن دائم من Meta | إرسال الرسائل |
| `WHATSAPP_APP_SECRET` | App Secret من Meta | التحقق من ردود الفنيين الواردة |
| `WHATSAPP_WEBHOOK_VERIFY_TOKEN` | نص سري تختارينه وتضعينه في Meta | ربط الويبهوك |
| `WHATSAPP_TEMPLATE_OTP` | اسم قالب معتمد، الافتراضي `otp_login` | رمز الدخول بواتساب |
| `OTP_CHANNEL_WHATSAPP_ENABLED` | `true` بعد اكتمال ما سبق | إظهار خيار واتساب في الدخول |

رابط الويبهوك الذي يُسجَّل في Meta: `https://نطاقك/webhooks/whatsapp`

بدون `WHATSAPP_APP_SECRET` تُرفض كل الرسائل الواردة، فلا يُقبل رد الفني «نعم» تلقائياً.

## 4. n8n

| المتغير | القيمة | يلزم لـ |
|---|---|---|
| `N8N_WEBHOOK_SECRET` | نص سري طويل، نفسه في n8n | توقيع كل ما يُرسل ويُستقبل |
| `N8N_WEBHOOK_DISPATCH_URL` | رابط ويبهوك توزيع الطلبات في n8n | إرسال الطلب للفنيين |
| `N8N_WEBHOOK_B2B_URL` | رابط ويبهوك طلبات الشركات في n8n | إشعار فوري بكل طلب B2B |

n8n يرسل إلينا على: `https://نطاقك/webhooks/n8n/{topic}` مع ترويسة `X-Signature`
(HMAC-SHA256 لجسم الطلب بالمفتاح `N8N_WEBHOOK_SECRET`).

بدون `N8N_WEBHOOK_SECRET` تُرفض كل طلبات n8n الواردة، ومنها تأكيد شحن المحافظ.

## 5. اختيارية

| المتغير | الافتراضي | الغرض |
|---|---|---|
| `SMS_ENDPOINT` `SMS_API_KEY` + `OTP_CHANNEL_SMS_ENABLED=true` | معطّل | رمز الدخول برسالة نصية |
| `PLATFORM_COMMISSION_PERCENT` | `12` | نسبة عمولة المنصة |
| `WALLET_MIN_FLOAT_YER` | `5000` | الحد الأدنى لرصيد الفني ليُعدّ جاهزاً |
| `DISPATCH_SLA_MINUTES` | `10` | مهلة قبول الطلب قبل التنبيه الأحمر |
| `SEED_ADMIN_PASSWORD` | فارغ | اتركيه فارغاً: تُولَّد كلمة قوية وتُطبع مرة واحدة عند البذر |

## 6. بعد تعبئة الملف

```bash
php artisan migrate --seed --force
php artisan config:cache && php artisan route:cache
npm ci && npm run build
```

ثم من لوحة الإدارة: «المدن والأحياء» لتأكيد الأحياء المفعّلة، و«الخدمات» لمراجعة الأسعار.

## لا تُرفع إلى Git أبداً

ملف `.env` وكل ما فيه من مفاتيح. الملف مُستثنى في `.gitignore`.
