{{-- Audit events list. Expects $events (paginator|collection), $usernames (id => username), optional $compact. --}}
@php
    $compact = $compact ?? false;
    $usernames = $usernames ?? collect();
    $tz = $portalClub->timezone ?? config('app.timezone');
@endphp
<div class="table-responsive">
    <table class="table table-sm mb-0">
        <thead>
            <tr>
                <th>{{ __('club_portal.audit.col_time') }}</th>
                <th>{{ __('club_portal.audit.col_actor') }}</th>
                <th>{{ __('club_portal.audit.col_action') }}</th>
                @unless($compact)<th>{{ __('club_portal.audit.col_details') }}</th>@endunless
            </tr>
        </thead>
        <tbody>
        @forelse($events as $event)
            @php
                $meta = $event->metadata ?? [];
                $details = [];
                if (isset($meta['target_user_id']) && $usernames->has((int) $meta['target_user_id'])) {
                    $details[] = __('club_portal.audit.target', ['name' => $usernames[(int) $meta['target_user_id']]]);
                }
                if (isset($meta['new_owner_id']) && $usernames->has((int) $meta['new_owner_id'])) {
                    $details[] = __('club_portal.audit.new_owner', ['name' => $usernames[(int) $meta['new_owner_id']]]);
                }
                foreach (['role', 'from_role', 'to_role', 'grants_role'] as $k) {
                    if (isset($meta[$k]) && is_string($meta[$k]) && \Illuminate\Support\Facades\Lang::has('club_portal.roles.' . $meta[$k])) {
                        $details[] = str_replace('_', ' ', $k) . ': ' . __('club_portal.roles.' . $meta[$k]);
                    }
                }
                foreach (['code_masked', 'label', 'name', 'from_status', 'to_status', 'replay_mode', 'max_attempts', 'score_rule', 'normal_play_policy', 'ends_at_from', 'ends_at_to', 'count'] as $k) {
                    if (isset($meta[$k]) && is_scalar($meta[$k]) && $meta[$k] !== '') {
                        $details[] = str_replace('_', ' ', $k) . ': ' . \Illuminate\Support\Str::limit((string) $meta[$k], 60);
                    }
                }
                // Reasons typed by club admins / members are shown; BallPicker's internal reasons are not.
                if (isset($meta['reason']) && is_string($meta['reason']) && $meta['reason'] !== '' && $event->actor_kind !== 'platform_admin') {
                    $details[] = __('club_portal.members.reason_label') . ': ' . \Illuminate\Support\Str::limit($meta['reason'], 120);
                }
                if (isset($meta['after']) && is_array($meta['after'])) {
                    $details[] = implode(', ', array_map(fn ($k) => str_replace('_', ' ', $k), array_keys($meta['after'])));
                }
            @endphp
            <tr>
                <td class="small text-nowrap" title="{{ $event->created_at?->toIso8601String() }}">{{ $event->created_at?->copy()->setTimezone($tz)->format('Y-m-d H:i') }}</td>
                <td class="small">
                    {{ $event->actor?->username ?? ($event->actor_kind === 'system' ? __('club_portal.common.system') : __('club_portal.common.deleted_user')) }}
                    <div class="text-muted" style="font-size:.7rem">{{ __('club_portal.actor_kind.' . $event->actor_kind) }}</div>
                </td>
                <td class="small fw-semibold">{{ $event->label() }}</td>
                @unless($compact)<td class="small text-muted">{{ $details === [] ? __('club_portal.common.none') : implode(' · ', $details) }}</td>@endunless
            </tr>
        @empty
            <tr><td colspan="{{ $compact ? 3 : 4 }}" class="text-center text-muted py-4">{{ ($filtered ?? false) ? __('club_portal.audit.empty_filtered') : __('club_portal.audit.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
