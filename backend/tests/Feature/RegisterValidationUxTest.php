<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AppLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * v1.9.7 — registration errors must be specific, field-bound and carry a
 * stable `code` the app maps to friendly copy. Logs carry field names only.
 */
class RegisterValidationUxTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $records;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ballspot.beta_code' => null]);
        $this->records = new TestHandler();
        Log::channel(AppLog::CHANNEL)->getLogger()->setHandlers([$this->records]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Player', 'username' => 'newplayer', 'email' => 'new@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'terms_accepted' => true, 'age_confirmed' => true,
        ], $overrides);
    }

    private function logged(string $message): array
    {
        return array_values(array_filter($this->records->getRecords(), fn ($r) => $r->message === $message));
    }

    public function test_existing_email_returns_a_specific_field_error_with_a_code(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $res = $this->postJson('/api/register', $this->payload(['email' => 'taken@example.com']));

        $res->assertStatus(422)
            ->assertJsonPath('code', 'email_taken')
            ->assertJsonPath('codes.email', 'email_taken')
            ->assertJsonPath('errors.email.0', 'An account with this email already exists. Please log in or reset your password.')
            ->assertJsonPath('message', 'An account with this email already exists. Please log in or reset your password.');
        $this->assertStringNotContainsStringIgnoringCase('sqlstate', $res->getContent());
    }

    public function test_existing_username_returns_a_specific_field_error_with_a_code(): void
    {
        User::factory()->create(['username' => 'takenname']);

        $this->postJson('/api/register', $this->payload(['username' => 'takenname']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'username_taken')
            ->assertJsonPath('codes.username', 'username_taken')
            ->assertJsonPath('errors.username.0', 'This username is already taken.');
    }

    public function test_password_confirmation_mismatch_is_rejected_with_a_clear_message(): void
    {
        $this->postJson('/api/register', $this->payload(['password_confirmation' => 'different123']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'password_mismatch')
            ->assertJsonPath('codes.password', 'password_mismatch')
            ->assertJsonPath('errors.password.0', 'Passwords do not match.');

        $this->assertSame(0, User::count());
    }

    public function test_matching_confirmation_registers(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
        $this->assertSame(1, User::count());
    }

    public function test_confirmation_is_required_when_the_config_flag_is_on(): void
    {
        config(['ballspot.auth.require_password_confirmation' => true]);

        $payload = $this->payload();
        unset($payload['password_confirmation']);

        $this->postJson('/api/register', $payload)
            ->assertStatus(422)
            ->assertJsonPath('codes.password', 'password_mismatch');
    }

    public function test_confirmation_is_optional_for_older_app_builds_while_the_flag_is_off(): void
    {
        config(['ballspot.auth.require_password_confirmation' => false]);

        $payload = $this->payload();
        unset($payload['password_confirmation']);

        $this->postJson('/api/register', $payload)->assertStatus(201);
    }

    public function test_validation_failure_logs_field_names_only(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/register', $this->payload([
            'email' => 'taken@example.com', 'password_confirmation' => 'nope-nope-1',
        ]))->assertStatus(422);

        $events = $this->logged('register.validation_failed');
        $this->assertCount(1, $events);
        $ctx = $events[0]->context;
        $this->assertEqualsCanonicalizing(['email', 'password'], $ctx['fields']);
        $this->assertEqualsCanonicalizing(['email_taken', 'password_mismatch'], $ctx['codes']);

        $dump = json_encode(array_map(fn ($r) => [$r->message, $r->context], $this->records->getRecords()));
        $this->assertStringNotContainsString('taken@example.com', $dump);
        $this->assertStringNotContainsString('password123', $dump);
        $this->assertStringNotContainsString('nope-nope-1', $dump);
    }

    public function test_multiple_field_errors_are_all_returned_with_their_codes(): void
    {
        User::factory()->create(['email' => 'taken@example.com', 'username' => 'takenname']);

        $res = $this->postJson('/api/register', $this->payload(['email' => 'taken@example.com', 'username' => 'takenname']));

        $res->assertStatus(422)
            ->assertJsonPath('codes.email', 'email_taken')
            ->assertJsonPath('codes.username', 'username_taken');
    }

    public function test_generic_field_failures_still_use_the_laravel_errors_shape(): void
    {
        $this->postJson('/api/register', $this->payload(['email' => 'not-an-email']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('codes.email', 'email_invalid');
    }
}
