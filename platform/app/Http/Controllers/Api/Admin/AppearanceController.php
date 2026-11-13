<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Appearance\AppearanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppearanceController extends Controller
{
    /** Site-relative path or an https URL. Blocks javascript: and protocol-relative links. */
    private const LINK = 'regex:/^(\/(?!\/)[^\s]*|https:\/\/[^\s]+)$/';

    private const HEX = 'regex:/^#[0-9A-Fa-f]{6}$/';

    public function __construct(private readonly AppearanceService $appearance) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'announcement' => ['required', 'array'],
            'announcement.enabled' => ['required', 'boolean'],
            'announcement.speed' => ['required', 'integer', 'between:10,120'],
            'announcement.background' => ['required', 'string', self::HEX],
            'announcement.color' => ['required', 'string', self::HEX],
            'announcement.items' => ['present', 'array', 'max:8'],
            'announcement.items.*.ar' => ['required', 'string', 'max:160'],
            'announcement.items.*.en' => ['nullable', 'string', 'max:160'],
            'announcement.items.*.href' => ['nullable', 'string', 'max:255', self::LINK],

            'banners' => ['present', 'array', 'max:6'],
            'banners.*.title_ar' => ['required', 'string', 'max:80'],
            'banners.*.title_en' => ['nullable', 'string', 'max:80'],
            'banners.*.body_ar' => ['nullable', 'string', 'max:200'],
            'banners.*.body_en' => ['nullable', 'string', 'max:200'],
            'banners.*.cta_ar' => ['nullable', 'string', 'max:40'],
            'banners.*.cta_en' => ['nullable', 'string', 'max:40'],
            'banners.*.href' => ['nullable', 'string', 'max:255', self::LINK],
            'banners.*.image_url' => ['nullable', 'string', 'max:500', 'regex:/^https:\/\/[^\s]+$/'],
            'banners.*.tone' => ['required', Rule::in(AppearanceService::TONES)],
            'banners.*.is_active' => ['required', 'boolean'],

            'featured_service_ids' => ['present', 'array', 'max:12'],
            'featured_service_ids.*' => ['integer', 'distinct', Rule::exists('services', 'id')],
        ]);

        // Vue renders these as text, but strip markup anyway so the values stay
        // clean wherever else they are reused (emails, exports).
        $clean = fn (?string $v) => $v === null ? null : trim(strip_tags($v));

        $data['announcement']['items'] = array_map(fn (array $item) => [
            'ar' => $clean($item['ar']),
            'en' => $clean($item['en'] ?? null),
            'href' => $item['href'] ?? null,
        ], $data['announcement']['items']);

        $data['banners'] = array_map(function (array $banner) use ($clean) {
            foreach (['title_ar', 'title_en', 'body_ar', 'body_en', 'cta_ar', 'cta_en'] as $field) {
                $banner[$field] = $clean($banner[$field] ?? null);
            }
            $banner['href'] ??= null;
            $banner['image_url'] ??= null;

            return $banner;
        }, $data['banners']);

        $this->appearance->update($data, $request->user());

        return response()->json(['data' => $this->payload()]);
    }

    private function payload(): array
    {
        return [
            'appearance' => $this->appearance->get(),
            'palette' => AppearanceService::PALETTE,
            'tones' => AppearanceService::TONES,
            'services' => Service::query()->where('is_active', true)->with('category:id,name_ar,name_en')
                ->orderBy('service_category_id')->orderBy('sort_order')
                ->get(['id', 'service_category_id', 'name_ar', 'name_en'])
                ->map(fn (Service $s) => [
                    'id' => $s->id,
                    'name_ar' => $s->name_ar,
                    'name_en' => $s->name_en,
                    'category_ar' => $s->category?->name_ar,
                    'category_en' => $s->category?->name_en,
                ]),
        ];
    }
}
