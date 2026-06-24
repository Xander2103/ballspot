@extends('admin.layout')

@section('title', 'Daily Challenges')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Daily Challenges</h1>
    <a href="{{ route('admin.daily.create') }}" class="btn btn-primary btn-sm">+ New Daily Challenge</a>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Challenge</th>
                    <th>Readiness</th>
                    <th>Status</th>
                    <th>Guesses</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($dailyChallenges as $daily)
                @php $ch = $daily->challenge; @endphp
                <tr>
                    <td class="fw-semibold">{{ $daily->challenge_date->format('d M Y') }}</td>
                    <td>
                        @if($ch)
                            <a href="/admin/challenges/{{ $ch->id }}/edit" class="text-decoration-none fw-semibold">
                                {{ $ch->title }}
                            </a>
                            @if($ch->isDemoContent())
                                <span class="badge bg-warning text-dark ms-1 small">Demo</span>
                            @endif
                        @else
                            <span class="text-danger">Challenge deleted</span>
                        @endif
                    </td>
                    <td>
                        @if(!$ch)
                            <span class="badge bg-danger">Missing</span>
                        @elseif($ch->isReadyForDaily())
                            <span class="badge bg-success">Ready</span>
                        @elseif(!$ch->hidden_image_path)
                            <span class="badge bg-danger" title="No hidden image">No image</span>
                        @elseif($ch->status !== 'active')
                            <span class="badge bg-warning text-dark">Not active</span>
                        @else
                            <span class="badge bg-danger">Incomplete</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge badge-{{ $daily->status }}">{{ ucfirst($daily->status) }}</span>
                    </td>
                    <td>{{ $daily->guesses_count }}</td>
                    <td>
                        <form action="{{ route('admin.daily.updateStatus', $daily) }}" method="POST" class="d-inline d-flex gap-2 align-items-center">
                            @csrf
                            @method('PATCH')
                            <select name="status" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                                @foreach(['scheduled','active','archived'] as $s)
                                <option value="{{ $s }}" {{ $daily->status === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                                @endforeach
                            </select>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        No daily challenges yet. <a href="{{ route('admin.daily.create') }}">Create one.</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($dailyChallenges->hasPages())
<div class="mt-3">
    {{ $dailyChallenges->links() }}
</div>
@endif
@endsection
