<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DownloadPageTest extends TestCase
{
    private const IOS     = 'https://apps.apple.com/be/app/ballpicker-sports/id6796491992';
    private const ANDROID = 'https://play.google.com/store/apps/details?id=be.vanmalderstudio.ballpicker';

    public static function iosAgents(): array
    {
        return [
            'iPhone Safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1'],
            'iPad mobile mode' => ['Mozilla/5.0 (iPad; CPU OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1'],
            'iPod' => ['Mozilla/5.0 (iPod touch; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148'],
            'Instagram in-app browser' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 350.0.0'],
        ];
    }

    public static function androidAgents(): array
    {
        return [
            'Chrome' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36'],
            'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 13; SAMSUNG SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/24.0 Chrome/117.0 Mobile Safari/537.36'],
            'Cubot phone is no bot' => ['Mozilla/5.0 (Linux; Android 11; CUBOT X50) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36'],
        ];
    }

    public static function pageAgents(): array
    {
        return [
            'no user agent' => [''],
            'Windows Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'],
            'Mac Safari' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15'],
            'Linux Firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0'],
            'Windows Phone' => ['Mozilla/5.0 (Windows Phone 10.0; Android 6.0.1; Microsoft; Lumia 950) AppleWebKit/537.36 Chrome/52.0 Mobile Safari/537.36 Edge/15.15063'],
            'WhatsApp Android preview' => ['WhatsApp/2.24.6.77 A'],
            'WhatsApp iOS preview' => ['WhatsApp/2.24.6.77 i'],
            'Facebook' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'],
            'iMessage preview' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/601.2.4 (KHTML, like Gecko) facebookexternalhit/1.1 Facebot Twitterbot/1.0'],
            'X' => ['Twitterbot/1.0'],
            'Telegram' => ['TelegramBot (like TwitterBot)'],
            'LinkedIn' => ['LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)'],
            'Slack' => ['Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)'],
            'Discord' => ['Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)'],
            'Googlebot Smartphone' => ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
        ];
    }

    #[DataProvider('iosAgents')]
    public function test_ios_devices_go_to_the_app_store(string $agent): void
    {
        $this->assertTemporaryRedirect($agent, self::IOS);
    }

    #[DataProvider('androidAgents')]
    public function test_android_devices_go_to_google_play(string $agent): void
    {
        $this->assertTemporaryRedirect($agent, self::ANDROID);
    }

    #[DataProvider('pageAgents')]
    public function test_unknown_devices_and_preview_bots_get_the_page(string $agent): void
    {
        $response = $this->withHeader('User-Agent', $agent)->get('/download');

        $response->assertOk()
            ->assertSee('href="'.self::IOS.'"', false)
            ->assertSee('href="'.self::ANDROID.'"', false)
            ->assertSee('property="og:image"', false);
        $this->assertStringContainsString('User-Agent', (string) $response->headers->get('Vary'));
    }

    public function test_the_page_redirects_ipados_that_identifies_as_a_mac(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)')->get('/download')
            ->assertSee('navigator.maxTouchPoints > 1', false)
            ->assertSee('location.replace('.json_encode(self::IOS).')', false);
    }

    public function test_the_landing_page_replaced_the_laravel_page(): void
    {
        // Even on a phone: `/` is what older app builds share, and it never redirects.
        $this->withHeader('User-Agent', self::iosAgents()['iPhone Safari'][0])->get('/')
            ->assertOk()
            ->assertSee('BallPicker')
            ->assertSee('href="'.self::IOS.'"', false)
            ->assertSee('href="'.self::ANDROID.'"', false)
            ->assertSee('/privacy')
            ->assertDontSee('Laravel');
    }

    public function test_open_graph_tags_use_absolute_urls_on_both_pages(): void
    {
        config(['ballspot.web_url' => 'https://ballpicker.example/']);

        foreach (['/' => 'https://ballpicker.example', '/download' => 'https://ballpicker.example/download'] as $path => $url) {
            $this->withHeader('User-Agent', 'WhatsApp/2.24.6.77 A')->get($path)->assertOk()
                ->assertSee('<meta property="og:url" content="'.$url.'">', false)
                ->assertSee('<meta property="og:title" content="BallPicker: find the ball, beat your friends">', false)
                ->assertSee('<meta property="og:image" content="https://ballpicker.example/images/og-image.jpg">', false)
                ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
        }
    }

    public function test_the_preview_image_fits_whatsapp_and_social_cards(): void
    {
        $path = public_path('images/og-image.jpg');

        [$width, $height] = getimagesize($path);
        $this->assertSame([1200, 630], [$width, $height]);
        $this->assertLessThan(300 * 1024, filesize($path), 'WhatsApp can drop larger preview images');
    }

    public function test_every_image_the_pages_use_exists(): void
    {
        foreach (['/', '/download'] as $path) {
            preg_match_all('#/images/[\w.-]+#', $this->get($path)->getContent(), $matches);
            $this->assertNotEmpty($matches[0]);
            foreach (array_unique($matches[0]) as $image) {
                $this->assertFileExists(public_path($image));
            }
        }
    }

    public function test_the_pages_follow_the_visitor_language(): void
    {
        $this->withHeader('Accept-Language', 'nl-BE,nl;q=0.9')->get('/')
            ->assertSee('<html lang="nl">', false)
            ->assertSee('Vind de bal. Versla je vrienden.');
        $this->get('/download?lang=fr')->assertSee('Télécharge BallPicker');
    }

    public function test_shared_links_create_no_session_cookie_and_no_database_work(): void
    {
        DB::enableQueryLog();

        foreach (['/', '/download'] as $path) {
            $this->assertSame([], $this->get($path)->assertOk()->headers->getCookies());
        }
        $this->assertSame([], $this->withHeader('User-Agent', 'iPhone')->get('/download')->headers->getCookies());

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_the_security_headers_still_apply(): void
    {
        $this->get('/download')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_neighbouring_routes_keep_working(): void
    {
        $this->get('/privacy')->assertOk()->assertCookie(config('session.cookie'));
        $this->get('/up')->assertOk();
        $this->getJson('/api/me')->assertUnauthorized()->assertJsonPath('code', 'session_invalid');
        $this->get('/admin/login')->assertOk();
        $this->post('/forgot-password', [])->assertRedirect();
    }

    private function assertTemporaryRedirect(string $agent, string $target): void
    {
        $response = $this->withHeader('User-Agent', $agent)->get('/download');

        $response->assertStatus(302)->assertRedirect($target);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('User-Agent', (string) $response->headers->get('Vary'));
    }
}
