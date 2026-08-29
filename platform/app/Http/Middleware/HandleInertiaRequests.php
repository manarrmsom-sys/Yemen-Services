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
            'catalog' => fn () => cache()->remember("catalog:{$locale}", 300, fn () => ServiceCategory::active()
                ->with(['services' => fn ($q) => $q->active()])
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'slug' => $c->slug,
                    'name' => $locale === 'ar' ? $c->name_ar : $c->name_en,
                    'icon' => $c->icon,
                    'accent' => $c->accent_color,
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
