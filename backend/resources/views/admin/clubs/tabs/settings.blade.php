@php
    $replayLabels = ['no_replay' => 'No replay — players who completed a pack cannot play it in the competition', 'single_attempt' => 'One competition attempt per pack (recommended)', 'multiple_attempts' => 'Multiple attempts (max and first/best set per competition)'];
    $policyLabels = ['blocked' => 'Blocked — linked packs can only be played in the competition until it is finalized (recommended)', 'allowed' => 'Allowed — linked packs stay playable normally'];
    $s = $club->resolvedSettings();
@endphp
<form method="POST" action="{{ route('admin.clubs.settings', $club) }}" class="card shadow-sm" style="max-width: 860px;" data-section="settings-form">
    @csrf
    @method('PUT')
    <input type="hidden" name="version" value="{{ old('version', $club->version) }}">
    <div class="card-body">
        @if($errors->any())<div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>@endif

        <h2 class="h6 text-uppercase text-muted small mb-3">Access</h2>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="form-check form-switch">
                    <input type="hidden" name="join_enabled" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="s-join" name="join_enabled" value="1" {{ old('join_enabled', $club->join_enabled ? '1' : '0') === '1' ? 'checked' : '' }}>
                    <label class="form-check-label fw-semibold" for="s-join">Joining with invite codes enabled</label>
                </div>
                <div class="form-text">Master switch for every code. Existing members are not affected.</div>
            </div>
            <div class="col-md-3">
                <label for="s-max" class="form-label fw-semibold">Max members</label>
                <input id="s-max" type="number" name="max_members" min="1" value="{{ old('max_members', $club->max_members) }}" class="form-control" placeholder="{{ config('ballspot.clubs.limits.max_members', 500) }} (default)">
            </div>
            <div class="col-md-3 small text-muted d-flex align-items-end">Now {{ $club->members_count }} active.</div>
            <div class="col-md-6">
                <label for="s-visibility" class="form-label fw-semibold">Member list visible to</label>
                <select id="s-visibility" name="member_list_visibility" class="form-select">
                    <option value="admins" {{ old('member_list_visibility', $s['member_list_visibility']) === 'admins' ? 'selected' : '' }}>Owners &amp; admins only (default)</option>
                    <option value="members" {{ old('member_list_visibility', $s['member_list_visibility']) === 'members' ? 'selected' : '' }}>All members</option>
                </select>
                <div class="form-text">Ordinary members always see the member count.</div>
            </div>
            <div class="col-md-6">
                <label for="s-identity" class="form-label fw-semibold">Leaderboard identity</label>
                <select id="s-identity" class="form-select" disabled>
                    <option selected>Usernames + avatars</option>
                </select>
                <input type="hidden" name="leaderboard_identity" value="username">
                <div class="form-text">Fixed: real names are never shown on club leaderboards.</div>
            </div>
        </div>

        <h2 class="h6 text-uppercase text-muted small mb-3">Competition defaults</h2>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label for="s-replay" class="form-label fw-semibold">Default replay mode</label>
                <select id="s-replay" name="default_replay_mode" class="form-select">
                    @foreach($replayModes as $mode)
                        <option value="{{ $mode }}" {{ old('default_replay_mode', $s['default_replay_mode']) === $mode ? 'selected' : '' }}>{{ $replayLabels[$mode] ?? $mode }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6">
                <label for="s-policy" class="form-label fw-semibold">Default normal play of linked packs</label>
                <select id="s-policy" name="default_normal_play_policy" class="form-select">
                    @foreach($normalPolicies as $policy)
                        <option value="{{ $policy }}" {{ old('default_normal_play_policy', $s['default_normal_play_policy']) === $policy ? 'selected' : '' }}>{{ $policyLabels[$policy] ?? $policy }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6">
                <label for="s-tz" class="form-label fw-semibold">Timezone</label>
                <select id="s-tz" name="timezone" class="form-select">
                    @foreach($timezones as $tz)
                        <option value="{{ $tz }}" {{ old('timezone', $club->timezone) === $tz ? 'selected' : '' }}>{{ $tz }}</option>
                    @endforeach
                </select>
                <div class="form-text">New competitions copy it; their windows are shown in it.</div>
            </div>
        </div>
        <p class="small text-muted mb-0">Defaults apply to new competitions only. Each competition's own rules lock when it starts.</p>
    </div>
    <div class="card-footer bg-white d-flex gap-2 align-items-center">
        <button type="submit" class="btn btn-primary px-4">Save settings</button>
        <span class="text-muted small ms-auto">Changes are audited with before/after values.</span>
    </div>
</form>
