<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BetaCodeTest extends TestCase
{
    use RefreshDatabase;

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name'           => 'Beta Tester',
            'username'       => 'betatester',
            'email'          => 'beta@example.com',
            'password'       => 'password123',
            'terms_accepted' => true,
            'age_confirmed'  => true,
        ], $overrides);
    }

    public function test_registration_is_open_when_no_beta_code_configured(): void
    {
        config(['ballspot.beta_code' => null]);

        $this->postJson('/api/register', $this->registerPayload())->assertStatus(201);
    }

    public function test_registration_requires_beta_code_when_configured(): void
    {
        config(['ballspot.beta_code' => 'FRIENDS2026']);

        $this->postJson('/api/register', $this->registerPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('beta_code');
    }

    public function test_wrong_beta_code_is_rejected_without_echoing_the_expected_code(): void
    {
        config(['ballspot.beta_code' => 'FRIENDS2026']);

        $response = $this->postJson('/api/register', $this->registerPayload(['beta_code' => 'NOPE']));

        $response->assertStatus(422)->assertJsonValidationErrors('beta_code');
        $this->assertStringNotContainsString('FRIENDS2026', $response->getContent());
    }

    public function test_correct_beta_code_registers_case_insensitively(): void
    {
        config(['ballspot.beta_code' => 'FRIENDS2026']);

        $this->postJson('/api/register', $this->registerPayload(['beta_code' => 'friends2026']))
            ->assertStatus(201);
    }
}
