<?php

namespace Tests\Feature\Clubs\Admin;

use App\Models\ChallengePack;
use App\Models\ClubCompetition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * Plan §5.7: a club pack linked to a published, not yet finalized/cancelled/
 * voided competition cannot be archived, drafted or hidden from the admin —
 * neither via the quick status action nor via the edit form.
 */
class AdminClubPackCompetitionGuardTest extends TestCase
{
    use CreatesClubs, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_quick_status_archive_is_refused_while_a_published_competition_links_the_pack(): void
    {
        $club = $this->makeClub();
        $pack = $this->makeClubPack($club);
        $this->makeCompetition($club, [$pack], ['state' => 'published', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(8)]);

        $this->actingAs($this->admin)
            ->from('/admin/packs?club=' . $club->id)
            ->post('/admin/packs/' . $pack->id . '/status', ['status' => 'archived'])
            ->assertRedirect()
            ->assertSessionHas('error', __('clubs.errors.pack_in_competition'));

        $this->assertSame(ChallengePack::STATUS_ACTIVE, $pack->fresh()->status);

        $this->actingAs($this->admin)
            ->post('/admin/packs/' . $pack->id . '/status', ['visibility' => 'hidden'])
            ->assertSessionHas('error');
        $this->assertSame(ChallengePack::VISIBILITY_PUBLIC, $pack->fresh()->visibility);
    }

    public function test_edit_form_cannot_draft_a_pack_in_a_live_competition(): void
    {
        $club = $this->makeClub();
        $pack = $this->makeClubPack($club);
        ClubCompetition::factory()->live()->create(['club_id' => $club->id])
            ->competitionPacks()->create(['challenge_pack_id' => $pack->id, 'sort_order' => 0]);

        $this->actingAs($this->admin)
            ->from('/admin/packs/' . $pack->id . '/edit')
            ->put('/admin/packs/' . $pack->id, [
                'name'       => $pack->name,
                'status'     => 'draft',
                'visibility' => 'public',
            ])
            ->assertSessionHas('error', __('clubs.errors.pack_in_competition'));

        $this->assertSame(ChallengePack::STATUS_ACTIVE, $pack->fresh()->status);
    }

    public function test_archiving_is_allowed_once_the_competition_is_finalized_or_only_drafted(): void
    {
        $club      = $this->makeClub();
        $finalized = $this->makeClubPack($club);
        $drafted   = $this->makeClubPack($club);
        ClubCompetition::factory()->finalized()->create(['club_id' => $club->id])
            ->competitionPacks()->create(['challenge_pack_id' => $finalized->id, 'sort_order' => 0]);
        $this->makeCompetition($club, [$drafted]);

        foreach ([$finalized, $drafted] as $pack) {
            $this->actingAs($this->admin)
                ->post('/admin/packs/' . $pack->id . '/status', ['status' => 'archived'])
                ->assertSessionMissing('error');
            $this->assertSame(ChallengePack::STATUS_ARCHIVED, $pack->fresh()->status);
        }
    }
}
