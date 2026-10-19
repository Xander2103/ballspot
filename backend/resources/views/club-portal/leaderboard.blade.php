@extends('club-portal.layout')

@section('title', __('club_portal.leaderboard.title') . ' · ' . $club->name)

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">{{ __('club_portal.leaderboard.title') }}</h1>
        <p class="text-muted small mb-0">{{ __('club_portal.leaderboard.intro') }}</p>
    </div>
    <a href="{{ route('club-portal.leaderboard.csv', $club) }}" class="btn btn-outline-secondary btn-sm" data-section="leaderboard-csv">{{ __('club_portal.leaderboard.download_csv') }}</a>
</div>

<div class="portal-card" data-section="leaderboard">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>{{ __('club_portal.competitions.col_rank') }}</th>
                    <th>{{ __('club_portal.competitions.col_player') }}</th>
                    <th class="text-end">{{ __('club_portal.competitions.col_total') }}</th>
                    <th class="text-end">{{ __('club_portal.competitions.col_completed') }}</th>
                    <th class="text-end">{{ __('club_portal.competitions.col_attempts') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse($entries as $row)
                <tr>
                    <td class="fw-bold">{{ $row['rank'] }}</td>
                    <td>{{ $row['username'] }}</td>
                    <td class="text-end">{{ number_format($row['total_score']) }}</td>
                    <td class="text-end">{{ $row['packs_completed'] }}</td>
                    <td class="text-end">{{ $row['attempts_used'] }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">{{ __('club_portal.leaderboard.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if($entries->hasPages())<div class="mt-3">{{ $entries->links('pagination::bootstrap-5') }}</div>@endif
<p class="small text-muted mt-2">{{ __('club_portal.leaderboard.csv_note') }}</p>
@endsection
