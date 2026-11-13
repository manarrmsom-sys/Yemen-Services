<?php

namespace App\Http\Middleware;

use App\Models\ServiceCategory;
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
            ],
            // Lightweight, cached catalog for the booking drawer — avoids an extra request.
            'catalog' => fn () => cache()->remember("catalog:{$locale}:v3", 300, fn () => ServiceCategory::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->limit(10)
                ->with(['services' => fn ($q) => $q->select([
                    'id', 'service_category_id', 'slug', 'name_ar', 'name_en',
                    'pricing_model', 'base_price', 'hourly_rate', 'unit_price',
                    'unit_label_ar', 'unit_label_en', 'callout_fee',
                ])->where('is_active', true)->orderBy('sort_order')->limit(10)])
                ->get([
                    'id', 'slug', 'name_ar', 'name_en', 'icon', 'accent_color',
                ])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'slug' => $c->slug,
                    'name' => $locale === 'ar' ? $c->name_ar : $c->name_en,
                    'icon' => $c->icon,
                    'accent' => $c->accent_color,
                    'image_url' => $c->image_url,
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
