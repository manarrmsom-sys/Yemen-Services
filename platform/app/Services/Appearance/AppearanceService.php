<?php

namespace App\Services\Appearance;

use App\Models\Governorate;
use App\Models\Order;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\TradespersonProfile;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-editable storefront appearance: announcement bar, home banners and the
 * order of featured services. Stored as one JSON document in site_settings.
 */
class AppearanceService
{
    public const KEY = 'appearance';

    public const CACHE_KEY = 'appearance:v1';

    public const TONES = ['crimson', 'ink', 'ivory'];

    /** Brand colours offered as presets for the announcement bar. */
    public const PALETTE = ['#111315', '#1E2022', '#CE1126', '#A80D1E', '#FFFFFF', '#F8F9FA', '#D4AF37'];

    public function defaults(): array
    {
        return [
            'announcement' => [
                'enabled' => true,
                'speed' => 32,
                'background' => '#111315',
                'color' => '#FFFFFF',
                'items' => [
                    ['ar' => 'سعر واضح قبل تأكيد الحجز — بدون رسوم مخفية', 'en' => 'Clear price before you confirm — no hidden fees', 'href' => null],
                    ['ar' => 'الدفع نقداً عند اكتمال الخدمة', 'en' => 'Pay in cash when the job is done', 'href' => null],
                    ['ar' => 'فنيون موثّقون بالهوية والخبرة', 'en' => 'ID- and experience-verified tradespeople', 'href' => '/pros'],
                ],
            ],
            'banners' => [
                [
                    'title_ar' => 'اعرف التكلفة قبل أن تحجز', 'title_en' => 'Know the cost before you book',
                    'body_ar' => 'حاسبة السعر تعرض لك التفاصيل كاملة قبل تسجيل الدخول.', 'body_en' => 'The estimator shows the full breakdown before you sign in.',
                    'cta_ar' => 'احسب التكلفة', 'cta_en' => 'Get an estimate',
                    'href' => '/book', 'image_url' => null, 'tone' => 'ink', 'is_active' => true,
                ],
                [
                    'title_ar' => 'أنت فني؟ انضم إلى الشبكة', 'title_en' => 'A tradesperson? Join the network',
                    'body_ar' => 'وثّق حسابك واستقبل الطلبات القريبة منك.', 'body_en' => 'Verify your account and receive nearby jobs.',
                    'cta_ar' => 'انضم الآن', 'cta_en' => 'Join now',
                    'href' => '/join', 'image_url' => null, 'tone' => 'crimson', 'is_active' => true,
                ],
            ],
            'featured_service_ids' => [],
        ];
    }

    /** Full document as stored (defaults filled in). Used by the admin editor. */
    public function get(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $stored = SiteSetting::find(self::KEY)?->value ?? [];
            $defaults = $this->defaults();

            return [
                'announcement' => array_merge($defaults['announcement'], $stored['announcement'] ?? []),
                'banners' => array_values($stored['banners'] ?? $defaults['banners']),
                'featured_service_ids' => array_values($stored['featured_service_ids'] ?? []),
            ];
        });
    }

    public function update(array $data, ?User $actor = null): array
    {
        $current = $this->get();

        $value = [
            'announcement' => array_merge($current['announcement'], $data['announcement'] ?? []),
            'banners' => array_values($data['banners'] ?? $current['banners']),
            'featured_service_ids' => array_values(array_map('intval', $data['featured_service_ids'] ?? $current['featured_service_ids'])),
        ];
        $value['announcement']['items'] = array_values($value['announcement']['items'] ?? []);

        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $value, 'updated_by' => $actor?->id]);
        $this->flush();

        return $this->get();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        foreach (config('app.supported_locales', ['ar', 'en']) as $locale) {
            Cache::forget("home:featured-services:{$locale}");
        }
    }

    /** Announcement bar for the public layout, in one locale. Null when hidden. */
    public function announcement(string $locale): ?array
    {
        $bar = $this->get()['announcement'];

        $items = collect($bar['items'] ?? [])
            ->map(fn (array $item) => [
                'text' => trim((string) ($this->localized($item, $locale))),
                'href' => $item['href'] ?? null,
            ])
            ->filter(fn (array $item) => $item['text'] !== '')
            ->values()->all();

        if (! ($bar['enabled'] ?? false) || $items === []) {
            return null;
        }

        return [
            'items' => $items,
            'speed' => (int) $bar['speed'],
            'background' => $bar['background'],
            'color' => $bar['color'],
        ];
    }

    /** Active promo banners for the home page, in one locale. */
    public function banners(string $locale): array
    {
        return collect($this->get()['banners'])
            ->filter(fn (array $b) => $b['is_active'] ?? true)
            ->map(fn (array $b) => [
                'title' => $this->localized($b, $locale, 'title_'),
                'body' => $this->localized($b, $locale, 'body_'),
                'cta' => $this->localized($b, $locale, 'cta_'),
                'href' => $b['href'] ?? null,
                'image_url' => $b['image_url'] ?? null,
                'tone' => in_array($b['tone'] ?? null, self::TONES, true) ? $b['tone'] : 'ink',
            ])
            ->filter(fn (array $b) => filled($b['title']))
            ->values()->all();
    }

    /**
     * Services shown on the home page. Admin-picked order when set, otherwise
     * the services with the most orders (then catalogue order).
     */
    public function featuredServices(string $locale, int $limit = 6): array
    {
        return Cache::remember("home:featured-services:{$locale}", 300, function () use ($locale, $limit): array {
            $ids = $this->get()['featured_service_ids'];

            $query = Service::query()->where('is_active', true)
                ->whereHas('category', fn ($q) => $q->where('is_active', true))
                ->with('category:id,slug,name_ar,name_en,icon,image_url,requires_kyc,is_remote');

            if ($ids !== []) {
                $services = $query->whereIn('id', $ids)->get()
                    ->sortBy(fn (Service $s) => array_search($s->id, $ids, true))->values();
            } else {
                $orders = Order::query()->selectRaw('service_id, COUNT(*) as n')->groupBy('service_id')->pluck('n', 'service_id');
                $services = $query->orderBy('sort_order')->orderBy('id')->get()
                    ->sortByDesc(fn (Service $s) => $orders[$s->id] ?? 0)->take($limit)->values();
            }

            return $services->map(fn (Service $s) => [
                'id' => $s->id,
                'slug' => $s->slug,
                'name' => $locale === 'ar' ? $s->name_ar : $s->name_en,
                'description' => $locale === 'ar' ? $s->description_ar : $s->description_en,
                'pricing_model' => $s->pricing_model->value,
                'from_price' => match ($s->pricing_model->value) {
                    'hourly' => $s->hourly_rate,
                    'per_unit' => $s->unit_price,
                    'quote' => null,
                    default => $s->base_price,
                },
                'callout_fee' => $s->callout_fee,
                'unit_label' => $locale === 'ar' ? $s->unit_label_ar : $s->unit_label_en,
                'category' => [
                    'id' => $s->category->id,
                    'slug' => $s->category->slug,
                    'name' => $locale === 'ar' ? $s->category->name_ar : $s->category->name_en,
                    'icon' => $s->category->icon,
                    'image_url' => $s->category->image_url,
                    'requires_kyc' => (bool) $s->category->requires_kyc,
                    'is_remote' => (bool) $s->category->is_remote,
                ],
            ])->all();
        });
    }

    /** Real platform numbers for the hero strip. No invented figures. */
    public function stats(): array
    {
        return Cache::remember('home:stats', 300, fn (): array => [
            'verified_pros' => TradespersonProfile::where('kyc_status', 'verified')->count(),
            'services' => Service::where('is_active', true)->count(),
            'governorates' => Governorate::where('is_active', true)->count(),
            'completed_orders' => Order::whereIn('status', ['completed', 'closed'])->count(),
        ]);
    }

    /** Pick the locale's text, falling back to Arabic (the required language). */
    private function localized(array $row, string $locale, string $prefix = ''): ?string
    {
        $value = $row[$prefix.$locale] ?? null;

        return filled($value) ? $value : ($row[$prefix.'ar'] ?? null);
    }
}
