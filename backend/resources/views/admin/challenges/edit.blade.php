@extends('admin.layout')

@section('title', 'Edit Challenge')

@section('content')
<div class="mb-3">
    <a href="/admin/challenges" class="text-muted small">&larr; Back to Challenges</a>
</div>
<h1 class="h3 mb-4">Edit Challenge <span class="text-muted small">#{{ $challenge->id }}</span></h1>

<div class="card shadow-sm" style="max-width: 640px;">
    <div class="card-body">
        <form action="/admin/challenges/{{ $challenge->id }}" method="POST" enctype="multipart/form-data">
            @csrf
            @method('PUT')

            <div class="mb-3">
                <label for="title" class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                <input type="text" id="title" name="title" class="form-control @error('title') is-invalid @enderror"
                       value="{{ old('title', $challenge->title) }}" required maxlength="255">
                @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="difficulty" class="form-label fw-semibold">Difficulty <span class="text-danger">*</span></label>
                    <select id="difficulty" name="difficulty" class="form-select @error('difficulty') is-invalid @enderror" required>
                        @foreach(['easy','medium','hard'] as $d)
                        <option value="{{ $d }}" {{ old('difficulty', $challenge->difficulty) === $d ? 'selected' : '' }}>{{ ucfirst($d) }}</option>
                        @endforeach
                    </select>
                    @error('difficulty')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6 mb-3">
                    <label for="status" class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                    <select id="status" name="status" class="form-select @error('status') is-invalid @enderror" required>
                        @foreach(['draft','active','archived'] as $s)
                        <option value="{{ $s }}" {{ old('status', $challenge->status) === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                    @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="ball_x_ratio" class="form-label fw-semibold">Ball X Ratio <span class="text-danger">*</span></label>
                    <input type="number" id="ball_x_ratio" name="ball_x_ratio"
                           class="form-control @error('ball_x_ratio') is-invalid @enderror"
                           value="{{ old('ball_x_ratio', $challenge->ball_x_ratio) }}" min="0" max="1" step="0.001" required>
                    <div class="form-text">0 = left edge, 1 = right edge</div>
                    @error('ball_x_ratio')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6 mb-3">
                    <label for="ball_y_ratio" class="form-label fw-semibold">Ball Y Ratio <span class="text-danger">*</span></label>
                    <input type="number" id="ball_y_ratio" name="ball_y_ratio"
                           class="form-control @error('ball_y_ratio') is-invalid @enderror"
                           value="{{ old('ball_y_ratio', $challenge->ball_y_ratio) }}" min="0" max="1" step="0.001" required>
                    <div class="form-text">0 = top edge, 1 = bottom edge</div>
                    @error('ball_y_ratio')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            {{-- Hidden Image --}}
            <div class="mb-3">
                <label for="hidden_image" class="form-label fw-semibold">Hidden Image <span class="text-muted small">(leave blank to keep current)</span></label>
                @if($challenge->hidden_image_path)
                <div class="mb-2">
                    <img src="{{ asset('storage/' . $challenge->hidden_image_path) }}"
                         alt="Current hidden image" class="img-thumbnail" style="max-height:120px;">
                    <div class="form-text text-muted">Current: {{ basename($challenge->hidden_image_path) }}</div>
                </div>
                @endif
                <input type="file" id="hidden_image" name="hidden_image"
                       class="form-control @error('hidden_image') is-invalid @enderror"
                       accept="image/*">
                <div class="form-text">Max 5 MB. Upload to replace.</div>
                @error('hidden_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Original Image --}}
            <div class="mb-4">
                <label for="original_image" class="form-label fw-semibold">Original Image <span class="text-muted small">(optional, leave blank to keep current)</span></label>
                @if($challenge->original_image_path)
                <div class="mb-2">
                    <img src="{{ asset('storage/' . $challenge->original_image_path) }}"
                         alt="Current original image" class="img-thumbnail" style="max-height:120px;">
                    <div class="form-text text-muted">Current: {{ basename($challenge->original_image_path) }}</div>
                </div>
                @endif
                <input type="file" id="original_image" name="original_image"
                       class="form-control @error('original_image') is-invalid @enderror"
                       accept="image/*">
                <div class="form-text">Max 5 MB. Upload to replace.</div>
                @error('original_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary px-4">Update Challenge</button>
                <a href="/admin/challenges" class="btn btn-outline-secondary px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
