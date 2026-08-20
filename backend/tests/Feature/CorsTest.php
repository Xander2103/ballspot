<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Locked-down CORS behaviour. fruitcake/php-cors short-circuits a single
 * literal allowed origin by stamping it on every response — even when the
 * request's Origin is disallowed or absent. config/cors.php therefore maps
 * non-wildcard origins to exact-match patterns, which keeps the library on
 * its dynamic branch: the Access-Control-Allow-Origin header is only emitted
 * for an Origin that actually matches.
 */
class CorsTest extends TestCase
{
    private function usePatternConfig(): void
    {
        config([
            'cors.allowed_origins'          => [],
            'cors.allowed_origins_patterns' => ['#^https://ballpicker\.example$#i'],
        ]);
    }

    public function test_allowed_origin_receives_matching_cors_header(): void
    {
        $this->usePatternConfig();

        $response = $this->get('/api/health', ['Origin' => 'https://ballpicker.example']);

        $response->assertOk();
        $this->assertSame(
            'https://ballpicker.example',
            $response->headers->get('Access-Control-Allow-Origin')
        );
    }

    public function test_disallowed_origin_receives_no_cors_header(): void
    {
        $this->usePatternConfig();

        $response = $this->get('/api/health', ['Origin' => 'https://evil.example.com']);

        $response->assertOk();
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_request_without_origin_receives_no_cors_header(): void
    {
        $this->usePatternConfig();

        $response = $this->get('/api/health');

        $response->assertOk();
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_config_maps_single_origin_to_exact_match_pattern(): void
    {
        $config = $this->loadCorsConfig('https://ballpicker.example');

        $this->assertSame([], $config['allowed_origins']);
        $this->assertCount(1, $config['allowed_origins_patterns']);

        $pattern = $config['allowed_origins_patterns'][0];
        $this->assertSame(1, preg_match($pattern, 'https://ballpicker.example'));
        $this->assertSame(0, preg_match($pattern, 'https://evil.example.com'));
        // No unanchored substring matches either.
        $this->assertSame(0, preg_match($pattern, 'https://ballpicker.example.evil.com'));
    }

    public function test_config_keeps_wildcard_for_local_development(): void
    {
        $config = $this->loadCorsConfig('*');

        $this->assertSame(['*'], $config['allowed_origins']);
        $this->assertSame([], $config['allowed_origins_patterns']);
    }

    /**
     * Re-evaluate config/cors.php with a given CORS_ALLOWED_ORIGINS value.
     */
    private function loadCorsConfig(string $origins): array
    {
        putenv("CORS_ALLOWED_ORIGINS={$origins}");
        $_ENV['CORS_ALLOWED_ORIGINS'] = $origins;

        try {
            return require base_path('config/cors.php');
        } finally {
            putenv('CORS_ALLOWED_ORIGINS');
            unset($_ENV['CORS_ALLOWED_ORIGINS']);
        }
    }
}
