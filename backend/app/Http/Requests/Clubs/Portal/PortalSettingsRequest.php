<?php

namespace App\Http\Requests\Clubs\Portal;

use App\Enums\NormalPlayPolicy;
use App\Enums\ReplayMode;
use App\Models\Club;
use Illuminate\Validation\Rule;

/**
 * Owner settings: joining on/off, member list visibility, competition
 * defaults. max_members, status and timezone stay platform-only.
 */
class PortalSettingsRequest extends PortalRequest
{
    protected string $ability = 'manageSettings';

    public function rules(): array
    {
        return [
            'version'                    => ['required', 'integer', 'min:1'],
            'join_enabled'               => ['nullable', 'boolean'],
            'member_list_visibility'     => ['required', Rule::in(Club::MEMBER_LIST_VISIBILITIES)],
            'default_replay_mode'        => ['required', Rule::in(array_map(fn ($c) => $c->value, ReplayMode::cases()))],
            'default_normal_play_policy' => ['required', Rule::in(array_map(fn ($c) => $c->value, NormalPlayPolicy::cases()))],
        ];
    }

    /** Payload for ClubService::updateSettings() — the portal's allow-list only. */
    public function settings(): array
    {
        return [
            'join_enabled'               => $this->boolean('join_enabled'),
            'member_list_visibility'     => (string) $this->input('member_list_visibility'),
            'default_replay_mode'        => (string) $this->input('default_replay_mode'),
            'default_normal_play_policy' => (string) $this->input('default_normal_play_policy'),
        ];
    }
}
