<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/config — public, non-sensitive flags the app needs before login
 * (is the beta gate on? is email verification required?). It must never carry
 * the beta code itself or any secret.
 */
class AppConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_reports_the_beta_gate_off_when_no_code_is_set(): void
    {
        config(['ballspot.beta_code' => null]);

        $this->getJson('/api/config')
            ->assertOk()
            ->assertJsonPath('beta_gate', false)
            ->assertJsonPath('email_verification_required', true)
            ->assertJsonStructure(['beta_gate', 'email_verification_required', 'minimum_age', 'app_name']);
    }

    public function test_config_reports_the_beta_gate_on_without_exposing_the_code(): void
    {
        config(['ballspot.beta_code' => 'SUPERSECRET2026']);

        $res = $this->getJson('/api/config');

        $res->assertOk()->assertJsonPath('beta_gate', true);
        $this->assertStringNotContainsStringIgnoringCase('SUPERSECRET2026', $res->getContent());
    }

    public function test_config_never_contains_secrets(): void
    {
        config(['ballspot.beta_code' => 'SUPERSECRET2026']);

        $body = strtolower($this->getJson('/api/config')->getContent());

        foreach (['app_key', 'db_password', 'mail_password', 'beta_code', 'supersecret', 'base64:'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_health_never_contains_secrets(): void
    {
        config(['ballspot.beta_code' => 'SUPERSECRET2026']);

        $body = strtolower($this->getJson('/api/health')->getContent());

        foreach (['app_key', 'db_password', 'mail_password', 'beta', 'supersecret', 'base64:'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_registration_without_beta_code_succeeds_when_gate_is_off(): void
    {
        config(['ballspot.beta_code' => null]);

        $this->postJson('/api/register', [
            'name' => 'Open Launch', 'username' => 'openlaunch', 'email' => 'open@example.com',
            'password' => 'password123', 'terms_accepted' => true, 'age_confirmed' => true,
        ])->assertStatus(201);
    }

    public function test_beta_code_sent_while_gate_is_off_is_ignored(): void
    {
        config(['ballspot.beta_code' => null]);

        $this->postJson('/api/register', [
            'name' => 'Open Launch', 'username' => 'openlaunch', 'email' => 'open@example.com',
            'password' => 'password123', 'terms_accepted' => true, 'age_confirmed' => true,
            'beta_code' => 'WHATEVER',
        ])->assertStatus(201);
    }
}
