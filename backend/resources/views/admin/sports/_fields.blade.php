{{-- Shared sport fields. $sport may be null (create). --}}
@php($isFootball = ($sport->slug ?? null) === 'football')
<div class="row g-3">
    <div class="col-md-6">
        <label for="name" class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
               value="{{ old('name', $sport->name ?? '') }}" required maxlength="60" placeholder="e.g. Padel">
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-6">
        <label for="slug" class="form-label fw-semibold">Slug</label>
        <input type="text" id="slug" name="slug" class="form-control font-monospace @error('slug') is-invalid @enderror"
               value="{{ old('slug', $sport->slug ?? '') }}" maxlength="60" placeholder="auto from name"
               {{ $isFootball ? 'readonly' : '' }}>
        @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">Lowercase letters, numbers, - and _. Leave empty to generate from the name.</div>
    </div>

    <div class="col-md-3">
        <label for="emoji" class="form-label fw-semibold">Emoji</label>
        <input type="text" id="emoji" name="emoji" class="form-control @error('emoji') is-invalid @enderror"
               value="{{ old('emoji', $sport->emoji ?? '⚽') }}" maxlength="8">
        @error('emoji')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-3">
        <label for="object_name" class="form-label fw-semibold">Object label</label>
        <input type="text" id="object_name" name="object_name" class="form-control @error('object_name') is-invalid @enderror"
               value="{{ old('object_name', $sport->object_name ?? 'ball') }}" maxlength="30" placeholder="ball / puck / shuttle">
        @error('object_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">What players are asked to find.</div>
    </div>

    <div class="col-md-3">
        <label for="primary_color" class="form-label fw-semibold">Colour</label>
        <input type="color" id="primary_color" name="primary_color" class="form-control form-control-color @error('primary_color') is-invalid @enderror"
               value="{{ old('primary_color', $sport->primary_color ?? '#00c853') }}">
        @error('primary_color')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-3">
        <label for="sort_order" class="form-label fw-semibold">Sort order</label>
        <input type="number" id="sort_order" name="sort_order" class="form-control @error('sort_order') is-invalid @enderror"
               value="{{ old('sort_order', $sport->sort_order ?? 0) }}" min="0" max="1000">
        @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-6">
        <label for="status" class="form-label fw-semibold">Status</label>
        @if($isFootball)
            <input type="hidden" name="status" value="active">
            <input type="text" class="form-control" value="🔒 Always active" disabled>
        @else
            <select id="status" name="status" class="form-select @error('status') is-invalid @enderror">
                @foreach(\App\Models\Sport::STATUSES as $st)
                    <option value="{{ $st }}" @selected(old('status', $sport->status ?? \App\Models\Sport::STATUS_COMING_SOON) === $st)>
                        {{ ['active' => 'Active', 'coming_soon' => 'Coming soon', 'hidden' => 'Hidden'][$st] ?? $st }}
                    </option>
                @endforeach
            </select>
            @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="form-text">
                New sports start as <strong>Coming soon</strong>: visible in the app but not playable.
                <strong>Hidden</strong> sports are not shown to players.
            </div>
        @endif
    </div>
</div>
