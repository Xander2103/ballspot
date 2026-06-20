@extends('admin.layout')

@section('title', 'Challenges')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Challenges</h1>
    <a href="/admin/challenges/create" class="btn btn-primary btn-sm">+ New Challenge</a>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Difficulty</th>
                    <th>Status</th>
                    <th>Ball X</th>
                    <th>Ball Y</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($challenges as $challenge)
                <tr>
                    <td class="text-muted small align-middle">{{ $challenge->id }}</td>
                    <td class="align-middle fw-semibold">{{ $challenge->title }}</td>
                    <td class="align-middle">
                        <span class="badge badge-{{ $challenge->difficulty }}">{{ ucfirst($challenge->difficulty) }}</span>
                    </td>
                    <td class="align-middle">
                        <span class="badge badge-{{ $challenge->status }}">{{ ucfirst($challenge->status) }}</span>
                    </td>
                    <td class="align-middle small">{{ $challenge->ball_x_ratio }}</td>
                    <td class="align-middle small">{{ $challenge->ball_y_ratio }}</td>
                    <td class="align-middle">
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
                    <td colspan="7" class="text-center text-muted py-4">No challenges yet. <a href="/admin/challenges/create">Create one.</a></td>
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
