{{-- PLACEHOLDER (B2). Owned by D1 (Photos): replace with the global photo review queue. --}}
@extends('admin.layout')

@section('title', 'Photo review')

@section('content')
<h1 class="h4 mb-1">Photo review</h1>
<p class="text-muted small mb-4">Global queue of photos submitted for clubs.</p>
<div class="card shadow-sm" style="max-width: 760px;" data-section="placeholder">
    <div class="card-body">
        <h2 class="h6">Being built in this sprint</h2>
        <p class="mb-2">Upload on behalf of a club, assign, prepare as a challenge and reject with a reason arrive in the photo phase.</p>
        <p class="small text-muted mb-0">{{ $open }} open {{ $open === 1 ? 'submission' : 'submissions' }}. Each club's submissions are listed read-only on its <a href="{{ route('admin.clubs.index') }}">club page</a> → Photos tab.</p>
    </div>
</div>
@endsection
