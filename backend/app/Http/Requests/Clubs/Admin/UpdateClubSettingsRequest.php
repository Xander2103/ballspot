<?php

namespace App\Http\Requests\Clubs\Admin;

use App\Enums\NormalPlayPolicy;
use App\Enums\ReplayMode;
use App\Models\Club;
use Illuminate\Validation\Rule;

/** Club settings tab (plan §8.3) with the optimistic `version`. */
class UpdateClubSettingsRequest extends AdminClubRequest
{
    public function rules(): array
    {
        return [
            'version'                    => ['required', 'integer', 'min:1'],
            'join_enabled'               => ['nullable', 'boolean'],
            'max_members'                => ['nullable', 'integer', 'min:1', 'max:100000'],
            'member_list_visibility'     => ['required', Rule::in(Club::MEMBER_LIST_VISIBILITIES)],
            'leaderboard_identity'       => ['nullable', Rule::in(Club::LEADERBOARD_IDENTITIES)],
            'default_replay_mode'        => ['required', Rule::in(array_map(fn ($c) => $c->value, ReplayMode::cases()))],
            'default_normal_play_policy' => ['required', Rule::in(array_map(fn ($c) => $c->value, NormalPlayPolicy::cases()))],
            'timezone'                   => ['required', 'string', 'timezone:all'],
        ];
    }

    /** Settings payload for ClubService::updateSettings(). */
    public function settings(): array
    {
        return [
            'join_enabled'               => $this->boolean('join_enabled'),
            'max_members'                => $this->filled('max_members') ? (int) $this->input('max_members') : null,
            'member_list_visibility'     => (string) $this->input('member_list_visibility'),
            // D12: fixed to usernames + avatars.
            'leaderboard_identity'       => 'username',
            'default_replay_mode'        => (string) $this->input('default_replay_mode'),
            'default_normal_play_policy' => (string) $this->input('default_normal_play_policy'),
            'timezone'                   => (string) $this->input('timezone'),
        ];
    }
}
