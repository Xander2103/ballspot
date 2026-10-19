@include('admin.clubs._audit_filters', [
    'action' => route('admin.clubs.show', $club),
    'hidden' => ['tab' => 'audit'],
    'withClub' => false,
])
<div class="card shadow-sm">
    @include('admin.clubs._audit_table', ['events' => $events])
</div>
@if($events->hasPages())<div class="mt-3">{{ $events->links('pagination::bootstrap-5') }}</div>@endif
<p class="small text-muted mt-2 mb-0">Append-only. Personal data and full invite codes are never stored here. <a href="{{ route('admin.club-audit.index', ['club' => $club->id]) }}">Open in the global audit log</a>.</p>
