<?php

namespace Tests\Feature\Clubs\Portal;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Models\ClubPortalLoginCode;
use App\Models\User;
use App\Notifications\ClubPortalLoginCodeNotification;
use App\Services\ClubPortal\ClubPortalSession;
use App\Support\FailedLoginLimiter;
use Illuminate\Support\Facades\Notification;

/**
 * Club portal sign-in: password → mandatory emailed 6-digit code → portal
 * session. Wrong / expired / locked codes, resend cooldown and email
 * ceiling, generic refusal of non-club-admins, session fixation, idle
 * timeout, logout.
 */
class PortalAuthTest extends PortalTestCase
{
    private const PASSWORD = 'password';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function postLogin(string $email = 'owner@example.test', string $password = self::PASSWORD)
    {
        return $this->from('/club/login')->post('/club/login', ['email' => $email, 'password' => $password]);
    }

    /** The plain code from the last email sent to the user. */
    private function sentCode(User $user): string
    {
        $code = null;
        Notification::assertSentTo($user, ClubPortalLoginCodeNotification::class, function (ClubPortalLoginCodeNotification $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return (string) $code;
    }

    private function wrongCode(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    public function test_login_page_renders(): void
    {
        $this->get('/club/login')->assertOk()
            ->assertSee('Sign in to the Club Portal')
            ->assertSee('BallPicker Club Portal');
    }

    public function test_full_sign_in_with_emailed_code(): void
    {
        $this->postLogin()->assertRedirect('/club/verify');
        $this->assertGuest();
        $this->assertNotNull(session(ClubPortalSession::PENDING));

        $code = $this->sentCode($this->owner);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $row = ClubPortalLoginCode::where('user_id', $this->owner->id)->firstOrFail();
        $this->assertNotSame($code, $row->code_hash, 'only a hash is stored');

        $this->get('/club/verify')->assertOk()->assertSee('o•••@example.test');

        // One club → straight to its dashboard.
        $this->post('/club/verify', ['code' => substr($code, 0, 3) . ' ' . substr($code, 3)])
            ->assertRedirect(route('club-portal.home'));
        $this->assertAuthenticatedAs($this->owner);
        $this->assertSame($this->owner->id, session(ClubPortalSession::VERIFIED_USER));
        $this->assertNull(session(ClubPortalSession::PENDING));
        $this->assertSame(0, ClubPortalLoginCode::count(), 'a used code is deleted');

        $this->get('/club')->assertRedirect($this->url());
        $this->get($this->url())->assertOk()->assertSee('Portal FC');
    }

    public function test_session_is_regenerated_on_password_step_and_on_code(): void
    {
        $this->get('/club/login');
        $initial = session()->getId();

        $this->postLogin();
        $afterPassword = session()->getId();
        $this->assertNotSame($initial, $afterPassword);

        $this->post('/club/verify', ['code' => $this->sentCode($this->owner)]);
        $this->assertNotSame($afterPassword, session()->getId());
        $this->assertAuthenticatedAs($this->owner);
    }

    public function test_wrong_code_is_refused_and_counted(): void
    {
        $this->postLogin();
        $code = $this->sentCode($this->owner);

        $this->from('/club/verify')->post('/club/verify', ['code' => $this->wrongCode($code)])
            ->assertRedirect('/club/verify')
            ->assertSessionHasErrors(['code' => 'That code is not correct.']);
        $this->assertGuest();
        $this->assertSame(1, ClubPortalLoginCode::firstOrFail()->attempts);

        $this->from('/club/verify')->post('/club/verify', ['code' => '12ab'])->assertSessionHasErrors('code');
    }

    public function test_code_locks_after_max_attempts_and_needs_a_new_sign_in(): void
    {
        config(['ballspot.clubs.portal.code_max_attempts' => 3]);
        $this->postLogin();
        $code = $this->sentCode($this->owner);

        $this->from('/club/verify')->post('/club/verify', ['code' => $this->wrongCode($code)]);
        $this->from('/club/verify')->post('/club/verify', ['code' => $this->wrongCode($code)]);
        $this->from('/club/verify')->post('/club/verify', ['code' => $this->wrongCode($code)])
            ->assertRedirect('/club/login')
            ->assertSessionHas('error', 'Too many wrong codes. Please sign in again.');

        $this->assertNull(session(ClubPortalSession::PENDING));
        $this->assertSame(0, ClubPortalLoginCode::count());

        // The right code is useless now.
        $this->post('/club/verify', ['code' => $code])->assertRedirect('/club/login');
        $this->assertGuest();
    }

    public function test_expired_code_is_refused(): void
    {
        $this->postLogin();
        $code = $this->sentCode($this->owner);

        $this->travel(11)->minutes();

        $this->from('/club/verify')->post('/club/verify', ['code' => $code])
            ->assertSessionHasErrors(['code' => 'This code has expired. Request a new one.']);
        $this->assertGuest();
    }

    public function test_resend_has_a_cooldown_and_replaces_the_code(): void
    {
        $this->postLogin();
        $first = $this->sentCode($this->owner);

        $this->post('/club/verify/resend')->assertRedirect('/club/verify')->assertSessionHas('error');
        Notification::assertSentToTimes($this->owner, ClubPortalLoginCodeNotification::class, 1);

        $this->travel(61)->seconds();
        $this->post('/club/verify/resend')->assertRedirect('/club/verify')->assertSessionHas('success');
        Notification::assertSentToTimes($this->owner, ClubPortalLoginCodeNotification::class, 2);

        $codes = [];
        Notification::assertSentTo($this->owner, ClubPortalLoginCodeNotification::class, function ($n) use (&$codes) {
            $codes[] = $n->code;

            return true;
        });
        $second = end($codes);

        if ($first !== $second) {
            $this->from('/club/verify')->post('/club/verify', ['code' => $first])->assertSessionHasErrors('code');
        }
        $this->post('/club/verify', ['code' => $second])->assertRedirect();
        $this->assertAuthenticatedAs($this->owner);
    }

    public function test_hourly_email_ceiling_per_address(): void
    {
        config(['ballspot.clubs.portal.code_emails_per_hour' => 2]);

        $this->postLogin()->assertRedirect('/club/verify');
        $this->postLogin()->assertRedirect('/club/verify');
        $this->postLogin()->assertRedirect('/club/login')->assertSessionHasErrors('email');

        Notification::assertSentToTimes($this->owner, ClubPortalLoginCodeNotification::class, 2);
    }

    public function test_wrong_password_and_unknown_account_get_the_generic_refusal(): void
    {
        $this->postLogin('owner@example.test', 'nope')->assertRedirect('/club/login')
            ->assertSessionHasErrors(['email' => 'These details are not valid for the Club Portal.']);
        $this->postLogin('nobody@example.test')->assertSessionHasErrors(['email' => 'These details are not valid for the Club Portal.']);

        Notification::assertNothingSent();
        $this->assertSame(2, FailedLoginLimiter::failures('club-portal', 'owner@example.test') + FailedLoginLimiter::failures('club-portal', 'nobody@example.test'));
    }

    public function test_member_only_and_outsiders_are_refused_generically(): void
    {
        $member   = $this->userWithRole(ClubRole::Member, null, ['email' => 'member@example.test']);
        $outsider = User::factory()->create(['email' => 'out@example.test']);
        $platform = User::factory()->create(['email' => 'platform@example.test', 'is_admin' => true]);
        $removed  = User::factory()->create(['email' => 'gone@example.test']);
        $this->addMember($this->club, $removed, ClubRole::Admin, ClubMemberStatus::Removed);

        foreach (['member@example.test', 'out@example.test', 'platform@example.test', 'gone@example.test'] as $email) {
            $this->postLogin($email)->assertRedirect('/club/login')
                ->assertSessionHasErrors(['email' => 'These details are not valid for the Club Portal.']);
        }

        Notification::assertNothingSent();
        $this->assertGuest();
        $this->assertSame(0, ClubPortalLoginCode::count());
        unset($member, $outsider, $platform);
    }

    public function test_admin_of_suspended_club_may_sign_in_but_archived_may_not(): void
    {
        $admin = $this->userWithRole(ClubRole::Admin, null, ['email' => 'admin@example.test']);
        $this->club->forceFill(['status' => 'suspended'])->save();
        $this->postLogin('admin@example.test')->assertRedirect('/club/verify');

        $this->club->forceFill(['status' => 'archived'])->save();
        $this->post('/club/logout');
        $this->postLogin('admin@example.test')->assertSessionHasErrors('email');
        unset($admin);
    }

    public function test_unverified_email_is_refused(): void
    {
        $this->owner->forceFill(['email_verified_at' => null])->save();

        $this->postLogin()->assertSessionHasErrors(['email' => 'These details are not valid for the Club Portal.']);
        Notification::assertNothingSent();
    }

    public function test_membership_ending_before_the_code_is_entered_refuses_the_sign_in(): void
    {
        $admin = $this->userWithRole(ClubRole::Admin, null, ['email' => 'admin@example.test']);
        $this->postLogin('admin@example.test');
        $code = $this->sentCode($admin);

        $this->addMember($this->club, $admin, ClubRole::Member);

        $this->post('/club/verify', ['code' => $code])->assertRedirect('/club/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_password_step_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postLogin('owner@example.test', 'wrong-' . $i)->assertStatus(302);
        }
        $this->postLogin('owner@example.test', 'wrong-x')->assertStatus(429);
    }

    public function test_failed_login_limiter_blocks_the_address(): void
    {
        for ($i = 0; $i < FailedLoginLimiter::MAX_FAILURES; $i++) {
            FailedLoginLimiter::recordFailure('club-portal', 'owner@example.test');
        }

        $this->postLogin()->assertStatus(429);
        Notification::assertNothingSent();
    }

    public function test_verify_without_pending_sign_in_goes_back_to_login(): void
    {
        $this->get('/club/verify')->assertRedirect('/club/login');
        $this->post('/club/verify', ['code' => '123456'])->assertRedirect('/club/login');
        $this->post('/club/verify/resend')->assertRedirect('/club/login');
    }

    public function test_code_can_be_switched_off(): void
    {
        config(['ballspot.clubs.portal.two_factor_required' => false]);

        $this->postLogin()->assertRedirect(route('club-portal.home'));
        $this->assertAuthenticatedAs($this->owner);
        Notification::assertNothingSent();
        $this->get($this->url())->assertOk();
    }

    public function test_web_session_from_elsewhere_is_not_a_portal_session(): void
    {
        // e.g. signed in on /admin with the same web guard.
        $this->actingAs($this->owner)->get($this->url())
            ->assertRedirect('/club/login')
            ->assertSessionHas('error', 'Enter the verification code we emailed you to continue.');
    }

    public function test_portal_session_of_another_user_does_not_carry_over(): void
    {
        $other = $this->userWithRole(ClubRole::Admin);
        $this->actingAs($other)->withSession([
            ClubPortalSession::VERIFIED_AT   => now()->getTimestamp(),
            ClubPortalSession::VERIFIED_USER => $this->owner->id,
            ClubPortalSession::LAST_SEEN     => now()->getTimestamp(),
        ])->get($this->url())->assertRedirect('/club/login');
    }

    public function test_idle_timeout_signs_out(): void
    {
        config(['ballspot.clubs.portal.idle_timeout_minutes' => 30]);
        $this->portalAs($this->owner)->get($this->url())->assertOk();

        $this->travel(31)->minutes();

        $this->get($this->url())->assertRedirect('/club/login')
            ->assertSessionHas('warning', 'You were signed out after a period of inactivity. Please sign in again.');
        $this->assertGuest();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->portalAs($this->owner)->get($this->url())->assertOk();

        $this->post('/club/logout')->assertRedirect('/club/login');
        $this->assertGuest();
        $this->get($this->url())->assertRedirect('/club/login');
    }

    public function test_intended_page_is_restored_after_sign_in(): void
    {
        $this->get($this->url('/members'))->assertRedirect('/club/login');

        $this->postLogin();
        $this->post('/club/verify', ['code' => $this->sentCode($this->owner)])
            ->assertRedirect(url($this->url('/members')));
    }

    public function test_code_email_is_localised(): void
    {
        $this->owner->forceFill(['preferred_language' => 'nl'])->save();
        $this->postLogin();

        Notification::assertSentTo($this->owner, ClubPortalLoginCodeNotification::class, function (ClubPortalLoginCodeNotification $n, $channels, $notifiable, $locale) {
            $previous = app()->getLocale();
            app()->setLocale((string) $locale);
            $subject = $n->toMail($this->owner)->subject;
            app()->setLocale($previous);

            return $locale === 'nl' && $subject === __('club_portal.email.subject', [], 'nl')
                && $subject !== __('club_portal.email.subject', [], 'en');
        });
    }
}
