<?php

namespace App\Http\Middleware;

use App\Models\ServiceCategory;
use App\Services\Appearance\AppearanceService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $locale = app()->getLocale();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'phone' => $request->user()->phone,
                    'role' => $request->user()->role->value,
                    'preferred_locale' => $request->user()->preferred_locale,
                    'is_staff' => $request->user()->isStaff(),
                ] : null,
            ],
            'locale' => $locale,
            'dir' => in_array($locale, config('app.rtl_locales', ['ar']), true) ? 'rtl' : 'ltr',
            'csrf_token' => csrf_token(),
            'supported_locales' => config('app.supported_locales'),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'otp' => fn () => $request->session()->get('otp'),
            ],
            'app' => [
                'name' => config('app.name'),
                'currency' => config('platform.currency'),
                'commission_percent' => config('platform.pricing.commission_percent'),
                // Digits only, e.g. 967774274848 (used for wa.me and tel: links).
                'support_whatsapp' => preg_replace('/\D/', '', (string) config('platform.whatsapp.business_number')),
                'otp' => [
                    'default' => config('platform.otp.default_channel'),
                    'channels' => array_keys(array_filter(config('platform.otp.channels', []))),
                ],
            ],
            // Admin-managed announcement bar shown by the public layout.
            'announcement' => fn () => app(AppearanceService::class)->announcement($locale),
            // Lightweight, cached catalog for the booking drawer — avoids an extra request.
            'catalog' => fn () => cache()->remember("catalog:{$locale}:v4", 300, fn () => ServiceCategory::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->limit(20)
                ->with(['services' => fn ($q) => $q->select([
                    'id', 'service_category_id', 'slug', 'name_ar', 'name_en',
                    'pricing_model', 'base_price', 'hourly_rate', 'unit_price',
                    'unit_label_ar', 'unit_label_en', 'callout_fee',
                ])->where('is_active', true)->orderBy('sort_order')->limit(10)])
                ->get([
                    'id', 'slug', 'name_ar', 'name_en', 'icon', 'accent_color', 'image_url', 'is_remote',
                ])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'slug' => $c->slug,
                    'name' => $locale === 'ar' ? $c->name_ar : $c->name_en,
                    'icon' => $c->icon,
                    'accent' => $c->accent_color,
                    'image_url' => $c->image_url,
                    'is_remote' => (bool) $c->is_remote,
                    'services' => $c->services->map(fn ($s) => [
                        'id' => $s->id,
                        'slug' => $s->slug,
                        'name' => $locale === 'ar' ? $s->name_ar : $s->name_en,
                        'pricing_model' => $s->pricing_model->value,
                        'base_price' => $s->base_price,
                        'hourly_rate' => $s->hourly_rate,
                        'unit_price' => $s->unit_price,
                        'unit_label' => $locale === 'ar' ? $s->unit_label_ar : $s->unit_label_en,
                        'callout_fee' => $s->callout_fee,
                    ]),
                ])),
        ]);
    }
}
