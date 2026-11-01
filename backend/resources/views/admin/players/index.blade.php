@extends('admin.layout')

@section('title', 'Players')

@section('content')
<div class="d-flex justify-content-between align-items-baseline mb-3">
    <h1 class="h4 mb-0">Players</h1>
    <span class="text-muted small">{{ $players->total() }} {{ \Illuminate\Support\Str::plural('account', $players->total()) }}</span>
</div>

<form method="GET" action="{{ route('admin.players.index') }}" class="row g-2 mb-3" data-player-search role="search">
    <div class="col-md">
        <label for="player-q" class="visually-hidden">Search players</label>
        <input id="player-q" type="search" name="q" value="{{ $q }}" maxlength="100" class="form-control @error('q') is-invalid @enderror"
               placeholder="Username, name, email or user ID" autofocus autocomplete="off">
        @error('q')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-auto">
        <label for="player-status" class="visually-hidden">Account status</label>
        <select id="player-status" name="status" class="form-select">
            @foreach(['all' => 'All accounts', 'active' => 'Active', 'unverified' => 'Email not verified', 'deleted' => 'Deleted'] as $value => $label)
                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-auto d-flex gap-2">
        <button type="submit" class="btn btn-primary" data-player-search-submit>
            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true" data-player-search-spinner></span>Search
        </button>
        @if($q !== '' || $status !== 'all')
            <a href="{{ route('admin.players.index') }}" class="btn btn-outline-secondary">Clear</a>
        @endif
    </div>
</form>

@if($failed)
    <div class="alert alert-danger" role="alert" data-player-search-error>
        The search could not be completed. Try again in a moment; if it keeps failing, check Diagnostics.
    </div>
@elseif($players->isEmpty())
    <div class="card shadow-sm" data-player-search-empty>
        <div class="card-body text-center text-muted py-5">
            @if($q !== '')
                No players match “{{ $q }}”. Check the spelling, or search on part of the name, email or username.
            @else
                No accounts with this status.
            @endif
        </div>
    </div>
@else
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr><th>ID</th><th>Username</th><th>Name</th><th>Email</th><th>Status</th><th>Registered</th></tr>
                </thead>
                <tbody>
                @foreach($players as $player)
                    <tr>
                        <td class="text-muted">{{ $player->id }}</td>
                        <td><a href="{{ route('admin.players.show', $player->id) }}" class="fw-semibold">{{ $player->username }}</a></td>
                        <td>{{ $player->name }}</td>
                        <td>{{ $player->email }}</td>
                        <td>@include('admin.players._status', ['player' => $player])</td>
                        <td class="text-nowrap">{{ $player->created_at?->format('Y-m-d') ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $players->links('pagination::bootstrap-5') }}</div>
@endif

<script>
document.querySelector('[data-player-search]').addEventListener('submit', function () {
    var button = this.querySelector('[data-player-search-submit]');
    button.disabled = true;
    this.querySelector('[data-player-search-spinner]').classList.remove('d-none');
    this.setAttribute('aria-busy', 'true');
});
</script>
@endsection
