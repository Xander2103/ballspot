@extends('club-portal.layout')

@section('title', __('club_portal.codes.title') . ' · ' . $club->name)

@php
    use App\Http\Controllers\ClubPortal\InviteCodeController;
    $codeErrors = $errors->getBag('newCode');
    $highlight = (int) session('highlight_code_id');
    $readOnly = !$club->isActive();
    $actor = request()->attributes->get('portal_member');
@endphp

@section('content')
<h1 class="h4 mb-1">{{ __('club_portal.codes.title') }}</h1>
<p class="text-muted small">{{ __('club_portal.codes.intro', ['active' => $activeCodes, 'limit' => $codeLimit]) }}</p>

@if(!$club->isActive())
    <div class="alert alert-warning py-2 small">{{ __('club_portal.codes.club_inactive') }}</div>
@elseif(!$club->join_enabled)
    <div class="alert alert-warning py-2 small">{{ __('club_portal.codes.join_closed') }}</div>
@endif

<div class="row g-3">
    <div class="{{ $readOnly ? 'col-12' : 'col-lg-8' }}">
        <div class="d-flex justify-content-end mb-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="codes-reveal" aria-pressed="false" data-show="{{ __('club_portal.common.show_codes') }}" data-hide="{{ __('club_portal.common.hide_codes') }}">{{ __('club_portal.common.show_codes') }}</button>
        </div>
        <div class="portal-card">
            <div class="table-responsive">
                <table class="table table-hover mb-0" data-section="code-list">
                    <thead>
                        <tr>
                            <th>{{ __('club_portal.codes.col_code') }}</th>
                            <th>{{ __('club_portal.codes.col_label') }}</th>
                            <th>{{ __('club_portal.codes.col_role') }}</th>
                            <th class="text-end">{{ __('club_portal.codes.col_uses') }}</th>
                            <th>{{ __('club_portal.codes.col_expires') }}</th>
                            <th>{{ __('club_portal.codes.col_state') }}</th>
                            <th class="text-end">{{ __('club_portal.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($codes as $code)
                        @php
                            [$stateLabel, $stateClass] = match (true) {
                                $code->isRevoked()   => [__('club_portal.codes.state_revoked'), 'badge-soft'],
                                $code->isExpired()   => [__('club_portal.codes.state_expired'), 'badge-soft-warn'],
                                $code->isExhausted() => [__('club_portal.codes.state_used_up'), 'badge-soft-warn'],
                                default              => [__('club_portal.codes.state_usable'), 'badge-soft-ok'],
                            };
                            $mayHandle = InviteCodeController::canHandle($actor, $code);
                            $codeBase = ['club' => $club, 'inviteCode' => $code];
                        @endphp
                        <tr class="{{ $code->isRevoked() ? 'text-muted' : '' }} {{ $highlight === $code->id ? 'table-success' : '' }}">
                            <td class="text-nowrap font-monospace">
                                @if($mayHandle)
                                    <span data-code-masked="{{ $code->masked() }}" data-code-full="{{ $code->formatted() }}">{{ $code->masked() }}</span>
                                    @unless($code->isRevoked())
                                        <button type="button" class="btn btn-link btn-sm p-0 ms-1 code-copy" data-copy="{{ $code->formatted() }}" data-copied="{{ __('club_portal.common.copied') }}">{{ __('club_portal.common.copy') }}</button>
                                    @endunless
                                @else
                                    <span>{{ $code->masked() }}</span>
                                    <div class="small text-muted font-sans-serif">{{ __('club_portal.codes.admin_code_hidden') }}</div>
                                @endif
                            </td>
                            <td class="small" style="min-width:10rem">{{ $code->label ?? __('club_portal.common.none') }}<div class="text-muted" style="font-size:.7rem">{{ $code->created_at?->format('Y-m-d') }}@if($code->createdBy) · {{ $code->createdBy->username }}@endif</div></td>
                            <td class="small"><span class="badge badge-role-{{ $code->grants_role->value }}">{{ __('club_portal.roles.' . $code->grants_role->value) }}</span></td>
                            <td class="text-end small text-nowrap">{{ $code->uses_count }}@if($code->max_uses) / {{ $code->max_uses }}@endif</td>
                            <td class="small text-nowrap">{{ $code->expires_at?->format('Y-m-d') ?? __('club_portal.common.never') }}</td>
                            <td><span class="badge {{ $stateClass }}">{{ $stateLabel }}</span></td>
                            <td class="text-end text-nowrap">
                                @if($mayHandle && !$code->isRevoked())
                                    <a href="{{ route('club-portal.codes.print', $codeBase) }}" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">{{ __('club_portal.codes.print') }}</a>
                                @endif
                                @unless($readOnly)
                                    @if($mayHandle)
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#code-action-modal" data-url="{{ route('club-portal.codes.regenerate', $codeBase) }}" data-text="{{ __('club_portal.codes.regenerate_text', ['code' => $code->masked()]) }}" data-button="{{ str_replace('…', '', __('club_portal.codes.regenerate')) }}" data-class="btn-warning">{{ __('club_portal.codes.regenerate') }}</button>
                                    @endif
                                    @unless($code->isRevoked())
                                        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#code-action-modal" data-url="{{ route('club-portal.codes.revoke', $codeBase) }}" data-text="{{ __('club_portal.codes.revoke_text', ['code' => $code->masked()]) }}" data-button="{{ str_replace('…', '', __('club_portal.codes.revoke')) }}" data-class="btn-danger">{{ __('club_portal.codes.revoke') }}</button>
                                    @endunless
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">{{ __('club_portal.codes.empty') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($codes->hasPages())<div class="mt-3">{{ $codes->links('pagination::bootstrap-5') }}</div>@endif
    </div>

    @unless($readOnly)
    <div class="col-lg-4">
        <form method="POST" action="{{ route('club-portal.codes.store', $club) }}" class="portal-card p-3" data-section="new-code">
            @csrf
            <h2 class="h6">{{ __('club_portal.codes.new') }}</h2>
            @if($codeErrors->any())<div class="alert alert-danger py-2 small">{{ $codeErrors->first() }}</div>@endif
            <div class="mb-2">
                <label for="code-label" class="form-label small fw-semibold">{{ __('club_portal.codes.label') }}</label>
                <input id="code-label" type="text" name="label" value="{{ old('label') }}" maxlength="60" class="form-control form-control-sm" placeholder="{{ __('club_portal.codes.label_placeholder') }}">
            </div>
            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label for="code-expires" class="form-label small fw-semibold">{{ __('club_portal.codes.expires') }}</label>
                    <input id="code-expires" type="date" name="expires_at" value="{{ old('expires_at') }}" min="{{ now()->toDateString() }}" class="form-control form-control-sm">
                </div>
                <div class="col-6">
                    <label for="code-max" class="form-label small fw-semibold">{{ __('club_portal.codes.max_uses') }}</label>
                    <input id="code-max" type="number" name="max_uses" value="{{ old('max_uses') }}" min="1" max="100000" class="form-control form-control-sm" placeholder="{{ __('club_portal.codes.unlimited') }}">
                </div>
            </div>
            <div class="mb-3">
                <label for="code-role" class="form-label small fw-semibold">{{ __('club_portal.codes.grants_role') }}</label>
                <select id="code-role" name="grants_role" class="form-select form-select-sm">
                    <option value="member" @selected(old('grants_role', 'member') === 'member')>{{ __('club_portal.roles.member') }}</option>
                    @if($isOwner)<option value="admin" @selected(old('grants_role') === 'admin')>{{ __('club_portal.roles.admin') }}</option>@endif
                </select>
                @unless($isOwner)<div class="form-text">{{ __('club_portal.codes.owner_only_role') }}</div>@endunless
            </div>
            <button type="submit" class="btn btn-primary btn-sm w-100" @disabled($activeCodes >= $codeLimit)>{{ __('club_portal.codes.create') }}</button>
            @if($activeCodes >= $codeLimit)<div class="form-text text-danger">{{ __('club_portal.codes.limit_reached') }}</div>@endif
        </form>
    </div>
    @endunless
</div>

<div class="modal fade" id="code-action-modal" tabindex="-1" aria-labelledby="code-action-title" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="#" class="modal-content" id="code-action-form">
            @csrf
            <div class="modal-header">
                <h2 class="modal-title h5" id="code-action-title">{{ __('club_portal.common.confirm') }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('club_portal.common.close') }}"></button>
            </div>
            <div class="modal-body"><p id="code-action-text" class="mb-0"></p></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('club_portal.common.cancel') }}</button>
                <button type="submit" class="btn btn-primary" id="code-action-submit">{{ __('club_portal.common.confirm') }}</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var revealed = false;
    var toggle = document.getElementById('codes-reveal');
    if (toggle) toggle.addEventListener('click', function () {
        revealed = !revealed;
        document.querySelectorAll('[data-code-full]').forEach(function (el) {
            el.textContent = revealed ? el.getAttribute('data-code-full') : el.getAttribute('data-code-masked');
        });
        toggle.textContent = toggle.getAttribute(revealed ? 'data-hide' : 'data-show');
        toggle.setAttribute('aria-pressed', revealed ? 'true' : 'false');
    });
    document.querySelectorAll('.code-copy').forEach(function (btn) {
        var label = btn.textContent;
        btn.addEventListener('click', function () {
            if (!navigator.clipboard) return;
            navigator.clipboard.writeText(btn.getAttribute('data-copy')).then(function () {
                btn.textContent = btn.getAttribute('data-copied');
                setTimeout(function () { btn.textContent = label; }, 1500);
            });
        });
    });
    var modal = document.getElementById('code-action-modal');
    if (modal) modal.addEventListener('show.bs.modal', function (event) {
        var t = event.relatedTarget;
        if (!t) return;
        document.getElementById('code-action-form').setAttribute('action', t.getAttribute('data-url'));
        document.getElementById('code-action-text').textContent = t.getAttribute('data-text');
        var btn = document.getElementById('code-action-submit');
        btn.textContent = t.getAttribute('data-button');
        btn.className = 'btn ' + t.getAttribute('data-class');
    });
}());
</script>
@endpush
