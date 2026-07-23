<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is behind auth:sanctum; a user only ever edits their own row.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'daily_reminder_enabled'      => ['sometimes', 'boolean'],
            'tournament_reminder_enabled' => ['sometimes', 'boolean'],
            'admin_notifications_enabled' => ['sometimes', 'boolean'],
            // 24-hour HH:mm, 00:00..23:59.
            'reminder_time'               => ['sometimes', 'string', 'date_format:H:i'],
            'timezone'                    => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
