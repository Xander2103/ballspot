<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GDPR accountability: acceptance of the Terms/Privacy Policy and the minimum
 * age must be enforced and recorded server-side, and the served legal pages
 * must describe what the code actually does.
 */
class ConsentAndLegalTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'           => 'Consent Tester',
            'username'       => 'consenttester',
            'email'          => 'consent@example.com',
            'password'       => 'password123',
            'terms_accepted' => true,
            'age_confirmed'  => true,
        ], $overrides);
    }

    public function test_registration_requires_terms_acceptance(): void
    {
        $res = $this->postJson('/api/register', $this->payload(['terms_accepted' => false]));

        $res->assertStatus(422)->assertJsonValidationErrors('terms_accepted');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_requires_age_confirmation(): void
    {
        $res = $this->postJson('/api/register', $this->payload(['age_confirmed' => false]));

        $res->assertStatus(422)->assertJsonValidationErrors('age_confirmed');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_without_consent_fields_is_rejected(): void
    {
        $payload = $this->payload();
        unset($payload['terms_accepted'], $payload['age_confirmed']);

        $this->postJson('/api/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['terms_accepted', 'age_confirmed']);
    }

    public function test_accepted_consent_is_recorded_with_a_version(): void
    {
        $this->postJson('/api/register', $this->payload())->assertCreated();

        $user = User::where('email', 'consent@example.com')->firstOrFail();
        $this->assertNotNull($user->terms_accepted_at, 'consent timestamp must be stored');
        $this->assertNotEmpty($user->terms_version, 'policy version must be stamped');
    }

    public function test_consent_fields_are_not_mass_assignable(): void
    {
        // A client must not be able to back-date its own consent record.
        $spoofed = $this->payload([
            'email'             => 'spoof@example.com',
            'username'          => 'spoofer',
            'terms_accepted_at' => '2000-01-01 00:00:00',
        ]);

        $this->postJson('/api/register', $spoofed)->assertCreated();

        $user = User::where('email', 'spoof@example.com')->firstOrFail();
        $this->assertNotSame('2000-01-01 00:00:00', (string) $user->terms_accepted_at);
    }

    public function test_export_includes_the_consent_record_but_no_secrets(): void
    {
        $this->postJson('/api/register', $this->payload())->assertCreated();
        $user = User::where('email', 'consent@example.com')->firstOrFail();
        $user->markEmailAsVerified();
        $token = $user->createToken('test')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/me/export')->assertOk();

        $this->assertNotNull($res->json('account.terms_accepted_at'));
        $this->assertNotNull($res->json('account.terms_version'));
        $this->assertStringNotContainsString($user->password, $res->getContent());
    }

    // ---------------------------------------------------------------
    // Served legal pages.
    // ---------------------------------------------------------------

    public function test_privacy_page_states_the_minimum_age(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertSee((string) config('ballspot.legal.minimum_age'), false);
    }

    public function test_privacy_page_discloses_push_tokens_and_friends_data(): void
    {
        $res = $this->get('/privacy')->assertOk();

        $res->assertSee('push notification token', false);
        $res->assertSee('friend code', false);
    }

    public function test_privacy_page_is_honest_that_deletion_anonymises(): void
    {
        $res = $this->get('/privacy')->assertOk();

        $res->assertSee('anonymised', false);
        // The old page claimed data was never shared with other players, which
        // the public-profile endpoint contradicts.
        $res->assertDontSee('We do not share your data with other users beyond your display name', false);
    }

    public function test_terms_page_covers_objectionable_content_and_age(): void
    {
        $res = $this->get('/terms')->assertOk();

        $res->assertSee('objectionable', false);
        $res->assertSee((string) config('ballspot.legal.minimum_age'), false);
    }

    public function test_legal_pages_are_publicly_reachable_without_auth(): void
    {
        foreach (['/privacy', '/terms', '/support'] as $path) {
            $this->get($path)->assertOk();
        }
    }
}
