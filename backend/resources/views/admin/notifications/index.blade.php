@extends('admin.layout')

@section('title', 'Notifications')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Announcements</h1>
</div>

@unless($pushEnabled)
    <div class="alert alert-warning small">
        <strong>Push not configured.</strong> Announcements will be saved as drafts but not delivered.
        Set <code>BALLPICKER_PUSH_ENABLED=true</code> and register devices to enable delivery.
    </div>
@endunless

<p class="text-muted small mb-3">
    Send an opt-in announcement to players' devices via Expo push. Plain text only —
    no HTML. Players who disabled admin announcements are never delivered to.
</p>

@if($errors->any())
    <div class="alert alert-danger small">
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form action="{{ route('admin.notifications.store') }}" method="POST">
            @csrf
            <div class="mb-3">
                <label class="form-label" for="title">Title</label>
                <input type="text" class="form-control" id="title" name="title"
                       maxlength="120" value="{{ old('title') }}" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="body">Message</label>
                <textarea class="form-control" id="body" name="body" rows="3"
                          maxlength="500" required>{{ old('body') }}</textarea>
                <div class="form-text">Max 500 characters. Plain text.</div>
            </div>
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label" for="target_type">Audience</label>
                    <select class="form-select" id="target_type" name="target_type">
                        <option value="opted_in" {{ old('target_type') === 'opted_in' ? 'selected' : '' }}>
                            Users who opted in
                        </option>
                        <option value="all" {{ old('target_type') === 'all' ? 'selected' : '' }}>
                            All users (except opted-out)
                        </option>
                    </select>
                </div>
                <div class="col-md-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="send_now" name="send_now" value="1">
                        <label class="form-check-label" for="send_now">Send now</label>
                    </div>
                </div>
                <div class="col-md-3 text-md-end">
                    <button type="submit" class="btn btn-primary w-100">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Title</th>
                    <th>Audience</th>
                    <th>Status</th>
                    <th>Sent</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($notifications as $n)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $n->title }}</div>
                            <div class="text-muted small">{{ \Illuminate\Support\Str::limit($n->body, 60) }}</div>
                        </td>
                        <td class="small">{{ $n->target_type === 'all' ? 'All users' : 'Opted in' }}</td>
                        <td>
                            @if($n->status === 'sent')
                                <span class="badge bg-success">Sent</span>
                            @elseif($n->status === 'failed')
                                <span class="badge bg-danger">Failed</span>
                            @else
                                <span class="badge badge-draft">Draft</span>
                            @endif
                        </td>
                        <td class="small">
                            @if($n->metadata && isset($n->metadata['recipients']))
                                {{ $n->metadata['sent'] ?? 0 }}/{{ $n->metadata['recipients'] }}
                            @else
                                &mdash;
                            @endif
                        </td>
                        <td class="small text-muted">{{ $n->created_at->diffForHumans() }}</td>
                        <td class="text-end">
                            @if($n->status !== 'sent')
                                <form action="{{ route('admin.notifications.send', $n) }}" method="POST" class="mb-0">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Send</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No announcements yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">
    {{ $notifications->links() }}
</div>
@endsection
