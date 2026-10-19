<?php

namespace Tests\Feature\Clubs\Portal;

use Illuminate\Support\Arr;

/** Portal strings exist in all 5 languages and follow the user's preferred_language. */
class PortalLocalizationTest extends PortalTestCase
{
    public function test_every_language_has_the_same_keys(): void
    {
        $en = array_keys(Arr::dot(require lang_path('en/club_portal.php')));
        sort($en);

        foreach (['nl', 'fr', 'de', 'es'] as $locale) {
            $keys = array_keys(Arr::dot(require lang_path($locale . '/club_portal.php')));
            sort($keys);
            $this->assertSame($en, $keys, "lang/{$locale}/club_portal.php keys differ from en");
        }
    }

    public function test_dutch_user_sees_dutch_pages(): void
    {
        $this->owner->forceFill(['preferred_language' => 'nl'])->save();

        $this->portalAs($this->owner)->get($this->url())->assertOk()
            ->assertSee('Overzicht')->assertSee('Leden')->assertSee('Uitnodigingscodes')->assertSee('Afmelden')
            ->assertDontSee('Invite codes');
        $this->portalAs($this->owner)->get($this->url('/members'))->assertOk()->assertSee('Zoek op gebruikersnaam');
        $this->portalAs($this->owner)->get($this->url('/settings'))->assertOk()->assertSee('Wie ziet de ledenlijst in de app');
        $this->portalAs($this->owner)->get($this->url('/competitions'))->assertOk()->assertSee('Nieuwe competitie');
    }

    public function test_login_page_follows_accept_language(): void
    {
        $this->withHeader('Accept-Language', 'fr-BE,fr;q=0.9')->get('/club/login')->assertOk()
            ->assertSee('Connexion au Portail du club');
        $this->withHeader('Accept-Language', 'de')->get('/club/login')->assertOk()
            ->assertSee('Beim Vereinsportal anmelden');
    }
}
