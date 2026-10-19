@extends('club-portal.layout')

@section('title', __('club_portal.settings.title') . ' · ' . $club->name)

@section('content')
<h1 class="h4 mb-1">{{ __('club_portal.settings.title') }}</h1>
<p class="text-muted small">{{ __('club_portal.settings.intro') }}</p>
@unless($canEdit)<div class="alert alert-warning py-2 small">{{ __('club_portal.settings.read_only') }}</div>@endunless

<form method="POST" action="{{ route('club-portal.settings.update', $club) }}" class="portal-card p-3" style="max-width: 640px" data-section="settings-form">
    @csrf @method('PUT')
    <input type="hidden" name="version" value="{{ $club->version }}">
    <fieldset @disabled(!$canEdit)>
        <div class="form-check form-switch mb-3">
            <input type="hidden" name="join_enabled" value="0">
            <input class="form-check-input" type="checkbox" role="switch" id="join_enabled" name="join_enabled" value="1" @checked(old('join_enabled', $club->join_enabled))>
            <label class="form-check-label" for="join_enabled">{{ __('club_portal.settings.join_enabled') }}</label>
            <div class="form-text">{{ __('club_portal.settings.join_enabled_hint') }}</div>
        </div>
        <div class="mb-3">
            <label for="member_list_visibility" class="form-label">{{ __('club_portal.settings.member_list_visibility') }}</label>
            <select id="member_list_visibility" name="member_list_visibility" class="form-select @error('member_list_visibility') is-invalid @enderror">
                @foreach(\App\Models\Club::MEMBER_LIST_VISIBILITIES as $v)
                    <option value="{{ $v }}" @selected(old('member_list_visibility', $settings['member_list_visibility']) === $v)>{{ __('club_portal.settings.visibility_' . $v) }}</option>
                @endforeach
            </select>
            @error('member_list_visibility')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-3">
            <label for="default_replay_mode" class="form-label">{{ __('club_portal.settings.default_replay_mode') }}</label>
            <select id="default_replay_mode" name="default_replay_mode" class="form-select @error('default_replay_mode') is-invalid @enderror">
                @foreach(\App\Enums\ReplayMode::cases() as $mode)
                    <option value="{{ $mode->value }}" @selected(old('default_replay_mode', $settings['default_replay_mode']) === $mode->value)>{{ __('club_portal.settings.replay_' . $mode->value) }}</option>
                @endforeach
            </select>
            @error('default_replay_mode')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-3">
            <label for="default_normal_play_policy" class="form-label">{{ __('club_portal.settings.default_normal_play_policy') }}</label>
            <select id="default_normal_play_policy" name="default_normal_play_policy" class="form-select @error('default_normal_play_policy') is-invalid @enderror">
                @foreach(\App\Enums\NormalPlayPolicy::cases() as $policy)
                    <option value="{{ $policy->value }}" @selected(old('default_normal_play_policy', $settings['default_normal_play_policy']) === $policy->value)>{{ __('club_portal.settings.policy_' . $policy->value) }}</option>
                @endforeach
            </select>
            @error('default_normal_play_policy')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <button type="submit" class="btn btn-primary">{{ __('club_portal.common.save') }}</button>
    </fieldset>
</form>
@endsection
