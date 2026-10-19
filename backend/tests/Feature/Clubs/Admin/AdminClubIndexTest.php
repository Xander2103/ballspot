<?php

namespace Tests\Feature\Clubs\Admin;

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\ClubPhotoSubmission;
use App\Models\User;
use App\Services\Clubs\ClubInviteCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * AT-ADM-02: the Clubs index searches every identifier server-side, filters
 * combine, sort and pagination keep the query string, and the page query is
 * bounded (no N+1).
 */
class AdminClubIndexTest extends TestCase
{
    use CreatesClubs, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    /** @return list<string> club names in page order */
    private function names(string $query = ''): array
    {
        return $this->actingAs($this->admin)->get('/admin/clubs' . $query)->assertOk()
            ->viewData('clubs')->pluck('name')->all();
    }

    public function test_search_matches_each_identifier(): void
    {
        $owner = User::factory()->create(['username' => 'coach_anna', 'email' => 'anna.coach@example.test']);
        $target = $this->makeClub([
            'name' => 'KFC Zebra Hoeven', 'slug' => 'kfc-zebra-hoeven', 'city' => 'Oudenaarde', 'external_ref' => 'KBVB-04411',
        ], $owner);
        $code = app(ClubInviteCodeService::class)->create($target, [], $this->admin, 'platform_admin');

        // Noise: similar but different clubs; a plain member of the noise club
        // shares nothing with the target.
        $noise = $this->makeClub(['name' => 'VV Lions', 'slug' => 'vv-lions', 'city' => 'Gent', 'external_ref' => 'KBVB-99999']);
        $this->addMember($noise, User::factory()->create(['username' => 'just_member']));
        $this->makeClub(['name' => 'Hoeven Kids', 'slug' => 'hoeven-kids', 'city' => 'Brugge']);

        $only = fn (string $q) => $this->assertSame(['KFC Zebra Hoeven'], $this->names('?q=' . urlencode($q)), "q={$q}");

        $only('Zebra');                         // name fragment
        $only('zebra');                         // case-insensitive name
        $only('kfc-zeb');                       // slug prefix
        $only('Oudenaar');                      // city fragment
        $only('KBVB-04411');                    // exact external ref
        $only((string) $target->id);            // exact numeric id
        $only($target->public_id);              // exact ULID
        $only(strtolower($target->public_id));  // ULID in lower case
        $only($code->formatted());              // invite code ABCD-EFGH
        $only(strtolower($code->code));         // invite code, lower case, no dash
        $only('coach_anna');                    // owner username
        $only('ANNA.coach@example.test');       // owner email (case-insensitive)

        // A slug fragment that is not a prefix, a plain member, and wildcards match nothing.
        $this->assertSame([], $this->names('?q=zebra-hoeven'));
        $this->assertSame([], $this->names('?q=just_member'));
        $this->assertSame([], $this->names('?q=' . urlencode('%')));
        $this->assertSame(['Hoeven Kids', 'KFC Zebra Hoeven'], $this->names('?q=Hoeven&sort=name'));
    }

    public function test_filters_combine(): void
    {
        $sport = $this->clubSport();
        $a = $this->makeClub(['name' => 'A Club', 'country_code' => 'BE', 'sport_id' => $sport->id, 'club_type' => 'school']);
        $b = $this->makeClub(['name' => 'B Club', 'country_code' => 'BE', 'sport_id' => $sport->id]);
        $c = $this->makeClub(['name' => 'C Club', 'country_code' => 'NL', 'sport_id' => $sport->id]);
        $d = $this->makeClub(['name' => 'D Club', 'country_code' => 'BE']);
        $b->forceFill(['status' => 'suspended'])->save();
        $c->forceFill(['status' => 'suspended'])->save();
        $d->forceFill(['status' => 'archived'])->save();

        $this->assertSame(['B Club'], $this->names('?status[]=suspended&country=be&sport=' . $sport->id . '&sort=name'));
        $this->assertSame(['B Club', 'C Club', 'D Club'], $this->names('?status[]=suspended&status[]=archived&sort=name'));
        $this->assertSame(['A Club'], $this->names('?club_type=school'));
        $this->assertSame(['A Club', 'B Club', 'C Club', 'D Club'], $this->names('?status[]=bogus&sort=name'), 'unknown values are ignored');

        // Live competition, open photos, needs owner.
        $pack = $this->makeClubPack($a, 1);
        $this->makeCompetition($a, [$pack], ['state' => 'published', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        $this->makeCompetition($b, [], ['state' => 'published', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]); // upcoming only
        ClubPhotoSubmission::factory()->create(['club_id' => $c->id, 'status' => 'pending']);
        ClubPhotoSubmission::factory()->create(['club_id' => $b->id, 'status' => 'converted']);
        $this->addMember($a, User::factory()->create(), ClubRole::Owner);

        $this->assertSame(['A Club'], $this->names('?has_active_competition=1'));
        $this->assertSame(['C Club'], $this->names('?has_pending_photos=1'));
        $this->assertSame(['B Club', 'C Club'], $this->names('?needs_owner=1&sort=name'), 'archived clubs are never flagged needs_owner');
        $this->assertSame([], $this->names('?has_active_competition=1&country=NL'));

        // Created range.
        $a->forceFill(['created_at' => now()->subDays(40)])->save();
        $this->assertSame(['A Club'], $this->names('?created_to=' . now()->subDays(30)->toDateString()));
        $this->assertSame(['B Club', 'C Club', 'D Club'], $this->names('?created_from=' . now()->subDays(30)->toDateString() . '&sort=name'));
    }

    public function test_sorting(): void
    {
        $b = $this->makeClub(['name' => 'Bravo']);
        $a = $this->makeClub(['name' => 'Alpha']);
        $c = $this->makeClub(['name' => 'Charlie']);
        foreach (range(1, 3) as $i) {
            $this->addMember($c, User::factory()->create());
        }
        $this->addMember($b, User::factory()->create());
        $a->forceFill(['created_at' => now()->subDays(3), 'last_activity_at' => now()->subMinutes(5)])->save();
        $b->forceFill(['created_at' => now()->subDays(2), 'last_activity_at' => now()->subDays(2)])->save();
        $c->forceFill(['created_at' => now()->subDay(), 'last_activity_at' => null])->save();

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $this->names('?sort=name'));
        $this->assertSame(['Charlie', 'Bravo', 'Alpha'], $this->names('?sort=created'));
        $this->assertSame(['Charlie', 'Bravo', 'Alpha'], $this->names('?sort=members'));
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $this->names('?sort=activity'), 'never-active clubs last');
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $this->names(''), 'default = last activity');
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $this->names('?sort=drop+table'));
    }

    public function test_pagination_keeps_the_query_string_and_page_sizes_are_bounded(): void
    {
        foreach (range(1, 30) as $i) {
            $this->makeClub(['name' => sprintf('Club %02d', $i), 'city' => 'Leuven']);
        }
        $this->makeClub(['name' => 'Elsewhere', 'city' => 'Gent']);

        $res = $this->actingAs($this->admin)->get('/admin/clubs?q=Leuven&sort=name')->assertOk();
        $page = $res->viewData('clubs');
        $this->assertSame(30, $page->total());
        $this->assertCount(25, $page->items());
        $this->assertStringContainsString('q=Leuven', $page->url(2));
        $this->assertStringContainsString('sort=name', $page->url(2));
        $res->assertSee('Showing 1–25 of 30 clubs');
        $this->assertMatchesRegularExpression('/href="[^"]*\/admin\/clubs\?q=Leuven&amp;sort=name&amp;page=2"/', $res->getContent());

        $second = $this->actingAs($this->admin)->get('/admin/clubs?q=Leuven&sort=name&page=2')->assertOk()->viewData('clubs');
        $this->assertSame(['Club 26', 'Club 27', 'Club 28', 'Club 29', 'Club 30'], $second->pluck('name')->all());

        $this->assertCount(30, $this->actingAs($this->admin)->get('/admin/clubs?q=Leuven&per_page=50')->viewData('clubs')->items());
        $this->assertSame(25, $this->actingAs($this->admin)->get('/admin/clubs?per_page=5000')->viewData('clubs')->perPage());
    }

    public function test_page_query_count_does_not_grow_with_the_number_of_clubs(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->admin)->get('/admin/clubs')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        foreach (range(1, 2) as $i) {
            $club = $this->makeClub(['sport_id' => $this->clubSport()->id], User::factory()->create());
            ClubPhotoSubmission::factory()->create(['club_id' => $club->id]);
        }
        $small = $count();

        foreach (range(1, 15) as $i) {
            $club = $this->makeClub(['sport_id' => $this->clubSport()->id], User::factory()->create());
            ClubPhotoSubmission::factory()->create(['club_id' => $club->id]);
        }
        $this->assertSame($small, $count());
    }

    public function test_empty_and_no_result_states(): void
    {
        $this->actingAs($this->admin)->get('/admin/clubs')->assertOk()
            ->assertSee('No clubs yet.')
            ->assertSee('Create the first club.');

        $this->makeClub(['name' => 'Only Club']);
        $this->actingAs($this->admin)->get('/admin/clubs?q=nothing-like-this')->assertOk()
            ->assertSee('No clubs match these filters')
            ->assertSee('for &ldquo;nothing-like-this&rdquo;', false)
            ->assertSee('Clear filters')
            ->assertDontSee('No clubs yet.');
    }

    public function test_index_shows_status_segments_counts_and_row_actions(): void
    {
        $owner = User::factory()->create(['username' => 'owner_one']);
        $club = $this->makeClub(['name' => 'Segment Club', 'city' => 'Gent', 'country_code' => 'BE'], $owner);
        $this->makeClub(['name' => 'Ownerless']);
        Club::factory()->suspended()->create(['name' => 'Paused']);

        $html = $this->actingAs($this->admin)->get('/admin/clubs')->assertOk()->getContent();
        $counts = $this->actingAs($this->admin)->get('/admin/clubs')->viewData('counts');
        $this->assertSame(['all' => 3, 'active' => 2, 'suspended' => 1, 'archived' => 0, 'needs_owner' => 1], $counts);

        foreach (['href="/admin/clubs?status%5B%5D=active"', 'href="/admin/clubs?status%5B%5D=suspended"', 'href="/admin/clubs?needs_owner=1"',
                  '+ Create club', 'Search name, slug, city, ref, ID, invite code, owner…', 'owner_one', 'Gent', 'Needs owner',
                  'aria-label="More actions for Segment Club"', 'data-action="suspend"', 'data-action="archive"', 'id="club-status-modal"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsString('confirm(', $html, 'status actions use Bootstrap modals, not alert()/confirm()');
    }
}
