<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Governorate;
use App\Models\User;
use App\Models\ZoneWaitlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WaitlistVotingAndBrandTest extends TestCase
{
    use RefreshDatabase;

    private Governorate $capital;

    private Governorate $aden;

    private District $live;

    private District $soon;

    private District $crater;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capital = Governorate::create(['code' => 'capital', 'name_ar' => 'الأمانة', 'name_en' => 'Capital', 'is_active' => true]);
        $this->aden = Governorate::create(['code' => 'aden', 'name_ar' => 'عدن', 'name_en' => 'Aden', 'is_active' => false]);
        $this->live = District::create(['governorate_id' => $this->capital->id, 'name_ar' => 'السبعين', 'status' => 'active']);
        $this->soon = District::create(['governorate_id' => $this->capital->id, 'name_ar' => 'شعوب', 'status' => 'coming_soon']);
        $this->crater = District::create(['governorate_id' => $this->aden->id, 'name_ar' => 'كريتر', 'status' => 'coming_soon']);
    }

    /** Cast a vote from its own address so the per-IP limiter stays out of the way. */
    private function vote(array $payload, int $n)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.$n"])
            ->postJson('/api/v1/waitlist', $payload + ['phone' => '+96777100'.str_pad((string) $n, 4, '0', STR_PAD_LEFT)]);
    }

    // ---- voting -------------------------------------------------------------

    public function test_a_visitor_can_vote_for_a_whole_coming_soon_city(): void
    {
        $this->vote(['governorate_id' => $this->aden->id], 1)->assertCreated();

        $entry = ZoneWaitlist::sole();
        $this->assertSame([$this->aden->id, null, null], [$entry->governorate_id, $entry->district_id, $entry->district_name]);
    }

    public function test_a_visitor_can_vote_for_a_district_in_a_coming_soon_city(): void
    {
        $this->vote(['governorate_id' => $this->aden->id, 'district_id' => $this->crater->id], 1)->assertCreated();
        $this->vote(['governorate_id' => $this->aden->id, 'district_name' => 'إنماء'], 2)->assertCreated();

        $this->assertSame(2, ZoneWaitlist::where('governorate_id', $this->aden->id)->count());
    }

    public function test_a_live_city_cannot_be_voted_for_as_a_whole(): void
    {
        // The capital is live: only its coming-soon or unlisted districts can be requested.
        $this->vote(['governorate_id' => $this->capital->id], 1)->assertStatus(422);
        $this->vote(['governorate_id' => $this->capital->id, 'district_id' => $this->live->id], 2)->assertStatus(422);
        $this->vote(['governorate_id' => $this->capital->id, 'district_id' => $this->soon->id], 3)->assertCreated();
    }

    public function test_sanaa_and_amanat_al_asimah_are_not_vote_targets(): void
    {
        $this->capital->update(['code' => 'amanat_al_asimah']);
        $sanaa = Governorate::create(['code' => 'sanaa', 'name_ar' => 'صنعاء', 'name_en' => 'Sanaa', 'is_active' => true]);
        District::create(['governorate_id' => $sanaa->id, 'name_ar' => 'سنحان', 'status' => 'active']);

        // Neither appears in the public vote tree, even though one is live.
        $codes = collect($this->getJson('/api/v1/zones')->assertOk()->json('data'))->pluck('code');
        $this->assertNotContains('amanat_al_asimah', $codes);
        $this->assertNotContains('sanaa', $codes);
        $this->assertContains('aden', $codes);

        // And a vote aimed at them is refused: book directly instead.
        $this->vote(['governorate_id' => $sanaa->id], 1)->assertStatus(422)->assertJsonValidationErrors(['governorate_id']);
        $this->vote(['governorate_id' => $this->capital->id], 2)->assertStatus(422)->assertJsonValidationErrors(['governorate_id']);
        $this->assertSame(0, ZoneWaitlist::count());
    }

    public function test_public_zone_tree_reports_vote_counts_and_updates_after_a_vote(): void
    {
        $this->vote(['governorate_id' => $this->aden->id], 1);
        $this->vote(['governorate_id' => $this->aden->id, 'district_id' => $this->crater->id], 2);
        $this->vote(['governorate_id' => $this->aden->id, 'district_id' => $this->crater->id], 3);
        $this->vote(['governorate_id' => $this->capital->id, 'district_id' => $this->soon->id], 4);

        $zones = collect($this->getJson('/api/v1/zones')->assertOk()->json('data'))->keyBy('code');

        $this->assertSame(3, $zones['aden']['votes'], 'City total includes its districts and whole-city votes.');
        $this->assertSame(2, collect($zones['aden']['districts'])->firstWhere('id', $this->crater->id)['votes']);
        $this->assertSame(1, $zones['capital']['votes']);
        $this->assertSame(0, collect($zones['capital']['districts'])->firstWhere('id', $this->live->id)['votes']);

        // The count is cached, but a new vote shows immediately.
        $this->vote(['governorate_id' => $this->aden->id, 'district_name' => 'المنصورة'], 5)->assertCreated();
        $this->assertSame(4, collect($this->getJson('/api/v1/zones')->json('data'))->firstWhere('code', 'aden')['votes']);
    }

    public function test_vote_counts_never_expose_who_voted(): void
    {
        $this->vote(['governorate_id' => $this->aden->id, 'name' => 'منار السرية'], 7);

        foreach ([$this->getJson('/api/v1/zones')->getContent(), $this->get('/waitlist')->assertOk()->getContent()] as $body) {
            $this->assertStringNotContainsString('7710007', $body);
            $this->assertStringNotContainsString('منار السرية', $body);
            $this->assertStringNotContainsString(trim(json_encode('منار السرية'), '"'), $body);
        }
    }

    public function test_waitlist_page_lists_every_city_and_preselects_from_the_link(): void
    {
        $this->vote(['governorate_id' => $this->aden->id], 1);

        $this->get('/waitlist?city=aden')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Waitlist')
            ->where('city', 'aden')
            ->has('zones', 2)
            ->where('zones', fn ($zones) => collect($zones)->firstWhere('code', 'aden')['votes'] === 1
                && collect($zones)->firstWhere('code', 'aden')['status'] === 'coming_soon'));

        // The parameter is reduced to a plain code; nothing else is reflected.
        $this->get('/waitlist?city='.rawurlencode('<script>alert(1)</script>'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('city', 'scriptalertscript'));
        $this->get('/waitlist')->assertOk()->assertInertia(fn ($page) => $page->where('city', null));
    }

    public function test_the_booking_form_still_hides_cities_that_are_not_live(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->has('governorates', 1)->where('governorates.0.code', 'capital'));
    }

    // ---- admin: demand ------------------------------------------------------

    public function test_admin_sees_votes_per_city_and_district_and_the_unlisted_names(): void
    {
        $this->vote(['governorate_id' => $this->aden->id], 1);
        $this->vote(['governorate_id' => $this->aden->id, 'district_id' => $this->crater->id], 2);
        $this->vote(['governorate_id' => $this->aden->id, 'district_name' => 'إنماء'], 3);
        $this->vote(['governorate_id' => $this->aden->id, 'district_name' => 'إنماء'], 4);
        $this->vote(['governorate_id' => $this->aden->id, 'district_name' => 'بئر أحمد'], 5);
        $this->vote(['governorate_id' => $this->capital->id, 'district_id' => $this->soon->id], 6);

        Sanctum::actingAs(User::factory()->dispatcher()->create());
        $data = collect($this->getJson('/api/v1/admin/zones')->assertOk()->json('data'))->keyBy('id');

        $aden = $data[$this->aden->id];
        $this->assertSame(5, $aden['waitlist_count']);
        $this->assertSame(1, collect($aden['districts'])->firstWhere('id', $this->crater->id)['waitlist_count']);
        $this->assertSame([['name' => 'إنماء', 'count' => 2], ['name' => 'بئر أحمد', 'count' => 1]], $aden['unlisted'], 'Typed names, most requested first.');

        $capital = $data[$this->capital->id];
        $this->assertSame(1, $capital['waitlist_count']);
        $this->assertSame([], $capital['unlisted']);

        // Enough to rank zones by demand: Aden outranks the capital.
        $this->assertGreaterThan($capital['waitlist_count'], $aden['waitlist_count']);
    }

    // ---- brand palette and motion stack --------------------------------------

    public function test_tailwind_palette_matches_the_approved_brand(): void
    {
        $config = file_get_contents(base_path('tailwind.config.js'));

        foreach ([
            '#FFFFFF' => false, // white is Tailwind's default; not redeclared
            '#F8F9FA' => true,  // ivory surfaces (60%)
            '#111315' => true,  // charcoal ink (30%)
            '#1E2022' => true,
            '#CE1126' => true,  // Yemeni crimson accent (10%)
            '#A80D1E' => true,  // crimson, pressed
            '#D4AF37' => true,  // gold: KYC badges and ratings only
        ] as $hex => $required) {
            if ($required) {
                $this->assertStringContainsString($hex, $config, "$hex is missing from the Tailwind palette");
            }
        }
        // The old navy must be gone everywhere it was hard-coded.
        foreach (['tailwind.config.js', 'resources/css/app.css', 'resources/views/app.blade.php', 'public/manifest.webmanifest'] as $file) {
            $this->assertStringNotContainsStringIgnoringCase('#0F172A', file_get_contents(base_path($file)), "$file still uses the old navy");
        }
    }

    public function test_gold_is_reserved_for_kyc_badges_and_ratings(): void
    {
        $allowed = ['Components/Badge.vue'];
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'vue') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(resource_path('js')) + 1));
            $source = file_get_contents($file->getPathname());

            // Stock amber is not a brand colour at all.
            if (preg_match('/\b(?:bg|text|border|ring)-amber-\d+/', $source)) {
                $offenders[] = "$relative uses amber";
            }
            // Gold backgrounds/borders only on the KYC badge component; the gold
            // star (text-gold) and avatar ring are the rating / verified markers.
            if (! in_array($relative, $allowed, true) && preg_match('/\b(?:bg|border)-gold(?:-\d+)?\b/', $source)) {
                $offenders[] = "$relative uses a gold surface";
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_smooth_scroll_and_motion_stack_are_wired_into_the_public_layout(): void
    {
        $package = json_decode(file_get_contents(base_path('package.json')), true);
        $deps = $package['dependencies'] + $package['devDependencies'];
        $this->assertArrayHasKey('gsap', $deps);
        $this->assertArrayHasKey('lenis', $deps);

        foreach (['js/Components/SmoothScroll.vue', 'js/composables/useSmoothScroll.js', 'js/composables/useScrollReveal.js', 'js/Components/AnnouncementBar.vue'] as $file) {
            $this->assertFileExists(resource_path($file));
        }

        $layout = file_get_contents(resource_path('js/Layouts/PublicLayout.vue'));
        $this->assertStringContainsString('<SmoothScroll', $layout);
        $this->assertStringContainsString('<AnnouncementBar', $layout);

        $scroll = file_get_contents(resource_path('js/composables/useSmoothScroll.js'));
        $this->assertStringContainsString("router.on('navigate'", $scroll, 'Must resync on Inertia navigation.');
        $this->assertStringContainsString('ScrollTrigger.refresh', $scroll);
        $this->assertStringContainsString('prefersReducedMotion', $scroll);
    }

    public function test_the_announcement_bar_is_fed_from_the_admin_setting_on_every_public_page(): void
    {
        foreach (['/', '/waitlist', '/join-technician', '/pros'] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->has('announcement.items')->has('announcement.speed'));
        }
    }
}
