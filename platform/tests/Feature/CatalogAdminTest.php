<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\TradespersonProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogAdminTest extends TestCase
{
    use RefreshDatabase;

    private ServiceCategory $category;

    private Service $service;

    private Governorate $gov;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gov = Governorate::create(['code' => 'capital', 'name_ar' => 'الأمانة', 'name_en' => 'Capital']);
        $this->category = ServiceCategory::create(['slug' => 'plumbing', 'name_ar' => 'السباكة', 'name_en' => 'Plumbing', 'sort_order' => 1]);
        $this->service = Service::create([
            'service_category_id' => $this->category->id, 'slug' => 'leak-repair', 'name_ar' => 'إصلاح تسريب', 'name_en' => 'Leak repair',
            'pricing_model' => 'fixed', 'base_price' => 6000, 'callout_fee' => 2000,
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function estimate(Service $service): int
    {
        return $this->postJson('/api/v1/estimate', ['service_id' => $service->id, 'governorate_id' => $this->gov->id, 'urgency' => 'today'])
            ->assertOk()->json('data.total');
    }

    // ---- reading ------------------------------------------------------------

    public function test_staff_see_the_whole_catalogue_including_inactive_items(): void
    {
        $off = ServiceCategory::create(['slug' => 'old', 'name_ar' => 'قديم', 'name_en' => 'Old', 'is_active' => false, 'sort_order' => 2]);
        Service::create(['service_category_id' => $this->category->id, 'slug' => 'hidden', 'name_ar' => 'مخفية', 'name_en' => 'Hidden', 'pricing_model' => 'quote', 'is_active' => false]);

        Sanctum::actingAs(User::factory()->dispatcher()->create());
        $res = $this->getJson('/api/v1/admin/catalog')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name_ar', 'السباكة')
            ->assertJsonPath('data.0.name_en', 'Plumbing')
            ->assertJsonCount(2, 'data.0.services')
            ->assertJsonPath('data.0.services.0.base_price', 6000)
            ->assertJsonPath('data.1.id', $off->id)
            ->assertJsonPath('data.1.is_active', false);

        $this->assertSame(['fixed', 'hourly', 'per_unit', 'quote'], $res->json('pricing_models'));
    }

    // ---- categories ---------------------------------------------------------

    public function test_admin_creates_a_category_and_it_appears_to_customers(): void
    {
        $this->admin();
        $this->getJson('/api/v1/catalog'); // warm the public cache, to prove it is invalidated

        $res = $this->postJson('/api/v1/admin/catalog/categories', [
            'name_ar' => 'الكهرباء', 'name_en' => 'Electrical Work', 'icon' => '⚡', 'is_remote' => false,
        ])->assertCreated()->assertJsonPath('data.slug', 'electrical-work')->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('service_categories', ['id' => $res->json('data.id'), 'name_ar' => 'الكهرباء', 'icon' => '⚡']);
        $this->assertContains('electrical-work', array_column($this->getJson('/api/v1/catalog')->json('data'), 'slug'));
    }

    public function test_category_slugs_are_derived_made_unique_and_validated(): void
    {
        $this->admin();

        // Same English name twice: the second gets a suffix instead of failing.
        $this->postJson('/api/v1/admin/catalog/categories', ['name_ar' => 'سباكة ٢', 'name_en' => 'Plumbing'])
            ->assertCreated()->assertJsonPath('data.slug', 'plumbing-2');
        // An explicit duplicate is the admin's mistake: tell them.
        $this->postJson('/api/v1/admin/catalog/categories', ['name_ar' => 'x', 'name_en' => 'X', 'slug' => 'plumbing'])
            ->assertStatus(422)->assertJsonValidationErrors(['slug']);
        $this->postJson('/api/v1/admin/catalog/categories', ['name_ar' => 'x', 'name_en' => 'X', 'slug' => 'Not A Slug!'])
            ->assertStatus(422)->assertJsonValidationErrors(['slug']);
        $this->postJson('/api/v1/admin/catalog/categories', ['name_en' => 'No Arabic'])
            ->assertStatus(422)->assertJsonValidationErrors(['name_ar']);
        $this->postJson('/api/v1/admin/catalog/categories', ['name_ar' => 'x', 'name_en' => 'X', 'image_url' => 'javascript:alert(1)'])
            ->assertStatus(422)->assertJsonValidationErrors(['image_url']);
    }

    public function test_admin_edits_and_deactivates_a_category(): void
    {
        $this->admin();
        $this->getJson('/api/v1/catalog');

        $this->patchJson("/api/v1/admin/catalog/categories/{$this->category->id}", [
            'name_ar' => '<b>السباكة والصرف</b>', 'is_remote' => true, 'image_url' => 'https://example.test/p.jpg',
        ])->assertOk()->assertJsonPath('data.name_ar', 'السباكة والصرف')->assertJsonPath('data.is_remote', true);

        $fresh = $this->category->fresh();
        $this->assertSame(['السباكة والصرف', 'Plumbing', 'plumbing'], [$fresh->name_ar, $fresh->name_en, $fresh->slug], 'Untouched fields are kept.');

        // Deactivating hides it from customers at once, but keeps the row.
        $this->patchJson("/api/v1/admin/catalog/categories/{$this->category->id}", ['is_active' => false])->assertOk();
        $this->assertSame([], $this->getJson('/api/v1/catalog')->json('data'));
        $this->getJson('/api/v1/catalog/categories/plumbing')->assertNotFound();
        $this->assertDatabaseHas('service_categories', ['id' => $this->category->id, 'is_active' => false]);

        $this->patchJson("/api/v1/admin/catalog/categories/{$this->category->id}", ['is_active' => true])->assertOk();
        $this->assertCount(1, $this->getJson('/api/v1/catalog')->json('data'));
    }

    public function test_a_category_in_use_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $this->admin();
        $empty = ServiceCategory::create(['slug' => 'empty', 'name_ar' => 'فارغ', 'name_en' => 'Empty']);
        Service::create(['service_category_id' => $empty->id, 'slug' => 'e1', 'name_ar' => 'خ', 'name_en' => 'S', 'pricing_model' => 'quote']);

        TradespersonProfile::create([
            'user_id' => User::factory()->tradesperson()->create()->id, 'primary_category_id' => $this->category->id,
            'base_governorate_id' => $this->gov->id, 'display_name' => 'فني',
        ]);

        $this->deleteJson("/api/v1/admin/catalog/categories/{$this->category->id}")->assertStatus(409);
        $this->assertDatabaseHas('service_categories', ['id' => $this->category->id]);

        $this->deleteJson("/api/v1/admin/catalog/categories/{$empty->id}")->assertOk();
        $this->assertDatabaseMissing('service_categories', ['id' => $empty->id]);
        $this->assertDatabaseMissing('services', ['slug' => 'e1']);
    }

    // ---- services -----------------------------------------------------------

    public function test_admin_creates_a_service_with_each_pricing_model(): void
    {
        $this->admin();
        $base = ['service_category_id' => $this->category->id];

        $this->postJson('/api/v1/admin/catalog/services', $base + ['name_ar' => 'تركيب سخان', 'name_en' => 'Water Heater', 'pricing_model' => 'fixed', 'base_price' => 9000, 'callout_fee' => 2000])
            ->assertCreated()->assertJsonPath('data.slug', 'water-heater')->assertJsonPath('data.base_price', 9000);
        $this->postJson('/api/v1/admin/catalog/services', $base + ['name_ar' => 'صيانة', 'name_en' => 'Maintenance', 'pricing_model' => 'hourly', 'hourly_rate' => 4000])
            ->assertCreated()->assertJsonPath('data.hourly_rate', 4000);
        $this->postJson('/api/v1/admin/catalog/services', $base + ['name_ar' => 'تمديد', 'name_en' => 'Piping', 'pricing_model' => 'per_unit', 'unit_price' => 1500, 'unit_label_ar' => 'متر', 'unit_label_en' => 'metre'])
            ->assertCreated()->assertJsonPath('data.unit_label_ar', 'متر');
        $this->postJson('/api/v1/admin/catalog/services', $base + ['name_ar' => 'مشروع', 'name_en' => 'Project', 'pricing_model' => 'quote'])
            ->assertCreated()->assertJsonPath('data.pricing_model', 'quote');

        $this->assertSame(5, Service::where('service_category_id', $this->category->id)->count());
    }

    public function test_the_price_matching_the_pricing_model_is_required(): void
    {
        $this->admin();
        $base = ['service_category_id' => $this->category->id, 'name_ar' => 'خدمة', 'name_en' => 'Svc'];

        $this->postJson('/api/v1/admin/catalog/services', $base + ['pricing_model' => 'fixed'])->assertStatus(422)->assertJsonValidationErrors(['base_price']);
        $this->postJson('/api/v1/admin/catalog/services', $base + ['pricing_model' => 'fixed', 'base_price' => 0])->assertStatus(422)->assertJsonValidationErrors(['base_price']);
        $this->postJson('/api/v1/admin/catalog/services', $base + ['pricing_model' => 'hourly', 'base_price' => 5000])->assertStatus(422)->assertJsonValidationErrors(['hourly_rate']);
        $this->postJson('/api/v1/admin/catalog/services', $base + ['pricing_model' => 'per_unit'])->assertStatus(422)->assertJsonValidationErrors(['unit_price']);
        $this->postJson('/api/v1/admin/catalog/services', $base + ['pricing_model' => 'free'])->assertStatus(422)->assertJsonValidationErrors(['pricing_model']);
        $this->postJson('/api/v1/admin/catalog/services', $base + ['pricing_model' => 'fixed', 'base_price' => -5])->assertStatus(422);
        $this->postJson('/api/v1/admin/catalog/services', $base + ['pricing_model' => 'fixed', 'base_price' => 999999999999])->assertStatus(422);
        $this->postJson('/api/v1/admin/catalog/services', ['service_category_id' => 999] + $base + ['pricing_model' => 'quote'])->assertStatus(422)->assertJsonValidationErrors(['service_category_id']);

        // Switching an existing fixed service to hourly without a rate is refused too.
        $this->patchJson("/api/v1/admin/catalog/services/{$this->service->id}", ['pricing_model' => 'hourly'])
            ->assertStatus(422)->assertJsonValidationErrors(['hourly_rate']);
        $this->assertSame('fixed', $this->service->fresh()->pricing_model->value);
    }

    public function test_updating_a_price_changes_what_customers_are_quoted(): void
    {
        $this->admin();
        $this->assertSame(8000, $this->estimate($this->service), '6000 + 2000 call-out before the change.');
        $this->get('/'); // warm the booking catalogue cache

        $this->patchJson("/api/v1/admin/catalog/services/{$this->service->id}", ['base_price' => 7500])
            ->assertOk()->assertJsonPath('data.base_price', 7500)->assertJsonPath('data.callout_fee', 2000);

        $this->assertSame(7500, $this->service->fresh()->base_price);
        $this->assertSame(9500, $this->estimate($this->service));

        // The booking form's catalogue shows the new price straight away.
        $this->get('/')->assertInertia(fn ($page) => $page->where('catalog.0.services.0.base_price', 7500));

        $this->patchJson("/api/v1/admin/catalog/services/{$this->service->id}", ['callout_fee' => 0])->assertOk();
        $this->assertSame(7500, $this->estimate($this->service));
    }

    public function test_admin_edits_moves_and_deactivates_a_service(): void
    {
        $this->admin();
        $other = ServiceCategory::create(['slug' => 'ac', 'name_ar' => 'تكييف', 'name_en' => 'AC']);

        $this->patchJson("/api/v1/admin/catalog/services/{$this->service->id}", [
            'name_ar' => 'إصلاح <script>x</script>تسريب المياه', 'service_category_id' => $other->id,
        ])->assertOk();

        $fresh = $this->service->fresh();
        $this->assertSame(['إصلاح xتسريب المياه', $other->id, 'leak-repair', 6000], [$fresh->name_ar, $fresh->service_category_id, $fresh->slug, $fresh->base_price]);

        $this->patchJson("/api/v1/admin/catalog/services/{$this->service->id}", ['is_active' => false])->assertOk();
        $this->getJson('/api/v1/catalog/services/leak-repair')->assertNotFound();
        $this->assertDatabaseHas('services', ['id' => $this->service->id, 'is_active' => false]);
    }

    public function test_a_service_with_orders_cannot_be_deleted(): void
    {
        $this->admin();
        Order::create([
            'service_id' => $this->service->id, 'service_category_id' => $this->category->id, 'governorate_id' => $this->gov->id,
            'status' => OrderStatus::Closed, 'urgency' => 'today', 'subtotal' => 6000, 'total_estimate' => 8000,
            'commission_percent' => 12, 'commission_amount' => 720, 'contact_name' => 'ع', 'contact_phone' => '+967733111222', 'price_breakdown' => [],
        ]);
        $unused = Service::create(['service_category_id' => $this->category->id, 'slug' => 'unused', 'name_ar' => 'غ', 'name_en' => 'U', 'pricing_model' => 'quote']);

        $this->deleteJson("/api/v1/admin/catalog/services/{$this->service->id}")->assertStatus(409);
        $this->getJson('/api/v1/admin/catalog')->assertJsonPath('data.0.services.0.orders_count', 1);
        $this->deleteJson("/api/v1/admin/catalog/services/{$unused->id}")->assertOk();
        $this->assertDatabaseMissing('services', ['id' => $unused->id]);
    }

    // ---- access control -----------------------------------------------------

    public function test_only_admins_can_change_the_catalogue(): void
    {
        $writes = [
            ['POST', '/api/v1/admin/catalog/categories', ['name_ar' => 'x', 'name_en' => 'X']],
            ['PATCH', "/api/v1/admin/catalog/categories/{$this->category->id}", ['is_active' => false]],
            ['DELETE', "/api/v1/admin/catalog/categories/{$this->category->id}", []],
            ['POST', '/api/v1/admin/catalog/services', ['service_category_id' => $this->category->id, 'name_ar' => 'x', 'name_en' => 'X', 'pricing_model' => 'quote']],
            ['PATCH', "/api/v1/admin/catalog/services/{$this->service->id}", ['base_price' => 1]],
            ['DELETE', "/api/v1/admin/catalog/services/{$this->service->id}", []],
        ];

        foreach ($writes as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertUnauthorized();
        }
        $this->getJson('/api/v1/admin/catalog')->assertUnauthorized();

        // Customers and tradespeople: nothing at all.
        foreach ([User::factory()->create(), User::factory()->tradesperson()->create()] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/admin/catalog')->assertForbidden();
            foreach ($writes as [$method, $url, $body]) {
                $this->json($method, $url, $body)->assertForbidden();
            }
        }

        // Dispatchers can look but not touch prices or the catalogue.
        Sanctum::actingAs(User::factory()->dispatcher()->create());
        $this->getJson('/api/v1/admin/catalog')->assertOk();
        foreach ($writes as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertForbidden();
        }

        $this->assertSame(1, ServiceCategory::count());
        $this->assertSame([6000, true], [$this->service->fresh()->base_price, $this->service->fresh()->is_active]);
    }

    public function test_admin_pages_for_the_catalogue_are_protected(): void
    {
        foreach (['/admin/categories', '/admin/services'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->actingAs(User::factory()->create());
        $this->get('/admin/categories')->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/categories')->assertOk()->assertInertia(fn ($page) => $page->component('Admin/Categories'));
        $this->get('/admin/services')->assertOk()->assertInertia(fn ($page) => $page->component('Admin/Services'));
    }
}
