<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'SAMEORIGIN',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        'Permissions-Policy'     => 'camera=(), geolocation=(), microphone=()',
    ];

    public function test_api_responses_carry_security_headers(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk();
        foreach (self::EXPECTED as $header => $value) {
            $response->assertHeader($header, $value);
        }
    }

    public function test_public_web_pages_carry_security_headers(): void
    {
        $response = $this->get('/privacy');

        $response->assertOk();
        foreach (self::EXPECTED as $header => $value) {
            $response->assertHeader($header, $value);
        }
    }
}
