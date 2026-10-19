@php
    $statusLabels = ['pending' => 'Pending', 'in_review' => 'In review', 'in_preparation' => 'In preparation', 'converted' => 'Converted', 'rejected' => 'Rejected'];
    $statusClass = ['pending' => 'badge-soft-warn', 'in_review' => 'badge-scheduled', 'in_preparation' => 'badge-active', 'converted' => 'badge-ok', 'rejected' => 'badge-archived'];
    $media = app(\App\Services\Clubs\ClubMediaService::class);
@endphp
<div class="d-flex justify-content-between align-items-center gap-3 mb-2 flex-wrap">
    <div class="alert alert-info py-2 small mb-0 flex-grow-1">
        Read-only list. Upload on behalf of the club, assignment, preparation and conversion to a challenge are being built in this sprint
        (<a href="{{ route('admin.club-photos.index') }}">Photo review</a>).
    </div>
    <form method="GET" action="{{ route('admin.clubs.show', $club) }}" class="d-flex gap-2">
        <input type="hidden" name="tab" value="photos">
        <label for="p-status" class="visually-hidden">Status</label>
        <select id="p-status" name="status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All statuses</option>
            @foreach($statusLabels as $value => $label)
                <option value="{{ $value }}" {{ $photoStatus === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
        <noscript><button class="btn btn-secondary btn-sm">Filter</button></noscript>
    </form>
</div>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover table-clubs mb-0">
            <thead class="table-light">
                <tr><th>Photo</th><th>Submitted</th><th>Status</th><th>Size</th><th>Note</th><th>Assignee</th></tr>
            </thead>
            <tbody>
            @forelse($photos as $photo)
                <tr>
                    <td>
                        @if($photo->path && \App\Services\Clubs\ClubMediaService::isSafePath($photo->path))
                            <img src="{{ $media->signedUrl($photo->path) }}" alt="Photo submission {{ $photo->id }}" class="thumb" loading="lazy">
                        @else
                            <span class="thumb-missing text-muted">no file</span>
                        @endif
                    </td>
                    <td class="small text-nowrap">{{ $photo->created_at?->format('Y-m-d H:i') }}<div class="text-muted" style="font-size:.7rem">{{ $photo->uploadedBy?->username ?? '—' }} · {{ str_replace('_', ' ', $photo->source) }}</div></td>
                    <td><span class="badge {{ $statusClass[$photo->status->value] ?? 'badge-soft' }}">{{ $statusLabels[$photo->status->value] ?? $photo->status->value }}</span>@if($photo->rights_confirmed_at) <span class="text-success small" title="Rights confirmed">rights ✓</span>@endif</td>
                    <td class="small text-nowrap">{{ $photo->width }}×{{ $photo->height }}<div class="text-muted" style="font-size:.7rem">{{ number_format(($photo->bytes ?? 0) / 1048576, 1) }} MB</div></td>
                    <td class="small">{{ \Illuminate\Support\Str::limit($photo->club_note ?? '', 80) ?: '—' }}@if($photo->rejection_reason)<div class="text-danger" style="font-size:.7rem">{{ \Illuminate\Support\Str::limit($photo->rejection_reason, 80) }}</div>@endif</td>
                    <td class="small">{{ $photo->assignedTo?->username ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No photo submissions{{ $photoStatus !== '' ? ' with this status' : ' yet' }}.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if($photos->hasPages())<div class="mt-3">{{ $photos->links('pagination::bootstrap-5') }}</div>@endif
