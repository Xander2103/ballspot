<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationSettingsRequest;
use App\Models\NotificationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationSettingsController extends Controller
{
    // GET /api/me/notification-settings
    public function show(Request $request): JsonResponse
    {
        // Lazily creates a defaults row on first read.
        return response()->json($this->payload($request->user()->notificationSettings()));
    }

    // PUT /api/me/notification-settings
    public function update(UpdateNotificationSettingsRequest $request): JsonResponse
    {
        $settings = $request->user()->notificationSettings();

        // Partial update — only overwrite keys the client actually sent.
        $settings->fill($request->validated());
        $settings->save();

        return response()->json($this->payload($settings));
    }

    private function payload(NotificationSetting $settings): array
    {
        return [
            'daily_reminder_enabled'      => (bool) $settings->daily_reminder_enabled,
            'tournament_reminder_enabled' => (bool) $settings->tournament_reminder_enabled,
            'admin_notifications_enabled' => (bool) $settings->admin_notifications_enabled,
            'reminder_time'               => $settings->reminder_time,
            'timezone'                    => $settings->timezone,
        ];
    }
}
