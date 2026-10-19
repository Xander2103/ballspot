@extends('club-portal.layout')

@section('title', __('club_portal.photos.title') . ' · ' . $club->name)

@php
    $media = app(\App\Services\Clubs\ClubMediaService::class);
    $badge = ['pending' => 'badge-soft-warn', 'in_review' => 'badge-soft-info', 'in_preparation' => 'badge-soft-info', 'converted' => 'badge-soft-ok', 'rejected' => 'badge-soft-danger'];
    $tz = $club->timezone ?? config('app.timezone');
@endphp

@section('content')
<h1 class="h4 mb-1">{{ __('club_portal.photos.title') }}</h1>
<p class="text-muted small">{{ __('club_portal.photos.intro') }}</p>

@if($canUpload)
<form method="POST" action="{{ route('club-portal.photos.store', $club) }}" enctype="multipart/form-data" class="portal-card p-3 mb-3" data-section="photo-upload">
    @csrf
    <h2 class="h6">{{ __('club_portal.photos.upload_heading') }}</h2>
    <div class="row g-2">
        <div class="col-md-6">
            <label for="photos" class="form-label small">{{ __('club_portal.photos.files', ['max' => $maxFiles]) }}</label>
            <input type="file" id="photos" name="photos[]" multiple required accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm @error('photos') is-invalid @enderror @error('photos.*') is-invalid @enderror">
            @error('photos')<div class="invalid-feedback">{{ $message }}</div>@enderror
            @error('photos.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label for="club_note" class="form-label small">{{ __('club_portal.photos.note') }} <span class="text-muted">({{ __('club_portal.common.optional') }})</span></label>
            <input type="text" id="club_note" name="club_note" maxlength="500" value="{{ old('club_note') }}" class="form-control form-control-sm @error('club_note') is-invalid @enderror" placeholder="{{ __('club_portal.photos.note_placeholder') }}">
            @error('club_note')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12">
            <div class="form-check">
                <input type="checkbox" class="form-check-input @error('rights_confirmed') is-invalid @enderror" id="rights_confirmed" name="rights_confirmed" value="1" required @checked(old('rights_confirmed'))>
                <label class="form-check-label small" for="rights_confirmed">{{ __('club_portal.photos.rights') }}</label>
                @error('rights_confirmed')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="col-12"><button type="submit" class="btn btn-primary btn-sm">{{ __('club_portal.photos.submit') }}</button></div>
    </div>
</form>
@endif

<div class="d-flex flex-wrap gap-2 mb-2" data-section="photo-filters">
    <a href="{{ route('club-portal.photos.index', $club) }}" class="badge text-decoration-none {{ $status === '' ? 'bg-primary' : 'badge-soft' }}">{{ __('club_portal.photos.all_statuses') }}</a>
    @foreach($counts as $value => $count)
        <a href="{{ route('club-portal.photos.index', ['club' => $club, 'status' => $value]) }}" class="badge text-decoration-none {{ $status === $value ? 'bg-primary' : ($badge[$value] ?? 'badge-soft') }}">{{ __('club_portal.photo_status.' . $value) }} · {{ $count }}</a>
    @endforeach
</div>

<div class="portal-card">
    <div class="table-responsive">
        <table class="table mb-0" data-section="photo-list">
            <thead>
                <tr>
                    <th>{{ __('club_portal.photos.col_photo') }}</th>
                    <th>{{ __('club_portal.photos.col_submitted') }}</th>
                    <th>{{ __('club_portal.photos.col_status') }}</th>
                    <th>{{ __('club_portal.photos.col_note') }}</th>
                    <th class="text-end"></th>
                </tr>
            </thead>
            <tbody>
            @forelse($photos as $photo)
                <tr>
                    <td>
                        @if($photo->path && \App\Services\Clubs\ClubMediaService::isSafePath($photo->path))
                            <img src="{{ $media->signedUrl($photo->path) }}" alt="#{{ $photo->id }}" class="thumb" loading="lazy">
                        @else
                            <span class="thumb-missing text-muted">{{ __('club_portal.photos.no_file') }}</span>
                        @endif
                    </td>
                    <td class="small text-nowrap">{{ $photo->created_at?->copy()->setTimezone($tz)->format('Y-m-d H:i') }}<div class="text-muted" style="font-size:.7rem">{{ __('club_portal.photos.by', ['name' => $photo->uploadedBy?->username ?? __('club_portal.common.deleted_user')]) }}</div></td>
                    <td><span class="badge {{ $badge[$photo->status->value] ?? 'badge-soft' }}">{{ __('club_portal.photo_status.' . $photo->status->value) }}</span></td>
                    <td class="small">
                        {{ \Illuminate\Support\Str::limit($photo->club_note ?? '', 100) ?: __('club_portal.common.none') }}
                        @if($photo->status->value === 'rejected' && $photo->rejection_reason)
                            <div class="text-danger small">{{ __('club_portal.photos.rejection', ['reason' => $photo->rejection_reason]) }}</div>
                        @endif
                    </td>
                    <td class="text-end">
                        @if($canUpload && $photo->status->value === 'pending' && $photo->source === \App\Models\ClubPhotoSubmission::SOURCE_CLUB_UPLOAD && $photo->assigned_to_user_id === null)
                            <form method="POST" action="{{ route('club-portal.photos.withdraw', [$club, $photo]) }}" onsubmit="return confirm(this.dataset.confirm)" data-confirm="{{ __('club_portal.photos.withdraw_confirm') }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-danger btn-sm">{{ __('club_portal.photos.withdraw') }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">{{ __('club_portal.photos.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if($photos->hasPages())<div class="mt-3">{{ $photos->links('pagination::bootstrap-5') }}</div>@endif
@endsection
