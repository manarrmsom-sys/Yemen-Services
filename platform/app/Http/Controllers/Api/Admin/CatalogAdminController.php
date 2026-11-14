<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\TradespersonProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin catalogue management: categories, services and their estimate prices.
 * Reads are open to staff; every write is admin-only (see routes/api.php).
 */
class CatalogAdminController extends Controller
{
    private const MAX_PRICE = 100_000_000; // YER

    private const PRICING_MODELS = ['fixed', 'hourly', 'per_unit', 'quote'];

    /** Every category and service, active or not, in both languages. */
    public function index(): JsonResponse
    {
        $orders = Order::selectRaw('service_id, COUNT(*) as n')->groupBy('service_id')->pluck('n', 'service_id');

        $categories = ServiceCategory::query()->orderBy('sort_order')->orderBy('id')
            ->with(['services' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->get()
            ->map(fn (ServiceCategory $c) => $this->category($c) + [
                'services' => $c->services->map(fn (Service $s) => $this->service($s) + ['orders_count' => (int) ($orders[$s->id] ?? 0)])->values(),
            ]);

        return response()->json(['data' => $categories, 'pricing_models' => self::PRICING_MODELS]);
    }

    // ---- categories ---------------------------------------------------------

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $this->validateCategory($request);
        $data['slug'] = $this->slug($data['slug'] ?? null, $data['name_en'], ServiceCategory::class);
        $data['sort_order'] ??= (int) ServiceCategory::max('sort_order') + 1;

        $category = ServiceCategory::create($data);
        $this->flush();

        // fresh(): include column defaults (is_active, requires_kyc...) in the reply.
        return response()->json(['data' => $this->category($category->fresh()) + ['services' => []]], 201);
    }

    public function updateCategory(Request $request, ServiceCategory $category): JsonResponse
    {
        $data = $this->validateCategory($request, $category);
        if (array_key_exists('slug', $data)) {
            $data['slug'] = $this->slug($data['slug'], $data['name_en'] ?? $category->name_en, ServiceCategory::class, $category->id);
        }

        $category->update($data);
        $this->flush();

        return response()->json(['data' => $this->category($category->fresh())]);
    }

    public function destroyCategory(ServiceCategory $category): JsonResponse
    {
        // Orders and tradespeople point at the category; removing it would break
        // their history. Switching it off hides it from customers instead.
        abort_if(
            Order::where('service_category_id', $category->id)->exists()
                || TradespersonProfile::where('primary_category_id', $category->id)->exists(),
            409,
            __('errors.catalog_in_use'),
        );

        $category->delete();
        $this->flush();

        return response()->json(['data' => ['ok' => true]]);
    }

    // ---- services -----------------------------------------------------------

    public function storeService(Request $request): JsonResponse
    {
        $data = $this->validateService($request);
        $data['slug'] = $this->slug($data['slug'] ?? null, $data['name_en'], Service::class);
        $data['sort_order'] ??= (int) Service::where('service_category_id', $data['service_category_id'])->max('sort_order') + 1;

        $service = Service::create($data);
        $this->flush();

        return response()->json(['data' => $this->service($service->fresh()) + ['orders_count' => 0]], 201);
    }

    public function updateService(Request $request, Service $service): JsonResponse
    {
        $data = $this->validateService($request, $service);
        if (array_key_exists('slug', $data)) {
            $data['slug'] = $this->slug($data['slug'], $data['name_en'] ?? $service->name_en, Service::class, $service->id);
        }

        $service->update($data);
        $this->flush();

        return response()->json(['data' => $this->service($service->fresh())]);
    }

    public function destroyService(Service $service): JsonResponse
    {
        abort_if(Order::where('service_id', $service->id)->exists(), 409, __('errors.catalog_in_use'));

        $service->delete();
        $this->flush();

        return response()->json(['data' => ['ok' => true]]);
    }

    // ---- validation ---------------------------------------------------------

    private function validateCategory(Request $request, ?ServiceCategory $category = null): array
    {
        $required = $category ? 'sometimes' : 'required';

        $data = $request->validate([
            'name_ar' => [$required, 'string', 'max:80'],
            'name_en' => [$required, 'string', 'max:80'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:16'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:1024', 'regex:/^https:\/\/[^\s]+$/'],
            'is_remote' => ['sometimes', 'boolean'],
            'requires_kyc' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,9999'],
        ]);

        return $this->clean($data, ['name_ar', 'name_en', 'description_ar', 'description_en', 'icon']);
    }

    private function validateService(Request $request, ?Service $service = null): array
    {
        $required = $service ? 'sometimes' : 'required';
        $price = ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_PRICE];

        $data = $request->validate([
            'service_category_id' => [$required, 'integer', Rule::exists('service_categories', 'id')],
            'name_ar' => [$required, 'string', 'max:120'],
            'name_en' => [$required, 'string', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'pricing_model' => [$required, Rule::in(self::PRICING_MODELS)],
            'base_price' => $price,
            'hourly_rate' => $price,
            'unit_price' => $price,
            'callout_fee' => $price,
            'unit_label_ar' => ['sometimes', 'nullable', 'string', 'max:40'],
            'unit_label_en' => ['sometimes', 'nullable', 'string', 'max:40'],
            'min_duration_minutes' => ['sometimes', 'integer', 'between:15,1440'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,9999'],
        ]);

        // The price that matches the pricing model must be set, otherwise the
        // estimator would quote the customer zero.
        $model = $data['pricing_model'] ?? $service?->pricing_model->value;
        $field = ['fixed' => 'base_price', 'hourly' => 'hourly_rate', 'per_unit' => 'unit_price'][$model] ?? null;
        if ($field && (int) ($data[$field] ?? $service?->{$field} ?? 0) <= 0) {
            throw ValidationException::withMessages([$field => [__('errors.catalog_price_required')]]);
        }

        return $this->clean($data, ['name_ar', 'name_en', 'description_ar', 'description_en', 'unit_label_ar', 'unit_label_en']);
    }

    // ---- helpers ------------------------------------------------------------

    /** Strip markup from free text and turn empty strings into null. */
    private function clean(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $value = trim(strip_tags($data[$field]));
                $data[$field] = $value === '' ? null : $value;
            }
        }

        return $data;
    }

    /** Use the given slug or derive one from the English name; keep it unique. */
    private function slug(?string $given, string $nameEn, string $model, ?int $ignoreId = null): string
    {
        $base = $given ?: (Str::slug($nameEn) ?: 'item');
        $slug = $base;
        $taken = fn (string $s) => $model::where('slug', $s)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();

        if ($given && $taken($slug)) {
            throw ValidationException::withMessages(['slug' => [__('validation.unique', ['attribute' => 'slug'])]]);
        }
        for ($i = 2; $taken($slug); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /** Everything customers see is cached; drop it so changes show at once. */
    private function flush(): void
    {
        foreach (config('app.supported_locales', ['ar', 'en']) as $locale) {
            Cache::forget("catalog:{$locale}:v4");
            Cache::forget("api:catalog:{$locale}");
            Cache::forget("home:featured-services:{$locale}");
        }
        Cache::forget('home:stats');
        Cache::forget('seo:sitemap');
    }

    private function category(ServiceCategory $c): array
    {
        return $c->only([
            'id', 'slug', 'name_ar', 'name_en', 'description_ar', 'description_en', 'icon', 'image_url',
            'is_remote', 'requires_kyc', 'is_active', 'sort_order',
        ]);
    }

    private function service(Service $s): array
    {
        return [
            'id' => $s->id,
            'service_category_id' => $s->service_category_id,
            'slug' => $s->slug,
            'name_ar' => $s->name_ar,
            'name_en' => $s->name_en,
            'description_ar' => $s->description_ar,
            'description_en' => $s->description_en,
            'pricing_model' => $s->pricing_model->value,
            'base_price' => $s->base_price,
            'hourly_rate' => $s->hourly_rate,
            'unit_price' => $s->unit_price,
            'callout_fee' => $s->callout_fee,
            'unit_label_ar' => $s->unit_label_ar,
            'unit_label_en' => $s->unit_label_en,
            'min_duration_minutes' => $s->min_duration_minutes,
            'is_active' => $s->is_active,
            'sort_order' => $s->sort_order,
        ];
    }
}
