{{-- Club identity / location / contact fields. Expects $club (null on create), $sports, $types, $timezones, $defaultTz. --}}
@php
    $typeLabels = ['sports_club' => 'Sports club', 'school' => 'School', 'company' => 'Company', 'community' => 'Community', 'other' => 'Other'];
    $val = fn (string $key, $default = '') => old($key, $club?->{$key} instanceof \BackedEnum ? $club->{$key}->value : ($club?->{$key} ?? $default));
@endphp

<h2 class="h6 text-uppercase text-muted small mb-3">Identity</h2>
<div class="row g-3 mb-4">
    <div class="col-md-7">
        <label for="name" class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ $val('name') }}" required maxlength="80" placeholder="e.g. KFC Example">
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-5">
        <label for="slug" class="form-label fw-semibold">Slug</label>
        <input type="text" id="slug" name="slug" class="form-control font-monospace @error('slug') is-invalid @enderror" value="{{ $val('slug') }}" maxlength="90" placeholder="generated from the name">
        <div class="form-text">Readable admin identifier. Made unique automatically.</div>
        @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12">
        <label for="description" class="form-label fw-semibold">Description</label>
        <textarea id="description" name="description" rows="2" maxlength="2000" class="form-control @error('description') is-invalid @enderror">{{ $val('description') }}</textarea>
        <div class="form-text">Shown to members in the app.</div>
        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-7">
        <label for="logo" class="form-label fw-semibold">Logo</label>
        <input type="file" id="logo" name="logo" accept="image/jpeg,image/png,image/webp" class="form-control @error('logo') is-invalid @enderror">
        <div class="form-text">JPG, PNG or WebP, max {{ (int) round(config('ballspot.clubs.media.logo_max_kb', 2048) / 1024) }} MB. Re-encoded and stored under a random name.</div>
        @error('logo')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    @if($club?->logo_path)
    <div class="col-md-5 d-flex align-items-center gap-3">
        @include('admin.clubs._logo', ['club' => $club, 'size' => 'lg'])
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="remove_logo" {{ old('remove_logo') ? 'checked' : '' }}>
            <label class="form-check-label" for="remove_logo">Remove current logo</label>
        </div>
    </div>
    @endif
</div>

<h2 class="h6 text-uppercase text-muted small mb-3">Classification &amp; location</h2>
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <label for="club_type" class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
        <select id="club_type" name="club_type" class="form-select @error('club_type') is-invalid @enderror" required>
            @foreach($types as $type)
                <option value="{{ $type }}" {{ (string) $val('club_type', 'sports_club') === $type ? 'selected' : '' }}>{{ $typeLabels[$type] ?? $type }}</option>
            @endforeach
        </select>
        @error('club_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label for="sport_id" class="form-label fw-semibold">Default sport</label>
        <select id="sport_id" name="sport_id" class="form-select @error('sport_id') is-invalid @enderror">
            <option value="">— None —</option>
            @foreach($sports as $sport)
                <option value="{{ $sport->id }}" {{ (string) $val('sport_id') === (string) $sport->id ? 'selected' : '' }}>{{ $sport->emoji }} {{ $sport->name }}</option>
            @endforeach
        </select>
        @error('sport_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label for="external_ref" class="form-label fw-semibold">External reference</label>
        <input type="text" id="external_ref" name="external_ref" class="form-control @error('external_ref') is-invalid @enderror" value="{{ $val('external_ref') }}" maxlength="60" placeholder="federation / registration no.">
        @error('external_ref')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label for="city" class="form-label fw-semibold">City</label>
        <input type="text" id="city" name="city" class="form-control @error('city') is-invalid @enderror" value="{{ $val('city') }}" maxlength="80">
        @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label for="region" class="form-label fw-semibold">Region</label>
        <input type="text" id="region" name="region" class="form-control @error('region') is-invalid @enderror" value="{{ $val('region') }}" maxlength="80">
        @error('region')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-2">
        <label for="country_code" class="form-label fw-semibold">Country</label>
        <input type="text" id="country_code" name="country_code" class="form-control text-uppercase @error('country_code') is-invalid @enderror" value="{{ $val('country_code') }}" maxlength="2" placeholder="BE">
        @error('country_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label for="timezone" class="form-label fw-semibold">Timezone <span class="text-danger">*</span></label>
        <select id="timezone" name="timezone" class="form-select @error('timezone') is-invalid @enderror" required>
            @foreach($timezones as $tz)
                <option value="{{ $tz }}" {{ (string) $val('timezone', $defaultTz) === $tz ? 'selected' : '' }}>{{ $tz }}</option>
            @endforeach
        </select>
        <div class="form-text">Competition windows are shown in it.</div>
        @error('timezone')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<h2 class="h6 text-uppercase text-muted small mb-1">Contact <span class="badge badge-soft ms-1 text-normal">admin only</span></h2>
<p class="text-muted small mb-3">Personal data: never shown in the app, never in audit logs.</p>
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <label for="contact_name" class="form-label fw-semibold">Contact name</label>
        <input type="text" id="contact_name" name="contact_name" class="form-control @error('contact_name') is-invalid @enderror" value="{{ old('contact_name', $club?->contact_name) }}" maxlength="120" autocomplete="off">
        @error('contact_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label for="contact_email" class="form-label fw-semibold">Contact email</label>
        <input type="email" id="contact_email" name="contact_email" class="form-control @error('contact_email') is-invalid @enderror" value="{{ old('contact_email', $club?->contact_email) }}" maxlength="190" autocomplete="off">
        @error('contact_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
