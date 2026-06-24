@extends('admin.layout')

@section('title', 'Daily Challenges')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Daily Challenges</h1>
    <a href="{{ route('admin.daily.create') }}" class="btn btn-primary btn-sm">+ New Daily Challenge</a>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show" role="alert">
    {{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Challenge Title</th>
                    <th>Status</th>
                    <th>Guesses</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($dailyChallenges as $daily)
                <tr>
                    <td class="fw-semibold">{{ $daily->challenge_date->format('d M Y') }}</td>
                    <td>{{ $daily->challenge?->title ?? '—' }}</td>
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
                    <td colspan="5" class="text-center text-muted py-4">
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
