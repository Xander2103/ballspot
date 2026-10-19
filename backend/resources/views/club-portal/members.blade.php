@extends('club-portal.layout')

@section('title', __('club_portal.members.title') . ' · ' . $club->name)

@php
    use App\Services\ClubPortal\ClubPortalMemberRules as Rules;
    $tz = $club->timezone ?? config('app.timezone');
    $readOnly = !$club->isActive();
    $transferErrors = $errors->getBag('transfer');
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">{{ __('club_portal.members.title') }}</h1>
        <div class="small text-muted">{{ __('club_portal.members.how_to_add') }}</div>
    </div>
    <form method="GET" action="{{ route('club-portal.members.index', $club) }}" class="d-flex flex-wrap gap-2" role="search" data-section="member-filters">
        <label for="m-q" class="visually-hidden">{{ __('club_portal.common.search') }}</label>
        <input id="m-q" type="search" name="q" value="{{ $filters['q'] }}" maxlength="60" class="form-control form-control-sm" style="max-width: 14rem" placeholder="{{ __('club_portal.members.search_placeholder') }}">
        <label for="m-role" class="visually-hidden">{{ __('club_portal.members.col_role') }}</label>
        <select id="m-role" name="role" class="form-select form-select-sm" style="max-width: 9rem">
            <option value="">{{ __('club_portal.members.any_role') }}</option>
            @foreach(['owner', 'admin', 'member'] as $r)
                <option value="{{ $r }}" @selected($filters['role'] === $r)>{{ __('club_portal.roles.' . $r) }}</option>
            @endforeach
        </select>
        <label for="m-status" class="visually-hidden">Status</label>
        <select id="m-status" name="status" class="form-select form-select-sm" style="max-width: 10rem">
            <option value="active" @selected($filters['status'] === 'active')>{{ __('club_portal.members.status_active') }} ({{ (int) ($counts['active'] ?? 0) }})</option>
            <option value="banned" @selected($filters['status'] === 'banned')>{{ __('club_portal.members.status_banned') }} ({{ (int) ($counts['banned'] ?? 0) }})</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">{{ __('club_portal.common.filter') }}</button>
        @if($filters['q'] !== '' || $filters['role'] !== '' || $filters['status'] !== 'active')
            <a href="{{ route('club-portal.members.index', $club) }}" class="btn btn-link btn-sm">{{ __('club_portal.common.reset') }}</a>
        @endif
    </form>
</div>

<div class="portal-card">
    <div class="table-responsive">
        <table class="table table-hover mb-0" data-section="member-list">
            <thead>
                <tr>
                    <th>{{ __('club_portal.members.col_player') }}</th>
                    <th>{{ __('club_portal.members.col_role') }}</th>
                    <th>{{ __('club_portal.members.col_joined') }}</th>
                    <th>{{ __('club_portal.members.col_last_seen') }}</th>
                    <th class="text-end">{{ __('club_portal.common.actions') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse($members as $member)
                @php
                    $allowed = $readOnly ? [] : Rules::allowedActions($actor, $member);
                    $name = $member->user?->username ?? __('club_portal.common.deleted_user');
                    $isSelf = (int) $member->user_id === (int) $actor->user_id;
                @endphp
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            @if($member->user?->avatar_path)<img src="{{ asset('storage/' . $member->user->avatar_path) }}" alt="" width="28" height="28" class="rounded-circle">@endif
                            <span class="fw-semibold">{{ $name }}</span>
                            @if($isSelf)<span class="badge badge-soft">{{ __('club_portal.members.you') }}</span>@endif
                        </div>
                    </td>
                    <td><span class="badge badge-role-{{ $member->role->value }}">{{ __('club_portal.roles.' . $member->role->value) }}</span></td>
                    <td class="small text-nowrap">{{ $member->joined_at?->copy()->setTimezone($tz)->format('Y-m-d') ?? __('club_portal.common.none') }}</td>
                    <td class="small text-nowrap">{{ $member->last_seen_at?->copy()->setTimezone($tz)->format('Y-m-d') ?? __('club_portal.common.none') }}</td>
                    <td class="text-end">
                        @if($allowed === [])
                            @if(!$isSelf && !$readOnly && $member->role->value === 'owner')<span class="small text-muted">{{ __('club_portal.members.owner_protected') }}</span>@endif
                        @else
                            <div class="d-inline-flex flex-nowrap text-nowrap gap-1 justify-content-end">
                                @if(in_array(Rules::ROLE, $allowed, true))
                                    <form method="POST" action="{{ route('club-portal.members.role', [$club, $member]) }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="role" value="{{ $member->role->value === 'admin' ? 'member' : 'admin' }}">
                                        <button type="submit" class="btn btn-outline-secondary btn-sm">{{ $member->role->value === 'admin' ? __('club_portal.members.make_member') : __('club_portal.members.make_admin') }}</button>
                                    </form>
                                @endif
                                @if(in_array(Rules::UNBAN, $allowed, true))
                                    <form method="POST" action="{{ route('club-portal.members.unban', [$club, $member]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-secondary btn-sm">{{ __('club_portal.members.unban') }}</button>
                                    </form>
                                @endif
                                @if(in_array(Rules::REMOVE, $allowed, true))
                                    <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#member-action-modal"
                                            data-url="{{ route('club-portal.members.remove', [$club, $member]) }}"
                                            data-title="{{ __('club_portal.members.remove_title', ['name' => $name]) }}"
                                            data-text="{{ __('club_portal.members.remove_text', ['name' => $name]) }}"
                                            data-button="{{ str_replace('…', '', __('club_portal.members.remove')) }}">{{ __('club_portal.members.remove') }}</button>
                                @endif
                                @if(in_array(Rules::BAN, $allowed, true))
                                    <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#member-action-modal"
                                            data-url="{{ route('club-portal.members.ban', [$club, $member]) }}"
                                            data-title="{{ __('club_portal.members.ban_title', ['name' => $name]) }}"
                                            data-text="{{ __('club_portal.members.ban_text', ['name' => $name]) }}"
                                            data-button="{{ str_replace('…', '', __('club_portal.members.ban')) }}">{{ __('club_portal.members.ban') }}</button>
                                @endif
                                @if(in_array(Rules::TRANSFER, $allowed, true))
                                    <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#transfer-modal"
                                            data-member-id="{{ $member->id }}" data-name="{{ $name }}">{{ __('club_portal.members.transfer_button') }}</button>
                                @endif
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">{{ __('club_portal.members.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if($members->hasPages())<div class="mt-3">{{ $members->links('pagination::bootstrap-5') }}</div>@endif

<div class="modal fade" id="member-action-modal" tabindex="-1" aria-labelledby="member-action-title" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="#" class="modal-content" id="member-action-form">
            @csrf
            <div class="modal-header">
                <h2 class="modal-title h5" id="member-action-title"></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('club_portal.common.close') }}"></button>
            </div>
            <div class="modal-body">
                <p id="member-action-text"></p>
                <label for="member-reason" class="form-label small">{{ __('club_portal.members.reason_label') }} <span class="text-muted">({{ __('club_portal.common.optional') }})</span></label>
                <input id="member-reason" type="text" name="reason" maxlength="200" class="form-control form-control-sm">
                <div class="form-text">{{ __('club_portal.members.reason_hint') }}</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('club_portal.common.cancel') }}</button>
                <button type="submit" class="btn btn-danger" id="member-action-submit">{{ __('club_portal.common.confirm') }}</button>
            </div>
        </form>
    </div>
</div>

@if($portalIsOwner && !$readOnly)
<div class="modal fade" id="transfer-modal" tabindex="-1" aria-labelledby="transfer-title" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('club-portal.members.transfer', $club) }}" class="modal-content">
            @csrf
            <input type="hidden" name="member_id" id="transfer-member-id" value="{{ old('member_id') }}">
            <div class="modal-header">
                <h2 class="modal-title h5" id="transfer-title">{{ __('club_portal.members.transfer.title') }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('club_portal.common.close') }}"></button>
            </div>
            <div class="modal-body">
                <p class="small">{{ __('club_portal.members.transfer.intro') }}</p>
                <p class="fw-semibold mb-2">{{ __('club_portal.members.transfer.target') }}: <span id="transfer-name"></span></p>
                <label for="transfer-confirm" class="form-label small">{{ __('club_portal.members.transfer.confirm_label') }}</label>
                <input id="transfer-confirm" type="text" name="confirm" maxlength="100" autocomplete="off" required class="form-control form-control-sm {{ $transferErrors->has('confirm') ? 'is-invalid' : '' }}">
                @if($transferErrors->has('confirm'))<div class="invalid-feedback">{{ $transferErrors->first('confirm') }}</div>@endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('club_portal.common.cancel') }}</button>
                <button type="submit" class="btn btn-warning">{{ __('club_portal.members.transfer.submit') }}</button>
            </div>
        </form>
    </div>
</div>
@endif
@if($transferErrors->any())
    <div class="alert alert-danger mt-3">{{ $transferErrors->first() }}</div>
@endif
@endsection

@push('scripts')
<script>
(function () {
    var modal = document.getElementById('member-action-modal');
    if (modal) modal.addEventListener('show.bs.modal', function (event) {
        var t = event.relatedTarget;
        if (!t) return;
        document.getElementById('member-action-form').setAttribute('action', t.getAttribute('data-url'));
        document.getElementById('member-action-title').textContent = t.getAttribute('data-title');
        document.getElementById('member-action-text').textContent = t.getAttribute('data-text');
        document.getElementById('member-action-submit').textContent = t.getAttribute('data-button');
        document.getElementById('member-reason').value = '';
    });
    var transfer = document.getElementById('transfer-modal');
    if (transfer) transfer.addEventListener('show.bs.modal', function (event) {
        var t = event.relatedTarget;
        if (!t) return;
        document.getElementById('transfer-member-id').value = t.getAttribute('data-member-id');
        document.getElementById('transfer-name').textContent = t.getAttribute('data-name');
        document.getElementById('transfer-confirm').value = '';
    });
}());
</script>
@endpush
