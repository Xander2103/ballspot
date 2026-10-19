<?php

namespace Tests\Feature\Clubs\Portal;

use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Models\ClubAuditEvent;
use App\Models\User;
use App\Services\Clubs\ClubStandingsCsvExporter;
use Tests\Concerns\BuildsClubStandings;

/**
 * Wave F portal Leaderboard page + CSV downloads (club leaderboard and a
 * competition's standings): columns rank, username, total_score,
 * packs_completed, attempts_used; usernames only; formula injection guard;
 * safe filename; audit export.downloaded (club_admin); owner/admin only.
 */
class PortalCsvExportTest extends PortalTestCase
{
    use BuildsClubStandings;

    public function test_leaderboard_page_shows_the_ranking_and_the_download_link(): void
    {
        $this->seedLeaderboard($this->club);

        $this->portalAs($this->owner)->get($this->url('/leaderboard'))->assertOk()
            ->assertSee('Leaderboard')
            ->assertSee('alice')
            ->assertSee($this->url('/leaderboard.csv'))
            ->assertSee('Download CSV')
            ->assertDontSee('secret-mail.test');

        // The nav links the page.
        $this->portalAs($this->owner)->get($this->url())->assertOk()->assertSee($this->url('/leaderboard'));
    }

    public function test_leaderboard_csv_content_headers_and_audit(): void
    {
        $this->seedLeaderboard($this->club);

        $res = $this->portalAs($this->owner)->get($this->url('/leaderboard.csv'))->assertOk();
        $this->assertStringStartsWith('text/csv', $res->headers->get('Content-Type'));
        $this->assertSame('attachment; filename=portal-fc-leaderboard-' . now()->format('Ymd') . '.csv', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        $body = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertSame([
            ['rank', 'username', 'total_score', 'packs_completed', 'attempts_used'],
            ['1', 'alice', '300', '1', '1'],
            ['2', "'=HYPERLINK(\"http://x\")", '200', '1', '1'],
            ['3', "'@evil", '100', '1', '1'],
        ], $this->csvRows($body));
        $this->assertStringNotContainsString('secret-mail.test', $body);
        $this->assertStringNotContainsString('club_owner', $body, 'members without a score are not listed');

        $event = ClubAuditEvent::where('action', 'export.downloaded')->firstOrFail();
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, $event->actor_kind);
        $this->assertSame($this->owner->id, $event->actor_user_id);
        $this->assertSame(['export' => 'leaderboard', 'count' => 3], $event->metadata);
    }

    public function test_competition_standings_csv_with_shared_ranks_and_a_safe_filename(): void
    {
        $competition = $this->seedCompetitionStandings($this->club);
        $admin = $this->userWithRole(ClubRole::Admin);

        $this->portalAs($admin)->get($this->url('/competitions/' . $competition->public_id . '?tab=standings'))->assertOk()
            ->assertSee($this->url('/competitions/' . $competition->public_id . '/standings.csv'))
            ->assertSee('Download CSV');

        $res = $this->portalAs($admin)->get($this->url('/competitions/' . $competition->public_id . '/standings.csv'))->assertOk();
        $this->assertSame('attachment; filename=portal-fc-autumn-cup-final-standings-' . now()->format('Ymd') . '.csv', $res->headers->get('Content-Disposition'));
        $this->assertSame([
            ['rank', 'username', 'total_score', 'packs_completed', 'attempts_used'],
            ['1', 'bob', '500', '2', '2'],
            ['2', "'+dora", '400', '1', '3'],
            ['2', "'-carl", '400', '1', '3'],
        ], $this->sortTies($this->csvRows($res->streamedContent())));

        $event = ClubAuditEvent::where('action', 'export.downloaded')->firstOrFail();
        $this->assertSame(['export' => 'competition', 'competition_id' => $competition->id, 'count' => 3], $event->metadata);
        $this->assertSame($competition->id, $event->subject_id);
    }

    public function test_only_owners_and_admins_of_this_club_may_download(): void
    {
        $competition = $this->seedCompetitionStandings($this->club);
        $member = $this->userWithRole(ClubRole::Member);
        $other  = $this->makeClub(['slug' => 'other-fc'], User::factory()->create());
        $otherAdmin = $this->userWithRole(ClubRole::Admin, $other);

        $this->portalAs($member)->get($this->url('/leaderboard.csv'))->assertForbidden();
        $this->portalAs($member)->get($this->url('/competitions/' . $competition->public_id . '/standings.csv'))->assertForbidden();
        $this->portalAs($otherAdmin)->get($this->url('/leaderboard.csv'))->assertNotFound();
        // A competition of another club under this club's slug.
        $foreign = $this->seedCompetitionStandings($other, 'Foreign', '_b');
        $this->portalAs($this->owner)->get($this->url('/competitions/' . $foreign->public_id . '/standings.csv'))->assertNotFound();

        $this->assertSame(0, ClubAuditEvent::where('action', 'export.downloaded')->count());
    }

    public function test_guests_go_to_the_portal_login(): void
    {
        $this->get($this->url('/leaderboard.csv'))->assertRedirect('/club/login');
        $this->get($this->url('/leaderboard'))->assertRedirect('/club/login');
        $this->assertSame(0, ClubAuditEvent::where('action', 'export.downloaded')->count());
    }

    public function test_a_session_without_the_emailed_code_cannot_download(): void
    {
        $this->actingAs($this->owner)->get($this->url('/leaderboard.csv'))->assertRedirect();
        $this->assertSame(0, ClubAuditEvent::where('action', 'export.downloaded')->count());
    }

    public function test_suspended_club_still_allows_the_read_only_download(): void
    {
        $this->seedLeaderboard($this->club);
        $this->club->forceFill(['status' => ClubStatus::Suspended])->save();

        $this->portalAs($this->owner)->get($this->url('/leaderboard.csv'))->assertOk();
    }

    public function test_formula_guard_unit(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx"] as $v) {
            $this->assertSame("'" . $v, ClubStandingsCsvExporter::cell($v));
        }
        foreach (['alice', '0', '12', 'a=b', ''] as $v) {
            $this->assertSame($v, ClubStandingsCsvExporter::cell($v));
        }
    }

    /** Shared ranks have no defined order between them: sort ties by username for a stable assertion. */
    private function sortTies(array $rows): array
    {
        $head = array_shift($rows);
        usort($rows, fn ($a, $b) => [(int) $a[0], $a[1]] <=> [(int) $b[0], $b[1]]);

        return [$head, ...$rows];
    }
}
