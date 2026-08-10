<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\PushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function fakeExpoOk(): void
    {
        // Expo returns a ticket per message; "ok" = accepted.
        Http::fake([
            '*' => Http::response(['data' => [['status' => 'ok']]], 200),
        ]);
    }

    public function test_notifications_page_is_admin_only(): void
    {
        // Guest is redirected to admin login.
        $this->get('/admin/notifications')->assertRedirect(route('admin.login'));

        // Non-admin is forbidden.
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get('/admin/notifications')->assertForbidden();

        // Admin can view.
        $this->actingAs($this->admin())->get('/admin/notifications')->assertOk();
    }

    public function test_store_validates_title_and_body(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/notifications', ['title' => '', 'body' => '', 'target_type' => 'all'])
            ->assertSessionHasErrors(['title', 'body']);

        // Over-long body rejected.
        $this->actingAs($this->admin())
            ->post('/admin/notifications', [
                'title'       => 'Hi',
                'body'        => str_repeat('a', 501),
                'target_type' => 'all',
            ])
            ->assertSessionHasErrors('body');
    }

    public function test_store_saves_draft_without_sending(): void
    {
        Http::fake();

        $this->actingAs($this->admin())->post('/admin/notifications', [
            'title'       => 'Season kickoff',
            'body'        => 'New challenges are live!',
            'target_type' => 'opted_in',
        ])->assertRedirect('/admin/notifications');

        $this->assertDatabaseHas('admin_notifications', [
            'title'  => 'Season kickoff',
            'status' => AdminNotification::STATUS_DRAFT,
        ]);
        Http::assertNothingSent();
    }

    public function test_send_now_delivers_to_opted_in_tokens_and_marks_sent(): void
    {
        $this->fakeExpoOk();

        $optedIn = User::factory()->create();
        $optedIn->notificationSettings()->update(['admin_notifications_enabled' => true]);
        PushToken::create(['user_id' => $optedIn->id, 'token' => 'ExponentPushToken[in]']);

        $this->actingAs($this->admin())->post('/admin/notifications', [
            'title'       => 'Ping',
            'body'        => 'Body',
            'target_type' => 'opted_in',
            'send_now'    => '1',
        ])->assertRedirect('/admin/notifications');

        $this->assertDatabaseHas('admin_notifications', [
            'title'  => 'Ping',
            'status' => AdminNotification::STATUS_SENT,
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'exp.host'));
    }

    public function test_send_respects_opt_out(): void
    {
        $this->fakeExpoOk();

        $optedOut = User::factory()->create();
        $optedOut->notificationSettings()->update(['admin_notifications_enabled' => false]);
        PushToken::create(['user_id' => $optedOut->id, 'token' => 'ExponentPushToken[out]']);

        // Target ALL — but the opted-out user must still be excluded.
        $notification = AdminNotification::create([
            'title'       => 'Ping',
            'body'        => 'Body',
            'target_type' => AdminNotification::TARGET_ALL,
            'status'      => AdminNotification::STATUS_DRAFT,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.notifications.send', $notification))
            ->assertRedirect('/admin/notifications');

        $notification->refresh();
        $this->assertSame(0, $notification->metadata['recipients']);
        // No push was sent to the opted-out device.
        Http::assertNothingSent();
    }

    public function test_all_target_includes_users_without_settings_row(): void
    {
        $this->fakeExpoOk();

        // User never touched settings — no row exists, defaults to enabled.
        $user = User::factory()->create();
        PushToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken[fresh]']);

        $notification = AdminNotification::create([
            'title'       => 'Ping',
            'body'        => 'Body',
            'target_type' => AdminNotification::TARGET_ALL,
            'status'      => AdminNotification::STATUS_DRAFT,
        ]);

        $this->actingAs($this->admin())->post(route('admin.notifications.send', $notification));

        $notification->refresh();
        $this->assertSame(1, $notification->metadata['recipients']);
        $this->assertSame(AdminNotification::STATUS_SENT, $notification->status);
    }

    public function test_device_not_registered_tokens_are_deleted_on_send(): void
    {
        Http::fake([
            '*' => Http::response(['data' => [
                ['status' => 'ok'],
                ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
            ]], 200),
        ]);

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        PushToken::create(['user_id' => $u1->id, 'token' => 'ExponentPushToken[good-1]']);
        PushToken::create(['user_id' => $u2->id, 'token' => 'ExponentPushToken[dead-2]']);

        $this->actingAs($this->admin())->post('/admin/notifications', [
            'title'       => 'Ping',
            'body'        => 'Body',
            'target_type' => 'all',
            'send_now'    => '1',
        ])->assertRedirect('/admin/notifications');

        $this->assertDatabaseHas('push_tokens', ['token' => 'ExponentPushToken[good-1]']);
        $this->assertDatabaseMissing('push_tokens', ['token' => 'ExponentPushToken[dead-2]']);
    }

    public function test_send_with_push_disabled_stays_draft(): void
    {
        config(['ballspot.notifications.push_enabled' => false]);
        Http::fake();

        $user = User::factory()->create();
        PushToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken[x]']);

        $notification = AdminNotification::create([
            'title'       => 'Ping',
            'body'        => 'Body',
            'target_type' => AdminNotification::TARGET_ALL,
            'status'      => AdminNotification::STATUS_DRAFT,
        ]);

        $this->actingAs($this->admin())->post(route('admin.notifications.send', $notification));

        $notification->refresh();
        $this->assertSame(AdminNotification::STATUS_DRAFT, $notification->status);
        $this->assertSame('push_disabled', $notification->metadata['reason']);
        Http::assertNothingSent();
    }
}
