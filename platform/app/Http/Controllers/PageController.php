<?php

namespace App\Http\Controllers;

use App\Models\Governorate;
use App\Models\Order;
use App\Models\ServiceCategory;
use App\Models\TradespersonProfile;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function home(): Response
    {
        return Inertia::render('Home', [
            'governorates' => fn () => $this->governorates(),
            'featuredPros' => fn () => Cache::remember('home:featured-pros:v2', 300, fn () => TradespersonProfile::where('kyc_status', 'verified')
                ->orderByDesc('rating_avg')->limit(6)
                ->get(['id', 'display_name', 'image_url', 'headline_ar', 'headline_en', 'rating_avg', 'rating_count', 'badges', 'completed_orders'])),
        ]);
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

    public function book(Request $request, ?string $service = null): Response
    {
        return Inertia::render('Home', [
            'openBooking' => true,
            'preselectServiceSlug' => $service,
            'governorates' => fn () => $this->governorates(),
        ]);
    }

    public function track(?string $reference = null): Response
    {
        return Inertia::render('Track', [
            'reference' => $reference,
            'order' => $reference
                ? Order::where('reference', $reference)
                    ->with(['service', 'governorate', 'tradesperson:id,display_name,badges', 'events' => fn ($q) => $q->limit(20)])
                    ->first()
                : null,
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
        $order->load(['service', 'category', 'governorate', 'address', 'tradesperson.user', 'items', 'events.actor', 'review']);

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
