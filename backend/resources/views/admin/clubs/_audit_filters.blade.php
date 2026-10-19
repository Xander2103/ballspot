{{-- Audit filters. Expects $auditFilters, $action (form URL), optional $hidden (array of hidden inputs), $withClub (bool), $filterClub. --}}
<form method="GET" action="{{ $action }}" class="row g-2 align-items-end mb-3" data-section="audit-filters">
    @foreach(($hidden ?? []) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
    @if($withClub ?? false)
    <div class="col-6 col-md-2">
        <label for="a-club" class="small text-muted mb-1">Club ID</label>
        <input id="a-club" type="text" inputmode="numeric" name="club" value="{{ $auditFilters['club'] }}" class="form-control form-control-sm" placeholder="any">
    </div>
    @endif
    <div class="col-12 col-md-3">
        <label for="a-action" class="small text-muted mb-1">Action</label>
        <select id="a-action" name="action" class="form-select form-select-sm">
            <option value="">All actions</option>
            @foreach(\App\Models\ClubAuditEvent::ACTIONS as $a)
                <option value="{{ $a }}" {{ $auditFilters['action'] === $a ? 'selected' : '' }}>{{ __('clubs.audit.' . $a) }} ({{ $a }})</option>
            @endforeach
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label for="a-actor" class="small text-muted mb-1">Actor</label>
        <input id="a-actor" type="text" name="actor" value="{{ $auditFilters['actor'] }}" class="form-control form-control-sm" placeholder="username, email or id">
    </div>
    <div class="col-6 col-md-2">
        <label for="a-kind" class="small text-muted mb-1">Actor kind</label>
        <select id="a-kind" name="actor_kind" class="form-select form-select-sm">
            <option value="">Any</option>
            @foreach(\App\Models\ClubAuditEvent::ACTOR_KINDS as $k)
                <option value="{{ $k }}" {{ $auditFilters['actor_kind'] === $k ? 'selected' : '' }}>{{ str_replace('_', ' ', $k) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-6 col-md-1">
        <label for="a-from" class="small text-muted mb-1">From</label>
        <input id="a-from" type="date" name="from" value="{{ $auditFilters['from'] }}" class="form-control form-control-sm">
    </div>
    <div class="col-6 col-md-1">
        <label for="a-to" class="small text-muted mb-1">To</label>
        <input id="a-to" type="date" name="to" value="{{ $auditFilters['to'] }}" class="form-control form-control-sm">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        @if($auditFiltered)<a href="{{ $action . (($hidden ?? []) ? '?' . http_build_query($hidden) : '') }}" class="btn btn-link btn-sm">Reset</a>@endif
    </div>
</form>
