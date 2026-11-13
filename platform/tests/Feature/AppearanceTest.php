<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Appearance\AppearanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    private function services(int $count = 3): array
    {
        $cat = ServiceCategory::create(['slug' => 'plumbing', 'name_ar' => 'سباكة', 'name_en' => 'Plumbing']);

        return collect(range(1, $count))->map(fn ($i) => Service::create([
            'service_category_id' => $cat->id, 'slug' => "svc-$i", 'name_ar' => "خدمة $i", 'name_en' => "Service $i",
            'pricing_model' => 'fixed', 'base_price' => 1000 * $i, 'callout_fee' => 500, 'sort_order' => $i,
        ]))->all();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'announcement' => [
                'enabled' => true, 'speed' => 20, 'background' => '#CE1126', 'color' => '#FFFFFF',
                'items' => [['ar' => 'عرض الصيف', 'en' => 'Summer offer', 'href' => '/book']],
            ],
            'banners' => [[
                'title_ar' => 'بانر', 'title_en' => 'Banner', 'body_ar' => null, 'body_en' => null,
                'cta_ar' => 'احجز', 'cta_en' => 'Book', 'href' => '/book', 'image_url' => null,
                'tone' => 'crimson', 'is_active' => true,
            ]],
            'featured_service_ids' => [],
        ], $overrides);
    }

    public function test_defaults_are_served_before_anything_is_saved(): void
    {
        $bar = app(AppearanceService::class)->announcement('ar');

        $this->assertNotNull($bar);
        $this->assertNotEmpty($bar['items']);
        $this->assertSame('#111315', $bar['background']);
    }

    public function test_guests_and_non_admins_cannot_read_or_change_appearance(): void
    {
        $this->getJson('/api/v1/admin/appearance')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->putJson('/api/v1/admin/appearance', $this->payload())->assertForbidden();

        // Dispatchers run operations but do not control the storefront.
        Sanctum::actingAs(User::factory()->dispatcher()->create());
        $this->putJson('/api/v1/admin/appearance', $this->payload())->assertForbidden();
    }

    public function test_admin_updates_the_announcement_and_the_storefront_reflects_it(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson('/api/v1/admin/appearance', $this->payload())->assertOk()
            ->assertJsonPath('data.appearance.announcement.background', '#CE1126');

        $service = app(AppearanceService::class);
        $this->assertSame('عرض الصيف', $service->announcement('ar')['items'][0]['text']);
        $this->assertSame('Summer offer', $service->announcement('en')['items'][0]['text']);
        $this->assertSame(20, $service->announcement('ar')['speed']);
        $this->assertSame('بانر', $service->banners('ar')[0]['title']);
    }

    public function test_disabling_the_bar_hides_it(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->putJson('/api/v1/admin/appearance', $this->payload(['announcement' => ['enabled' => false]]))->assertOk();

        $this->assertNull(app(AppearanceService::class)->announcement('ar'));
    }

    public function test_english_falls_back_to_arabic_when_missing(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $payload = $this->payload();
        $payload['announcement']['items'] = [['ar' => 'نص عربي فقط', 'en' => null, 'href' => null]];
        $this->putJson('/api/v1/admin/appearance', $payload)->assertOk();

        $this->assertSame('نص عربي فقط', app(AppearanceService::class)->announcement('en')['items'][0]['text']);
    }

    public function test_unsafe_links_colours_and_markup_are_rejected_or_stripped(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        foreach (['javascript:alert(1)', '//evil.example', 'http://insecure.example'] as $href) {
            $payload = $this->payload();
            $payload['announcement']['items'][0]['href'] = $href;
            $this->putJson('/api/v1/admin/appearance', $payload)->assertStatus(422)
                ->assertJsonValidationErrors(['announcement.items.0.href']);
        }

        $this->putJson('/api/v1/admin/appearance', $this->payload(['announcement' => ['background' => 'red; x:url(evil)']]))
            ->assertStatus(422)->assertJsonValidationErrors(['announcement.background']);

        $payload = $this->payload();
        $payload['announcement']['items'][0]['ar'] = '<script>x</script>عرض <b>قوي</b>';
        $this->putJson('/api/v1/admin/appearance', $payload)->assertOk();
        $this->assertSame('xعرض قوي', app(AppearanceService::class)->announcement('ar')['items'][0]['text']);
    }

    public function test_featured_services_follow_the_admin_order(): void
    {
        [$a, $b, $c] = $this->services();
        Sanctum::actingAs(User::factory()->admin()->create());

        // Default: catalogue order.
        $this->assertSame([$a->id, $b->id, $c->id], array_column(app(AppearanceService::class)->featuredServices('ar'), 'id'));

        $this->putJson('/api/v1/admin/appearance', $this->payload(['featured_service_ids' => [$c->id, $a->id]]))->assertOk();

        $featured = app(AppearanceService::class)->featuredServices('ar');
        $this->assertSame([$c->id, $a->id], array_column($featured, 'id'));
        $this->assertSame(3000, $featured[0]['from_price']);
        $this->assertSame('سباكة', $featured[0]['category']['name']);
    }

    public function test_unknown_featured_service_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson('/api/v1/admin/appearance', $this->payload(['featured_service_ids' => [999]]))
            ->assertStatus(422)->assertJsonValidationErrors(['featured_service_ids.0']);
    }

    public function test_the_local_login_shortcut_is_not_registered_outside_local(): void
    {
        // Tests run with APP_ENV=testing: the credential-less admin login must not exist.
        $this->get('/bypass-login')->assertNotFound();
    }
}
