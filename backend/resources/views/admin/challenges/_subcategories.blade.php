{{-- Multi-select of active subcategories, grouped by "Sport · Type".
     $subcategories: Collection keyed by group label. $selected: collection of ids. --}}
<div class="mb-3">
    <label for="subcategories" class="form-label fw-semibold">Subcategories <span class="text-muted fw-normal">(optional)</span></label>
    @if($subcategories->isEmpty())
        <div class="form-text">No subcategories yet. <a href="/admin/subcategories/create">Create one</a> to group content.</div>
    @else
        <select id="subcategories" name="subcategories[]" multiple size="6"
                class="form-select @error('subcategories') is-invalid @enderror">
            @foreach($subcategories as $group => $items)
                <optgroup label="{{ $group }}">
                    @foreach($items as $sub)
                        <option value="{{ $sub->id }}" {{ $selected->contains($sub->id) ? 'selected' : '' }}>
                            {{ $sub->icon ? $sub->icon.' ' : '' }}{{ $sub->name }}
                        </option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        <div class="form-text">Hold Ctrl/Cmd to select multiple. Curated groups only — not required.</div>
        @error('subcategories')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    @endif
</div>
