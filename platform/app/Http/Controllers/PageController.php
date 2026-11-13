<?php

namespace App\Http\Controllers;

use App\Models\Governorate;
use App\Models\Order;
use App\Models\ServiceCategory;
use App\Models\TradespersonProfile;
use App\Services\Appearance\AppearanceService;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function home(AppearanceService $appearance): Response
    {
        $locale = app()->getLocale();

        return Inertia::render('Home', [
            'banners' => fn () => $appearance->banners($locale),
            'featuredServices' => fn () => $appearance->featuredServices($locale),
            'stats' => fn () => $appearance->stats(),
            'governorates' => fn () => $this->governorates(),
            'featuredPros' => fn () => $this->featuredPros(),
        ]);
    }

    private function featuredPros(): \Illuminate\Support\Collection
    {
        return Cache::remember('home:featured-pros:v2', 300, fn () => TradespersonProfile::where('kyc_status', 'verified')
            ->orderByDesc('rating_avg')->limit(6)
            ->get(['id', 'display_name', 'image_url', 'headline_ar', 'headline_en', 'rating_avg', 'rating_count', 'badges', 'completed_orders']));
    }

    public function sitemap(): HttpResponse
    {
        $urls = Cache::remember('seo:sitemap', 3600, function (): array {
            $base = rtrim(config('app.url'), '/');
            $categories = ServiceCategory::active()->get(['slug', 'updated_at']);

            return [
                ['loc' => $base.'/', 'lastmod' => now()->toAtomString(), 'priority' => '1.0'],
                ['loc' => $base.'/pros', 'lastmod' => now()->toAtomString(), 'priority' => '0.8'],
                ...$categories->map(fn ($category) => [
                    'loc' => $base.'/services/'.$category->slug,
                    'lastmod' => $category->updated_at?->toAtomString(),
                    'priority' => '0.7',
                ])->all(),
            ];
        });

        return response()->view('sitemap', compact('urls'))->header('Content-Type', 'application/xml');
    }

    public function category(ServiceCategory $category): Response
    {
        abort_unless($category->is_active, 404);
        $category->load(['services' => fn ($q) => $q->active()]);

        return Inertia::render('Category', [
            'category' => $category,
            'governorates' => fn () => $this->governorates(),
        ]);
    }

    public function book(Request $request, AppearanceService $appearance, ?string $service = null): Response
    {
        $locale = app()->getLocale();

        return Inertia::render('Home', [
            'banners' => fn () => $appearance->banners($locale),
            'featuredServices' => fn () => $appearance->featuredServices($locale),
            'stats' => fn () => $appearance->stats(),
            'featuredPros' => fn () => $this->featuredPros(),
            'openBooking' => true,
            'preselectServiceSlug' => $service,
            'governorates' => fn () => $this->governorates(),
        ]);
    }

    public function track(?string $reference = null): Response
    {
        return Inertia::render('Track', [
            'reference' => $reference,
            'order' => $reference ? $this->publicTracking($reference) : null,
        ]);
    }

    public function pros(): Response
    {
        return Inertia::render('Pros', ['governorates' => fn () => $this->governorates()]);
    }

    public function join(): Response
    {
        return Inertia::render('Join', [
            'categories' => ServiceCategory::active()->get(['id', 'name_ar', 'name_en', 'slug']),
            'governorates' => fn () => $this->governorates(),
        ]);
    }

    public function login(): Response
    {
        return Inertia::render('Auth/Login', [
            'redirect' => request()->query('redirect', route('account')),
        ]);
    }

    public function account(Request $request): Response
    {
        return Inertia::render('Account/Index', [
            'orders' => $request->user()->orders()
                ->with(['service', 'governorate', 'tradesperson:id,display_name'])
                ->latest()->paginate(10),
        ]);
    }

    public function accountOrder(Request $request, string $reference): Response
    {
        $order = Order::where('reference', $reference)->firstOrFail();
        $this->authorize('view', $order);
        $order->load(['service', 'category', 'governorate', 'address', 'items', 'review',
            'tradesperson:id,user_id,display_name,image_url,badges,rating_avg,rating_count',
            'tradesperson.user:id,phone',
            'events' => fn ($q) => $q->select(['id', 'order_id', 'type', 'from_status', 'to_status', 'created_at'])]);
        $order->tradesperson?->user?->makeVisible(['phone']);

        return Inertia::render('Account/Order', ['order' => $order]);
    }

    public function tradeDashboard(Request $request): Response
    {
        $profile = $request->user()->tradespersonProfile;
        abort_unless($profile, 403);
        $profile->load(['kycDocuments', 'wallet', 'primaryCategory', 'baseGovernorate']);

        return Inertia::render('Trade/Dashboard', ['profile' => $profile]);
    }

    public function adminHome(): Response
    {
        return Inertia::render('Admin/Dashboard');
    }

    /**
     * What anyone holding an order reference may see. Tracking is public by
     * design (guests have no account), so this is a strict whitelist: status
     * and timeline only — no name, phone, address, notes or internal messages.
     */
    private function publicTracking(string $reference): ?array
    {
        $order = Order::where('reference', strtoupper(trim($reference)))
            ->with(['service:id,name_ar,name_en', 'governorate:id,name_ar,name_en', 'tradesperson:id,display_name,badges'])
            ->first();

        if (! $order) {
            return null;
        }

        return [
            'reference' => $order->reference,
            'status' => $order->status->value,
            'total_estimate' => $order->total_estimate,
            'currency' => $order->currency,
            'created_at' => $order->created_at?->toIso8601String(),
            'service' => $order->service?->only(['name_ar', 'name_en']),
            'governorate' => $order->governorate?->only(['name_ar', 'name_en']),
            'tradesperson' => $order->tradesperson?->only(['display_name', 'badges']),
            'events' => $order->events()->latest('created_at')->limit(20)
                ->get(['id', 'type', 'to_status', 'created_at'])
                ->map(fn ($e) => ['id' => $e->id, 'type' => $e->type, 'to_status' => $e->to_status, 'created_at' => $e->created_at])
                ->all(),
        ];
    }

    private function governorates(): \Illuminate\Support\Collection
    {
        $locale = app()->getLocale();

        return Cache::remember("governorates:{$locale}", 900, fn () => Governorate::where('is_active', true)->orderBy('name_'.$locale)->get()
            ->map(fn ($g) => [
                'id' => $g->id,
                'code' => $g->code,
                'name' => $g->localizedName($locale),
                'dispatch_multiplier' => $g->dispatch_multiplier,
            ]));
    }
}
