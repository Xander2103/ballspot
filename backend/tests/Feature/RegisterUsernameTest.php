<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegisterUsernameTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $username, string $email = 'new@example.test')
    {
        return $this->postJson('/api/register', [
            'name' => 'Jan Peeters', 'username' => $username, 'email' => $email,
            'password' => 'password123', 'password_confirmation' => 'password123',
            'terms_accepted' => true, 'age_confirmed' => true,
        ]);
    }

    public function test_a_username_differing_only_in_case_counts_as_taken(): void
    {
        User::factory()->create(['username' => 'Xander']);

        $this->register('XANDER')->assertStatus(422)
            ->assertJsonPath('codes.username', 'username_taken')
            ->assertJsonPath('code', 'username_taken');
        $this->assertSame(1, User::whereRaw('LOWER(username) = ?', ['xander'])->count());
    }

    public function test_the_chosen_case_is_stored_and_whitespace_and_leading_at_are_removed(): void
    {
        $this->register('  @XanderVM ')->assertStatus(201);

        $this->assertDatabaseHas('users', ['username' => 'XanderVM']);
    }

    #[DataProvider('rejectedUsernames')]
    public function test_unsafe_usernames_are_refused_with_a_stable_code(string $username): void
    {
        $this->register($username)->assertStatus(422)->assertJsonPath('codes.username', 'username_invalid');
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public static function rejectedUsernames(): array
    {
        return [
            'email address'       => ['someone@example.test'],
            'space'               => ['Jan Peeters'],
            'zero-width joiner'   => ["sam\u{200D}my"],
            'deleted placeholder' => ['deleted-12'],
            'leading dot'         => ['.sam'],
        ];
    }

    public function test_letters_from_other_alphabets_and_separators_are_allowed(): void
    {
        foreach (['José', 'jan_p', 'a.b-c', 'Łukasz7'] as $i => $username) {
            $this->register($username, "user{$i}@example.test")->assertStatus(201);
        }
    }

    public function test_existing_accounts_with_older_usernames_keep_working(): void
    {
        $legacy = User::factory()->create(['username' => 'Old Style Name', 'email' => 'old@example.test', 'password' => 'password123']);

        $this->postJson('/api/login', ['email' => 'old@example.test', 'password' => 'password123'])->assertOk();
        $this->assertSame('Old Style Name', $legacy->fresh()->username);
    }

    public function test_the_audit_command_reports_ids_only_and_changes_nothing(): void
    {
        $bad = User::factory()->create(['username' => 'first.last@example.test', 'name' => 'Private Person', 'email' => 'private@example.test']);
        User::factory()->create(['username' => 'Same', 'name' => 'A']);
        $before = User::orderBy('id')->get(['id', 'username', 'name', 'email'])->toArray();

        $this->artisan('ballspot:audit-usernames')
            ->expectsOutputToContain("email_shaped: 1 (ids: {$bad->id})")
            ->doesntExpectOutputToContain('Private Person')
            ->doesntExpectOutputToContain('first.last@example.test')
            ->assertSuccessful();

        $this->assertSame($before, User::orderBy('id')->get(['id', 'username', 'name', 'email'])->toArray());
    }
}
