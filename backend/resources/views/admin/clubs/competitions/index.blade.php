{{-- PLACEHOLDER (B2). Owned by C1 (Competitions): replace with the cross-club competition list. --}}
@extends('admin.layout')

@section('title', 'Club competitions')

@section('content')
<h1 class="h4 mb-1">Club competitions</h1>
<p class="text-muted small mb-4">Cross-club list of private club competitions.</p>
<div class="card shadow-sm" style="max-width: 760px;" data-section="placeholder">
    <div class="card-body">
        <h2 class="h6">Being built in this sprint</h2>
        <p class="mb-2">The competition editor (window, linked packs, replay rules, publish / cancel / void, standings and rebuild) arrives in the next phase.</p>
        <p class="small text-muted mb-0">{{ $total }} {{ $total === 1 ? 'competition exists' : 'competitions exist' }}. Until then each club's competitions are listed read-only on its <a href="{{ route('admin.clubs.index') }}">club page</a> → Competitions tab.</p>
    </div>
</div>
@endsection
