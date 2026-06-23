@extends('admin.layout')

@section('title', 'Challenges')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Challenges</h1>
    <a href="/admin/challenges/create" class="btn btn-primary btn-sm">+ New Challenge</a>
</div>

{{-- Filters --}}
<form method="GET" action="/admin/challenges" class="mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-auto">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach(['draft','active','archived'] as $s)
                    <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <select name="difficulty" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All difficulties</option>
                @foreach(['easy','medium','hard'] as $d)
                    <option value="{{ $d }}" {{ request('difficulty') === $d ? 'selected' : '' }}>{{ ucfirst($d) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <select name="category" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All categories</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat->id }}" {{ request('category') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>
        @if(request('status') || request('difficulty') || request('category'))
        <div class="col-auto">
            <a href="/admin/challenges" class="btn btn-outline-secondary btn-sm">Clear</a>
        </div>
        @endif
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:80px">Hidden</th>
                    <th style="width:80px">Reveal</th>
                    <th>Title</th>
                    <th>Category</th>
                    <th>Difficulty</th>
                    <th>Status</th>
                    <th>Ball position</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($challenges as $challenge)
                <tr>
                    <td>
                        @if($challenge->hidden_image_path)
                        <img src="{{ asset('storage/' . $challenge->hidden_image_path) }}"
                             alt="hidden" style="width:64px;height:48px;object-fit:cover;border-radius:4px;">
                        @else
                        <span class="text-muted small">—</span>
                        @endif
                    </td>
                    <td>
                        @if($challenge->original_image_path)
                        <img src="{{ asset('storage/' . $challenge->original_image_path) }}"
                             alt="reveal" style="width:64px;height:48px;object-fit:cover;border-radius:4px;">
                        @else
                        <span class="text-muted small">—</span>
                        @endif
                    </td>
                    <td class="fw-semibold">{{ $challenge->title }}</td>
                    <td class="small text-muted">{{ $challenge->category?->name ?? '—' }}</td>
                    <td>
                        <span class="badge badge-{{ $challenge->difficulty }}">{{ ucfirst($challenge->difficulty) }}</span>
                    </td>
                    <td>
                        <span class="badge badge-{{ $challenge->status }}">{{ ucfirst($challenge->status) }}</span>
                    </td>
                    <td class="small text-muted">
                        x {{ round($challenge->ball_x_ratio * 100) }}%
                        · y {{ round($challenge->ball_y_ratio * 100) }}%
                    </td>
                    <td class="small text-muted">{{ $challenge->created_at->format('d M Y') }}</td>
                    <td>
                        <a href="/admin/challenges/{{ $challenge->id }}/edit" class="btn btn-outline-secondary btn-sm me-1">Edit</a>
                        <form action="/admin/challenges/{{ $challenge->id }}" method="POST" class="d-inline"
                              onsubmit="return confirm('Delete this challenge?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">
                        No challenges yet. <a href="/admin/challenges/create">Create one.</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($challenges->hasPages())
<div class="mt-3">
    {{ $challenges->links() }}
</div>
@endif
@endsection
