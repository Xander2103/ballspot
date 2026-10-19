<?php

namespace Tests\Feature\Clubs\Admin;

use App\Models\ClubAuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubStandings;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * Wave F central-admin CSV downloads: club leaderboard (overview tab) and a
 * competition's standings (standings tab) — same exporter as the portal,
 * audited as platform_admin, closed to non-admins, scoped to the club.
 */
class AdminClubCsvExportTest extends TestCase
{
    use BuildsClubStandings, CreatesClubs, RefreshDatabase;

    public function test_admin_downloads_leaderboard_and_competition_standings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $club  = $this->makeClub(['name' => 'Admin CSV FC', 'slug' => 'admin-csv-fc'], User::factory()->create());
        $this->seedLeaderboard($club);
        $competition = $this->seedCompetitionStandings($club);

        $this->actingAs($admin)->get("/admin/clubs/{$club->id}?tab=overview")->assertOk()
            ->assertSee("/admin/clubs/{$club->id}/leaderboard.csv");
        $this->actingAs($admin)->get("/admin/clubs/{$club->id}/competitions/{$competition->id}?tab=standings")->assertOk()
            ->assertSee("/admin/clubs/{$club->id}/competitions/{$competition->id}/standings.csv")->assertSee('Download CSV');

        $lb = $this->actingAs($admin)->get("/admin/clubs/{$club->id}/leaderboard.csv")->assertOk();
        $this->assertSame('attachment; filename=admin-csv-fc-leaderboard-' . now()->format('Ymd') . '.csv', $lb->headers->get('Content-Disposition'));
        $rows = $this->csvRows($lb->streamedContent());
        $this->assertSame(['rank', 'username', 'total_score', 'packs_completed', 'attempts_used'], $rows[0]);
        $this->assertSame("'=HYPERLINK(\"http://x\")", $rows[2][1]);
        $this->assertStringNotContainsString('secret-mail.test', $lb->streamedContent());

        $st = $this->actingAs($admin)->get("/admin/clubs/{$club->id}/competitions/{$competition->id}/standings.csv")->assertOk();
        $this->assertCount(4, $this->csvRows($st->streamedContent()));
        $this->assertStringNotContainsString('secret-mail.test', $st->streamedContent());

        $events = ClubAuditEvent::where('action', 'export.downloaded')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame([ClubAuditEvent::ACTOR_PLATFORM_ADMIN, ClubAuditEvent::ACTOR_PLATFORM_ADMIN], $events->pluck('actor_kind')->all());
        $this->assertSame(['leaderboard', 'competition'], $events->pluck('metadata.export')->all());
    }

    public function test_closed_to_non_admins_and_scoped_to_the_club(): void
    {
        $owner = User::factory()->create();
        $club  = $this->makeClub([], $owner);
        $other = $this->makeClub([], User::factory()->create());
        $competition = $this->seedCompetitionStandings($other);

        $this->get("/admin/clubs/{$club->id}/leaderboard.csv")->assertRedirect('/admin/login');
        $this->actingAs($owner)->get("/admin/clubs/{$club->id}/leaderboard.csv")->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get("/admin/clubs/{$club->id}/competitions/{$competition->id}/standings.csv")->assertNotFound();
        $this->assertSame(0, ClubAuditEvent::where('action', 'export.downloaded')->count());
    }
}
