@php
    $mf = $memberFilters;
    $statusLabels = ['active' => 'Active', 'left' => 'Left', 'removed' => 'Removed', 'banned' => 'Banned'];
    $roleLabels = ['owner' => 'Owner', 'admin' => 'Admin', 'member' => 'Member'];
    $addErrors = $errors->getBag('addMember');
    $filtered = $mf['q'] !== '' || $mf['role'] !== '' || $mf['status'] !== 'active';
@endphp

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <form method="GET" action="{{ route('admin.clubs.show', $club) }}" class="row g-2 align-items-end" data-section="member-filters" role="search">
            <input type="hidden" name="tab" value="members">
            <div class="col-12 col-md-5">
                <label for="m-q" class="small text-muted mb-1">Search</label>
                <input id="m-q" type="search" name="q" value="{{ $mf['q'] }}" class="form-control form-control-sm" placeholder="username or email" maxlength="100">
            </div>
            <div class="col-6 col-md-2">
                <label for="m-role" class="small text-muted mb-1">Role</label>
                <select id="m-role" name="role" class="form-select form-select-sm">
                    <option value="">Any role</option>
                    @foreach($roleLabels as $value => $label)
                        <option value="{{ $value }}" {{ $mf['role'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label for="m-status" class="small text-muted mb-1">Status</label>
                <select id="m-status" name="status" class="form-select form-select-sm">
                    @foreach($statusLabels as $value => $label)
                        <option value="{{ $value }}" {{ $mf['status'] === $value ? 'selected' : '' }}>{{ $label }} ({{ (int) ($memberCounts[$value] ?? 0) }})</option>
                    @endforeach
                    <option value="any" {{ $mf['status'] === 'any' ? 'selected' : '' }}>Any status ({{ (int) $memberCounts->sum() }})</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
                @if($filtered)<a href="{{ route('admin.clubs.show', ['club' => $club, 'tab' => 'members']) }}" class="btn btn-link btn-sm">Reset</a>@endif
            </div>
        </form>
    </div>
    <div class="col-lg-4">
        <form method="POST" action="{{ route('admin.clubs.members.store', $club) }}" class="card card-body py-2 shadow-sm" data-section="add-member">
            @csrf
            <div class="small fw-semibold mb-1">Add an existing player</div>
            <div class="input-group input-group-sm">
                <input type="text" name="identifier" value="{{ old('identifier') }}" class="form-control {{ $addErrors->has('identifier') ? 'is-invalid' : '' }}" placeholder="username or email" aria-label="Username or email" maxlength="190" autocomplete="off" required>
                <select name="role" class="form-select" style="max-width: 7.5rem" aria-label="Role">
                    @foreach($roleLabels as $value => $label)
                        <option value="{{ $value }}" {{ old('role', 'member') === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-primary">Add</button>
            </div>
            @if($addErrors->any())<div class="text-danger small mt-1">{{ $addErrors->first() }}</div>@endif
            <div class="form-text">Players normally join with an invite code. Adding here skips the code.</div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover table-clubs mb-0">
            <thead class="table-light">
                <tr>
                    <th>Player</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Last seen</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($members as $member)
                @php
                    $u = $member->user;
                    $name = $u?->username ?? ('member #' . $member->id);
                    $st = $member->status->value;
                    $base = ['club' => $club, 'member' => $member];
                @endphp
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $name }}</div>
                        <div class="text-muted small">{{ $u?->name }}@if($u?->email && !$u->anonymized_at) · {{ $u->email }}@endif @if($u?->anonymized_at)<span class="badge badge-soft">deleted account</span>@endif</div>
                    </td>
                    <td>
                        <span class="badge {{ $member->role->value === 'owner' ? 'badge-active' : ($member->role->value === 'admin' ? 'badge-club' : 'badge-soft') }}">{{ $roleLabels[$member->role->value] }}</span>
                    </td>
                    <td>
                        <span class="badge {{ $st === 'active' ? 'badge-soft-ok' : ($st === 'banned' ? 'bg-danger' : 'badge-soft') }}">{{ $statusLabels[$st] }}</span>
                        @if($member->removal_reason && $st !== 'active')<div class="text-muted" style="font-size:.7rem" title="{{ $member->removal_reason }}">{{ \Illuminate\Support\Str::limit($member->removal_reason, 40) }}</div>@endif
                    </td>
                    <td class="small text-nowrap">
                        {{ $member->joined_at?->format('Y-m-d') ?? '—' }}
                        <div class="text-muted" style="font-size:.7rem">
                            @if($member->inviteCode) via code {{ $member->inviteCode->masked() }}
                            @elseif($member->addedBy) added by {{ $member->addedBy->username }}
                            @endif
                        </div>
                    </td>
                    <td class="small text-muted text-nowrap">{{ $member->last_seen_at?->diffForHumans(short: true) ?? '—' }}</td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for {{ $name }}">Actions</button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                @if($st === 'active')
                                    <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#member-action-modal" data-action="role" data-url="{{ route('admin.clubs.members.update', $base) }}" data-member="{{ $name }}" data-role="{{ $member->role->value }}">Change role…</button></li>
                                    @if($member->role->value !== 'owner')
                                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#member-action-modal" data-action="transfer" data-url="{{ route('admin.clubs.transfer-ownership', $club) }}" data-member="{{ $name }}" data-member-id="{{ $member->id }}">Make sole owner…</button></li>
                                    @endif
                                    <li><hr class="dropdown-divider"></li>
                                    <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#member-action-modal" data-action="remove" data-url="{{ route('admin.clubs.members.remove', $base) }}" data-member="{{ $name }}">Remove…</button></li>
                                @elseif($st !== 'banned')
                                    <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#member-action-modal" data-action="reactivate" data-url="{{ route('admin.clubs.members.reactivate', $base) }}" data-member="{{ $name }}">Reactivate…</button></li>
                                @endif
                                @if($st === 'banned')
                                    <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#member-action-modal" data-action="unban" data-url="{{ route('admin.clubs.members.unban', $base) }}" data-member="{{ $name }}">Lift ban…</button></li>
                                @else
                                    <li><button type="button" class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#member-action-modal" data-action="ban" data-url="{{ route('admin.clubs.members.ban', $base) }}" data-member="{{ $name }}">Ban…</button></li>
                                @endif
                            </ul>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">
                    @if($filtered)
                        No members match these filters<span>@if($mf['q'] !== '') for &ldquo;{{ $mf['q'] }}&rdquo;@endif</span>. <a href="{{ route('admin.clubs.show', ['club' => $club, 'tab' => 'members']) }}">Reset filters</a>
                    @else
                        No members yet. Create an <a href="{{ route('admin.clubs.show', ['club' => $club, 'tab' => 'codes']) }}">invite code</a> or add a player above.
                    @endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if($members->hasPages())<div class="mt-3">{{ $members->links('pagination::bootstrap-5') }}</div>@endif

{{-- Shared member action dialog --}}
<div class="modal fade" id="member-action-modal" tabindex="-1" aria-labelledby="member-action-title" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="#" class="modal-content" id="member-action-form">
            @csrf
            <input type="hidden" name="_method" value="POST" id="member-action-method">
            <input type="hidden" name="member_id" value="" id="member-action-member-id">
            <div class="modal-header">
                <h2 class="modal-title h5" id="member-action-title">Confirm</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="member-action-text" class="mb-2"></p>
                <div id="member-action-role-wrap" class="mb-2">
                    <label for="member-action-role" class="form-label fw-semibold">New role</label>
                    <select id="member-action-role" name="role" class="form-select">
                        @foreach($roleLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    <div class="form-text">A club always keeps at least one owner.</div>
                </div>
                <div id="member-action-reason-wrap">
                    <label for="member-action-reason" class="form-label fw-semibold">Reason <span class="text-muted fw-normal">(optional, admin only)</span></label>
                    <textarea id="member-action-reason" name="reason" class="form-control" rows="2" maxlength="200"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="member-action-submit">Confirm</button>
            </div>
        </form>
    </div>
</div>
@push('scripts')
<script>
(function () {
    var modal = document.getElementById('member-action-modal');
    if (!modal) return;
    var copy = {
        role:       { title: 'Change role', button: 'Save role', cls: 'btn-primary', method: 'PATCH', role: true, reason: false,
                      text: 'Choose the new role for {m}. Owners manage settings and ownership; admins manage codes, members and content.' },
        transfer:   { title: 'Make sole owner', button: 'Transfer ownership', cls: 'btn-warning', method: 'POST', role: false, reason: false,
                      text: '{m} becomes the only owner. Every current owner becomes an admin.' },
        remove:     { title: 'Remove member', button: 'Remove', cls: 'btn-danger', method: 'POST', role: false, reason: true,
                      text: '{m} loses access to the club. Their results are kept and they can re-join with a valid code.' },
        ban:        { title: 'Ban member', button: 'Ban', cls: 'btn-danger', method: 'POST', role: false, reason: true,
                      text: '{m} loses access and can never re-join with a code until the ban is lifted. Results are kept.' },
        unban:      { title: 'Lift ban', button: 'Lift ban', cls: 'btn-primary', method: 'POST', role: false, reason: false,
                      text: 'The ban on {m} is lifted. They are not a member again until they re-join with a code or you reactivate them.' },
        reactivate: { title: 'Reactivate membership', button: 'Reactivate', cls: 'btn-success', method: 'POST', role: false, reason: false,
                      text: '{m} becomes an active member again with their previous role (a former owner returns as admin).' }
    };
    modal.addEventListener('show.bs.modal', function (event) {
        var t = event.relatedTarget;
        if (!t) return;
        var c = copy[t.getAttribute('data-action')];
        if (!c) return;
        document.getElementById('member-action-form').setAttribute('action', t.getAttribute('data-url'));
        document.getElementById('member-action-method').value = c.method;
        document.getElementById('member-action-member-id').value = t.getAttribute('data-member-id') || '';
        document.getElementById('member-action-title').textContent = c.title;
        document.getElementById('member-action-text').textContent = c.text.replace('{m}', t.getAttribute('data-member') || 'this member');
        document.getElementById('member-action-role-wrap').style.display = c.role ? '' : 'none';
        document.getElementById('member-action-role').disabled = !c.role;
        if (c.role) document.getElementById('member-action-role').value = t.getAttribute('data-role') || 'member';
        document.getElementById('member-action-reason-wrap').style.display = c.reason ? '' : 'none';
        document.getElementById('member-action-reason').disabled = !c.reason;
        document.getElementById('member-action-reason').value = '';
        var btn = document.getElementById('member-action-submit');
        btn.textContent = c.button;
        btn.className = 'btn ' + c.cls;
    });
}());
</script>
@endpush
