@extends('club-portal.layout')

@section('title', __('club_portal.competitions.title') . ' · ' . $club->name)

@section('content')
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
    <div>
        <h1 class="h4 mb-1">{{ __('club_portal.competitions.title') }}</h1>
        <p class="text-muted small mb-0">{{ __('club_portal.competitions.intro') }}</p>
    </div>
    @if($canManage)<a href="{{ route('club-portal.competitions.create', $club) }}" class="btn btn-primary btn-sm">{{ __('club_portal.competitions.new') }}</a>@endif
</div>

<div class="d-flex flex-wrap gap-2 my-3" data-section="competition-filters">
    <a href="{{ route('club-portal.competitions.index', $club) }}" class="badge text-decoration-none {{ $phase === '' ? 'bg-primary' : 'badge-soft' }}">{{ __('club_portal.competitions.all') }}</a>
    @foreach($phases as $p)
        <a href="{{ route('club-portal.competitions.index', ['club' => $club, 'phase' => $p]) }}" class="badge text-decoration-none {{ $phase === $p ? 'bg-primary' : 'badge-soft' }}">{{ __('club_portal.competitions.phase.' . $p) }}</a>
    @endforeach
</div>

<div class="portal-card">
    <div class="table-responsive">
        <table class="table table-hover mb-0" data-section="competition-list">
            <thead>
                <tr>
                    <th>{{ __('club_portal.competitions.col_name') }}</th>
                    <th>{{ __('club_portal.competitions.col_window') }}</th>
                    <th class="text-end">{{ __('club_portal.competitions.col_packs') }}</th>
                    <th class="text-end">{{ __('club_portal.competitions.col_players') }}</th>
                    <th>{{ __('club_portal.competitions.col_phase') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse($competitions as $competition)
                @php $tz = $competition->timezone; @endphp
                <tr>
                    <td><a href="{{ route('club-portal.competitions.show', [$club, $competition]) }}" class="fw-semibold">{{ $competition->name }}</a></td>
                    <td class="small text-nowrap">{{ $competition->starts_at?->copy()->setTimezone($tz)->format('Y-m-d H:i') }} – {{ $competition->ends_at?->copy()->setTimezone($tz)->format('Y-m-d H:i') }}</td>
                    <td class="text-end small">{{ $competition->competition_packs_count }}</td>
                    <td class="text-end small">{{ (int) $competition->participants_count }}</td>
                    <td>@include('club-portal.competitions._phase')</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">{{ __('club_portal.competitions.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if($competitions->hasPages())<div class="mt-3">{{ $competitions->links('pagination::bootstrap-5') }}</div>@endif
@endsection
