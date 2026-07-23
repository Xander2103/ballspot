<?php

namespace Tests\Feature;

use App\Models\NotificationSetting;
use App\Models\PushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): array
    {
        $user = User::factory()->create();
        return [$user, $user->createToken('test')->plainTextToken];
    }

    public function test_settings_require_auth(): void
    {
        $this->getJson('/api/me/notification-settings')->assertUnauthorized();
        $this->putJson('/api/me/notification-settings', [])->assertUnauthorized();
        $this->postJson('/api/me/push-tokens', [])->assertUnauthorized();
    }

    public function test_reading_settings_lazily_creates_defaults(): void
    {
        [$user, $token] = $this->auth();
        $this->assertDatabaseCount('notification_settings', 0);

        $res = $this->withToken($token)->getJson('/api/me/notification-settings');

        $res->assertOk()
            ->assertJson([
                'daily_reminder_enabled'      => true,
                'tournament_reminder_enabled' => true,
                'admin_notifications_enabled' => true,
                'reminder_time'               => '19:00',
                'timezone'                    => null,
            ]);
        // A single defaults row was created for this user.
        $this->assertDatabaseHas('notification_settings', ['user_id' => $user->id]);
        $this->assertSame(1, NotificationSetting::count());
    }

    public function test_user_can_update_own_settings(): void
    {
        [$user, $token] = $this->auth();

        $res = $this->withToken($token)->putJson('/api/me/notification-settings', [
            'daily_reminder_enabled'      => false,
            'tournament_reminder_enabled' => true,
            'admin_notifications_enabled' => false,
            'reminder_time'               => '08:30',
            'timezone'                    => 'Europe/Brussels',
        ]);

        $res->assertOk()
            ->assertJson([
                'daily_reminder_enabled'      => false,
                'admin_notifications_enabled' => false,
                'reminder_time'               => '08:30',
                'timezone'                    => 'Europe/Brussels',
            ]);
        $this->assertDatabaseHas('notification_settings', [
            'user_id'                => $user->id,
            'daily_reminder_enabled' => false,
            'reminder_time'          => '08:30',
        ]);
    }

    public function test_partial_update_only_touches_sent_keys(): void
    {
        [, $token] = $this->auth();

        $this->withToken($token)->putJson('/api/me/notification-settings', [
            'reminder_time' => '07:15',
        ])->assertOk()->assertJson([
            'reminder_time'          => '07:15',
            'daily_reminder_enabled' => true, // unchanged default
        ]);
    }

    /** @dataProvider invalidTimes */
    public function test_invalid_reminder_time_is_rejected(string $time): void
    {
        [, $token] = $this->auth();

        $this->withToken($token)->putJson('/api/me/notification-settings', [
            'reminder_time' => $time,
        ])->assertStatus(422)->assertJsonValidationErrors('reminder_time');
    }

    public static function invalidTimes(): array
    {
        return [['25:00'], ['7pm'], ['9:5'], ['noon'], ['24:00'], ['19-00']];
    }

    public function test_a_users_update_does_not_affect_another_user(): void
    {
        [$a, $tokenA] = $this->auth();
        [$b] = $this->auth();
        // Seed B's row with a distinct value.
        $b->notificationSettings()->update(['reminder_time' => '21:00']);

        $this->withToken($tokenA)->putJson('/api/me/notification-settings', [
            'reminder_time' => '06:00',
        ])->assertOk();

        $this->assertDatabaseHas('notification_settings', ['user_id' => $a->id, 'reminder_time' => '06:00']);
        // B untouched — a user can only ever edit their own /me row.
        $this->assertDatabaseHas('notification_settings', ['user_id' => $b->id, 'reminder_time' => '21:00']);
    }

    public function test_user_can_register_a_push_token(): void
    {
        [$user, $token] = $this->auth();

        $this->withToken($token)->postJson('/api/me/push-tokens', [
            'token'       => 'ExponentPushToken[abc123]',
            'platform'    => 'ios',
            'device_name' => 'iPhone',
        ])->assertCreated()->assertJson(['status' => 'registered']);

        $this->assertDatabaseHas('push_tokens', [
            'user_id'  => $user->id,
            'token'    => 'ExponentPushToken[abc123]',
            'platform' => 'ios',
        ]);
    }

    public function test_reregistering_a_token_reassigns_it_without_duplicating(): void
    {
        // User A already owns this device token.
        [$a] = $this->auth();
        PushToken::create(['user_id' => $a->id, 'token' => 'ExponentPushToken[shared]']);

        // User B registers the same token (device switched accounts).
        [$b, $tokenB] = $this->auth();
        $this->withToken($tokenB)->postJson('/api/me/push-tokens', [
            'token' => 'ExponentPushToken[shared]',
        ])->assertCreated();

        // Unique: exactly one row, now owned by B.
        $this->assertSame(1, PushToken::where('token', 'ExponentPushToken[shared]')->count());
        $this->assertDatabaseHas('push_tokens', [
            'token'   => 'ExponentPushToken[shared]',
            'user_id' => $b->id,
        ]);
    }

    public function test_raw_push_token_is_never_serialized(): void
    {
        [$user] = $this->auth();
        $pt = PushToken::create(['user_id' => $user->id, 'token' => 'secret-token']);

        $this->assertArrayNotHasKey('token', $pt->toArray());
    }
}
