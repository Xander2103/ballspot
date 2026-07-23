@csrf

<div class="row g-3">
    <div class="col-md-8">
        <label for="name" class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
               value="{{ old('name', $subcategory->name ?? '') }}" required maxlength="100" placeholder="e.g. FC Barcelona">
        <div class="form-text">Slug is generated from the name unless you set one below.</div>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label for="type" class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
        <select id="type" name="type" class="form-select @error('type') is-invalid @enderror" required>
            @foreach($types as $t)
                <option value="{{ $t }}" {{ old('type', $subcategory->type ?? '') === $t ? 'selected' : '' }}>{{ $t }}</option>
            @endforeach
        </select>
        @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-6">
        <label for="sport_id" class="form-label fw-semibold">Sport</label>
        <select id="sport_id" name="sport_id" class="form-select @error('sport_id') is-invalid @enderror">
            <option value="">Global (all sports)</option>
            @foreach($sports as $s)
                <option value="{{ $s->id }}" {{ (string) old('sport_id', $subcategory->sport_id ?? '') === (string) $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
            @endforeach
        </select>
        @error('sport_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-6">
        <label for="slug" class="form-label fw-semibold">Slug <span class="text-muted fw-normal">(optional)</span></label>
        <input type="text" id="slug" name="slug" class="form-control @error('slug') is-invalid @enderror"
               value="{{ old('slug', $subcategory->slug ?? '') }}" maxlength="120" placeholder="auto from name">
        @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <label for="description" class="form-label fw-semibold">Description</label>
        <textarea id="description" name="description" class="form-control @error('description') is-invalid @enderror"
                  rows="2" maxlength="500" placeholder="Optional">{{ old('description', $subcategory->description ?? '') }}</textarea>
        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label for="color" class="form-label fw-semibold">Color</label>
        <input type="text" id="color" name="color" class="form-control @error('color') is-invalid @enderror"
               value="{{ old('color', $subcategory->color ?? '') }}" maxlength="20" placeholder="#0d6efd">
        @error('color')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label for="icon" class="form-label fw-semibold">Icon <span class="text-muted fw-normal">(emoji)</span></label>
        <input type="text" id="icon" name="icon" class="form-control @error('icon') is-invalid @enderror"
               value="{{ old('icon', $subcategory->icon ?? '') }}" maxlength="40" placeholder="⚽">
        @error('icon')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label for="sort_order" class="form-label fw-semibold">Sort order</label>
        <input type="number" id="sort_order" name="sort_order" class="form-control @error('sort_order') is-invalid @enderror"
               value="{{ old('sort_order', $subcategory->sort_order ?? 0) }}" min="0">
        @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                   {{ old('is_active', ($subcategory->is_active ?? true)) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_active">Active</label>
        </div>
    </div>
</div>
