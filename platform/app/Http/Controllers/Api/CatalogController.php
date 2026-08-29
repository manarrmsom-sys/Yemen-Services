<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Governorate;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function index(): JsonResponse
    {
        $locale = app()->getLocale();

        $data = cache()->remember("api:catalog:{$locale}", 300, fn () => ServiceCategory::active()
            ->with(['services' => fn ($q) => $q->active()])
            ->get()
            ->map(fn ($c) => $this->transformCategory($c, $locale)));

        return response()->json(['data' => $data]);
    }

    public function category(ServiceCategory $category): JsonResponse
    {
        abort_unless($category->is_active, 404);
        $category->load(['services' => fn ($q) => $q->active()]);

        return response()->json(['data' => $this->transformCategory($category, app()->getLocale())]);
    }

    public function service(Service $service): JsonResponse
    {
        abort_unless($service->is_active, 404);
        $locale = app()->getLocale();

        return response()->json(['data' => [
            'id' => $service->id,
            'slug' => $service->slug,
            'name' => $locale === 'ar' ? $service->name_ar : $service->name_en,
            'description' => $locale === 'ar' ? $service->description_ar : $service->description_en,
            'pricing_model' => $service->pricing_model->value,
            'base_price' => $service->base_price,
            'hourly_rate' => $service->hourly_rate,
            'unit_price' => $service->unit_price,
            'unit_label' => $locale === 'ar' ? $service->unit_label_ar : $service->unit_label_en,
            'callout_fee' => $service->callout_fee,
            'min_duration_minutes' => $service->min_duration_minutes,
            'currency' => config('platform.currency'),
        ]]);
    }

    public function governorates(): JsonResponse
    {
        $locale = app()->getLocale();

        $data = cache()->remember("api:governorates:{$locale}", 3600, fn () => Governorate::where('is_active', true)
            ->orderBy('name_'.$locale)
            ->get()
            ->map(fn ($g) => [
                'id' => $g->id,
                'code' => $g->code,
                'name' => $g->localizedName($locale),
                'dispatch_multiplier' => $g->dispatch_multiplier,
            ]));

        return response()->json(['data' => $data]);
    }

    private function transformCategory(ServiceCategory $c, string $locale): array
    {
        return [
            'id' => $c->id,
            'slug' => $c->slug,
            'name' => $locale === 'ar' ? $c->name_ar : $c->name_en,
            'description' => $locale === 'ar' ? $c->description_ar : $c->description_en,
            'icon' => $c->icon,
            'accent' => $c->accent_color,
            'requires_kyc' => $c->requires_kyc,
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
        ];
    }
}
