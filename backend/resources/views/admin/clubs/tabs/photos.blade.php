@php
    $statusLabels = ['pending' => 'Pending', 'in_review' => 'In review', 'in_preparation' => 'In preparation', 'converted' => 'Converted', 'rejected' => 'Rejected'];
    $statusClass = ['pending' => 'badge-soft-warn', 'in_review' => 'badge-scheduled', 'in_preparation' => 'badge-active', 'converted' => 'badge-ok', 'rejected' => 'badge-archived'];
    $media = app(\App\Services\Clubs\ClubMediaService::class);
@endphp
<div class="card shadow-sm mb-3" data-section="photo-upload">
    <div class="card-body">
        <h2 class="h6 mb-1">Upload photos for {{ $club->name }}</h2>
        <p class="text-muted small mb-2">Added as <em>pending</em> to the <a href="{{ route('admin.club-photos.index', ['club' => $club->id]) }}">Photo review</a> queue. Stored privately, re-encoded (EXIF/GPS removed); duplicates of this club are refused.</p>
        <form method="POST" action="{{ route('admin.clubs.photos.store', $club) }}" enctype="multipart/form-data" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-5">
                <label for="photos" class="form-label small mb-1">Photos (up to {{ \App\Http\Controllers\Admin\Clubs\ClubPhotoController::MAX_FILES }})</label>
                <input type="file" id="photos" name="photos[]" multiple required accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm @error('photos') is-invalid @enderror @error('photos.*') is-invalid @enderror">
                @error('photos')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @error('photos.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label for="club_note" class="form-label small mb-1">Note <span class="text-muted">(optional)</span></label>
                <input type="text" id="club_note" name="club_note" maxlength="500" value="{{ old('club_note') }}" class="form-control form-control-sm" placeholder="e.g. derby goal min 89">
            </div>
            <div class="col-md-3">
                <div class="form-check small">
                    <input type="checkbox" class="form-check-input" id="rights_confirmed" name="rights_confirmed" value="1" {{ old('rights_confirmed') ? 'checked' : '' }}>
                    <label class="form-check-label" for="rights_confirmed">The club confirmed it holds the rights and the people shown agreed</label>
                </div>
                <button class="btn btn-primary btn-sm mt-1">Upload</button>
            </div>
        </form>
    </div>
</div>
<div class="d-flex justify-content-between align-items-center gap-3 mb-2 flex-wrap">
    <a href="{{ route('admin.club-photos.index', ['club' => $club->id]) }}" class="small">Open this club in Photo review →</a>
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
                <tr><th>Photo</th><th>Submitted</th><th>Status</th><th>Size</th><th>Note</th><th>Assignee</th><th class="text-end"></th></tr>
            </thead>
            <tbody>
            @forelse($photos as $photo)
                <tr>
                    <td>
                        @if($photo->path && \App\Services\Clubs\ClubMediaService::isSafePath($photo->path))
                            <a href="{{ route('admin.club-photos.prepare', $photo) }}"><img src="{{ $media->signedUrl($photo->path) }}" alt="Photo submission {{ $photo->id }}" class="thumb" loading="lazy"></a>
                        @else
                            <span class="thumb-missing text-muted">no file</span>
                        @endif
                    </td>
                    <td class="small text-nowrap">{{ $photo->created_at?->format('Y-m-d H:i') }}<div class="text-muted" style="font-size:.7rem">{{ $photo->uploadedBy?->username ?? '—' }} · {{ str_replace('_', ' ', $photo->source) }}</div></td>
                    <td><span class="badge {{ $statusClass[$photo->status->value] ?? 'badge-soft' }}">{{ $statusLabels[$photo->status->value] ?? $photo->status->value }}</span>@if($photo->rights_confirmed_at) <span class="text-success small" title="Rights confirmed">rights ✓</span>@endif</td>
                    <td class="small text-nowrap">{{ $photo->width }}×{{ $photo->height }}<div class="text-muted" style="font-size:.7rem">{{ number_format(($photo->bytes ?? 0) / 1048576, 1) }} MB</div></td>
                    <td class="small">{{ \Illuminate\Support\Str::limit($photo->club_note ?? '', 80) ?: '—' }}@if($photo->rejection_reason)<div class="text-danger" style="font-size:.7rem">{{ \Illuminate\Support\Str::limit($photo->rejection_reason, 80) }}</div>@endif</td>
                    <td class="small">{{ $photo->assignedTo?->username ?? '—' }}</td>
                    <td class="text-end"><a href="{{ route('admin.club-photos.prepare', $photo) }}" class="btn btn-sm {{ $photo->isOpen() ? 'btn-outline-primary' : 'btn-outline-secondary' }}">{{ $photo->isOpen() ? 'Prepare →' : 'View' }}</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No photo submissions{{ $photoStatus !== '' ? ' with this status' : ' yet' }}.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if($photos->hasPages())<div class="mt-3">{{ $photos->links('pagination::bootstrap-5') }}</div>@endif
