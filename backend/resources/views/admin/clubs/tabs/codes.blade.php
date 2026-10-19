@php
    $codeErrors = $errors->getBag('newCode');
    $highlight = session('highlight_code_id');
@endphp

@if(!$club->isActive() || !$club->join_enabled)
<div class="alert alert-warning py-2 small">
    @if(!$club->isActive())
        The club is <strong>{{ $club->status->value }}</strong>: no code can be used to join until it is reactivated.
    @else
        Joining is <strong>switched off</strong> in the club settings: no code can be used until it is switched on again.
    @endif
</div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="small text-muted">{{ $activeCodes }} of {{ $codeLimit }} usable codes. Codes are shown masked; reveal them only when you need to share one.</div>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="codes-reveal" aria-pressed="false">Show codes</button>
        </div>
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover table-clubs mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Label</th>
                            <th>Role</th>
                            <th class="text-end">Uses</th>
                            <th>Expires</th>
                            <th>State</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($codes as $code)
                        @php
                            $state = match (true) {
                                $code->isRevoked()   => ['Revoked', 'badge-archived'],
                                $code->isExpired()   => ['Expired', 'badge-soft-warn'],
                                $code->isExhausted() => ['Used up', 'badge-soft-warn'],
                                default              => ['Usable', 'badge-soft-ok'],
                            };
                            $codeBase = ['club' => $club, 'inviteCode' => $code];
                        @endphp
                        <tr class="{{ $code->isRevoked() ? 'text-muted' : '' }} {{ (int) $highlight === $code->id ? 'table-success' : '' }}">
                            <td class="text-nowrap">
                                <span class="code-value" data-code-masked="{{ $code->masked() }}" data-code-full="{{ $code->formatted() }}">{{ $code->masked() }}</span>
                                @unless($code->isRevoked())
                                    <button type="button" class="btn btn-link btn-sm p-0 ms-1 code-copy" data-copy="{{ $code->formatted() }}" title="Copy code">Copy</button>
                                @endunless
                            </td>
                            <td class="small">{{ $code->label ?? '—' }}<div class="text-muted" style="font-size:.7rem">{{ $code->created_at?->format('Y-m-d') }}@if($code->createdBy) · {{ $code->createdBy->username }}@endif</div></td>
                            <td class="small">{{ ucfirst($code->grants_role->value) }}</td>
                            <td class="text-end small">{{ $code->uses_count }}@if($code->max_uses) / {{ $code->max_uses }}@endif</td>
                            <td class="small text-nowrap">{{ $code->expires_at?->format('Y-m-d') ?? 'never' }}</td>
                            <td><span class="badge {{ $state[1] }}">{{ $state[0] }}</span></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    @unless($code->isRevoked())
                                        <a href="{{ route('admin.clubs.codes.print', $codeBase) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener">Print QR</a>
                                    @endunless
                                    <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for code {{ $code->masked() }}"><span aria-hidden="true">…</span></button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#code-action-modal" data-action="regenerate" data-url="{{ route('admin.clubs.codes.regenerate', $codeBase) }}" data-code="{{ $code->masked() }}">Regenerate…</button></li>
                                        @unless($code->isRevoked())
                                            <li><button type="button" class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#code-action-modal" data-action="revoke" data-url="{{ route('admin.clubs.codes.revoke', $codeBase) }}" data-code="{{ $code->masked() }}">Revoke…</button></li>
                                        @endunless
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No invite codes yet. Create one to let players join.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($codes->hasPages())<div class="mt-3">{{ $codes->links('pagination::bootstrap-5') }}</div>@endif
    </div>

    <div class="col-lg-4">
        <form method="POST" action="{{ route('admin.clubs.codes.store', $club) }}" class="card shadow-sm" data-section="new-code">
            @csrf
            <div class="card-header bg-white"><h2 class="h6 mb-0">New invite code</h2></div>
            <div class="card-body">
                @if($codeErrors->any())<div class="alert alert-danger py-2 small">{{ $codeErrors->first() }}</div>@endif
                <div class="mb-2">
                    <label for="code-label" class="form-label small fw-semibold">Label</label>
                    <input id="code-label" type="text" name="label" value="{{ old('label') }}" maxlength="60" class="form-control form-control-sm" placeholder="e.g. U15 parents, Season 26/27">
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label for="code-expires" class="form-label small fw-semibold">Expires</label>
                        <input id="code-expires" type="date" name="expires_at" value="{{ old('expires_at') }}" min="{{ now()->toDateString() }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-6">
                        <label for="code-max" class="form-label small fw-semibold">Max uses</label>
                        <input id="code-max" type="number" name="max_uses" value="{{ old('max_uses') }}" min="1" class="form-control form-control-sm" placeholder="unlimited">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="code-role" class="form-label small fw-semibold">Joins as</label>
                    <select id="code-role" name="grants_role" class="form-select form-select-sm">
                        <option value="member" {{ old('grants_role', 'member') === 'member' ? 'selected' : '' }}>Member</option>
                        <option value="admin" {{ old('grants_role') === 'admin' ? 'selected' : '' }}>Admin</option>
                    </select>
                    <div class="form-text">Ownership is never handed out by a code.</div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm w-100" {{ $activeCodes >= $codeLimit ? 'disabled' : '' }}>Create code</button>
                @if($activeCodes >= $codeLimit)<div class="form-text text-danger">Limit reached — revoke a code first.</div>@endif
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="code-action-modal" tabindex="-1" aria-labelledby="code-action-title" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="#" class="modal-content" id="code-action-form">
            @csrf
            <div class="modal-header">
                <h2 class="modal-title h5" id="code-action-title">Confirm</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body"><p id="code-action-text" class="mb-0"></p></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="code-action-submit">Confirm</button>
            </div>
        </form>
    </div>
</div>
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
        toggle.textContent = revealed ? 'Hide codes' : 'Show codes';
        toggle.setAttribute('aria-pressed', revealed ? 'true' : 'false');
    });
    document.querySelectorAll('.code-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var value = btn.getAttribute('data-copy');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(value).then(function () {
                    btn.textContent = 'Copied';
                    setTimeout(function () { btn.textContent = 'Copy'; }, 1500);
                });
            }
        });
    });
    var modal = document.getElementById('code-action-modal');
    if (!modal) return;
    var copy = {
        revoke:     { title: 'Revoke code', button: 'Revoke', cls: 'btn-danger', text: 'Code {c} stops working immediately. Members who already joined stay members.' },
        regenerate: { title: 'Regenerate code', button: 'Regenerate', cls: 'btn-warning', text: 'Code {c} is revoked and replaced by a new code with the same label, limits and role. Printed sheets with the old code stop working.' }
    };
    modal.addEventListener('show.bs.modal', function (event) {
        var t = event.relatedTarget;
        var c = t && copy[t.getAttribute('data-action')];
        if (!c) return;
        document.getElementById('code-action-form').setAttribute('action', t.getAttribute('data-url'));
        document.getElementById('code-action-title').textContent = c.title;
        document.getElementById('code-action-text').textContent = c.text.replace('{c}', t.getAttribute('data-code'));
        var btn = document.getElementById('code-action-submit');
        btn.textContent = c.button;
        btn.className = 'btn ' + c.cls;
    });
}());
</script>
@endpush
