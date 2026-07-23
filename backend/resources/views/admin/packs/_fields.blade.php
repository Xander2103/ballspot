{{-- Shared pack detail fields. $pack may be null (create). --}}
<div class="row g-3">
    <div class="col-md-8">
        <label for="name" class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
               value="{{ old('name', $pack->name ?? '') }}" required maxlength="120" placeholder="e.g. Belgium Pack">
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label for="sport_id" class="form-label fw-semibold">Sport</label>
        <select id="sport_id" name="sport_id" class="form-select">
            <option value="">Global (all sports)</option>
            @foreach($sports as $s)
                <option value="{{ $s->id }}" {{ (string) old('sport_id', $pack->sport_id ?? '') === (string) $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="col-12">
        <label for="description" class="form-label fw-semibold">Description</label>
        <textarea id="description" name="description" class="form-control @error('description') is-invalid @enderror"
                  rows="2" maxlength="1000">{{ old('description', $pack->description ?? '') }}</textarea>
        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label for="status" class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
        <select id="status" name="status" class="form-select">
            @foreach(['draft','active','archived'] as $st)
                <option value="{{ $st }}" {{ old('status', $pack->status ?? 'draft') === $st ? 'selected' : '' }}>{{ ucfirst($st) }}</option>
            @endforeach
        </select>
    </div>

    <div class="col-md-4">
        <label for="visibility" class="form-label fw-semibold">Visibility <span class="text-danger">*</span></label>
        <select id="visibility" name="visibility" class="form-select">
            @foreach(['public','hidden'] as $v)
                <option value="{{ $v }}" {{ old('visibility', $pack->visibility ?? 'public') === $v ? 'selected' : '' }}>{{ ucfirst($v) }}</option>
            @endforeach
        </select>
    </div>

    <div class="col-md-4">
        <label for="difficulty" class="form-label fw-semibold">Difficulty</label>
        <select id="difficulty" name="difficulty" class="form-select">
            <option value="">—</option>
            @foreach(['easy','medium','hard','mixed'] as $d)
                <option value="{{ $d }}" {{ old('difficulty', $pack->difficulty ?? '') === $d ? 'selected' : '' }}>{{ ucfirst($d) }}</option>
            @endforeach
        </select>
    </div>

    <div class="col-md-4">
        <label for="sort_order" class="form-label fw-semibold">Sort order</label>
        <input type="number" id="sort_order" name="sort_order" class="form-control"
               value="{{ old('sort_order', $pack->sort_order ?? 0) }}" min="0">
    </div>

    <div class="col-md-4">
        <label for="cover_image" class="form-label fw-semibold">Cover image</label>
        <input type="file" id="cover_image" name="cover_image" class="form-control @error('cover_image') is-invalid @enderror" accept="image/*">
        @error('cover_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
        @if(($pack->cover_image_path ?? null))
            <div class="form-text">Current: <a href="{{ $pack->coverImageUrl() }}" target="_blank">view</a></div>
        @endif
    </div>

    <div class="col-md-4 d-flex align-items-end">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured" value="1"
                   {{ old('is_featured', $pack->is_featured ?? false) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_featured">Featured</label>
        </div>
    </div>
</div>
