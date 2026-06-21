@extends('admin.layout')

@section('title', 'Edit Challenge')

@section('content')
<div class="mb-3">
    <a href="/admin/challenges" class="text-muted small">&larr; Back to Challenges</a>
</div>
<h1 class="h3 mb-4">Edit Challenge</h1>

<div class="card shadow-sm" style="max-width: 700px;">
    <div class="card-body">
        <form action="/admin/challenges/{{ $challenge->id }}" method="POST" enctype="multipart/form-data">
            @csrf
            @method('PATCH')

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

            {{-- Current image with click-to-set --}}
            @if($challenge->hidden_image_path)
            <div class="mb-3">
                <label class="form-label fw-semibold">Current Hidden Image</label>
                <div>
                    <div id="image-picker" style="position:relative; display:inline-block; cursor:crosshair; max-width:100%;">
                        <img id="image-preview"
                             src="{{ asset('storage/' . $challenge->hidden_image_path) }}"
                             alt="Current hidden image"
                             style="max-width:100%; max-height:300px; border-radius:6px; display:block;">
                        <div id="ball-marker" style="position:absolute; width:20px; height:20px; border-radius:50%; background:red; border:2px solid #fff; transform:translate(-50%,-50%); pointer-events:none;
                            left:{{ old('ball_x_ratio', $challenge->ball_x_ratio) * 100 }}%;
                            top:{{ old('ball_y_ratio', $challenge->ball_y_ratio) * 100 }}%;"></div>
                    </div>
                    <p class="text-muted small mt-1">Click image to reposition ball. Current: <span id="coords-display">x={{ old('ball_x_ratio', $challenge->ball_x_ratio) }}, y={{ old('ball_y_ratio', $challenge->ball_y_ratio) }}</span></p>
                </div>
            </div>
            @endif

            <div class="mb-3">
                <label for="hidden_image" class="form-label fw-semibold">Replace Hidden Image <span class="text-muted small">(optional)</span></label>
                <input type="file" id="hidden_image" name="hidden_image"
                       class="form-control @error('hidden_image') is-invalid @enderror"
                       accept="image/*">
                <div class="form-text">Max 5 MB. Leave empty to keep current image.</div>
                @error('hidden_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div id="new-image-picker-wrap" style="display:none; margin-bottom:1rem;">
                <p class="text-muted small mb-1">Click the new image to set ball position:</p>
                <div id="new-image-picker" style="position:relative; display:inline-block; cursor:crosshair; max-width:100%;">
                    <img id="new-image-preview" src="" alt="New preview" style="max-width:100%; display:block; border-radius:6px;">
                    <div id="new-ball-marker" style="display:none; position:absolute; width:20px; height:20px; border-radius:50%; background:red; border:2px solid #fff; transform:translate(-50%,-50%); pointer-events:none;"></div>
                </div>
                <p class="text-muted small mt-1">Ball position: <span id="new-coords-display">not set</span></p>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="ball_x_ratio" class="form-label fw-semibold">Ball X Ratio <span class="text-danger">*</span></label>
                    <input type="number" id="ball_x_ratio" name="ball_x_ratio"
                           class="form-control @error('ball_x_ratio') is-invalid @enderror"
                           value="{{ old('ball_x_ratio', $challenge->ball_x_ratio) }}" min="0" max="1" step="0.001" required>
                    @error('ball_x_ratio')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6 mb-3">
                    <label for="ball_y_ratio" class="form-label fw-semibold">Ball Y Ratio <span class="text-danger">*</span></label>
                    <input type="number" id="ball_y_ratio" name="ball_y_ratio"
                           class="form-control @error('ball_y_ratio') is-invalid @enderror"
                           value="{{ old('ball_y_ratio', $challenge->ball_y_ratio) }}" min="0" max="1" step="0.001" required>
                    @error('ball_y_ratio')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="mb-4">
                <label for="original_image" class="form-label fw-semibold">Replace Original Image <span class="text-muted small">(optional)</span></label>
                <input type="file" id="original_image" name="original_image"
                       class="form-control @error('original_image') is-invalid @enderror"
                       accept="image/*">
                <div class="form-text">Max 5 MB. Leave empty to keep current.</div>
                @error('original_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <button type="submit" class="btn btn-primary px-4">Save Changes</button>
            <a href="/admin/challenges" class="btn btn-outline-secondary ms-2">Cancel</a>
        </form>
    </div>
</div>

<script>
(function () {
    // Existing image click-to-set
    var picker = document.getElementById('image-picker');
    var marker = document.getElementById('ball-marker');
    var coordsDisplay = document.getElementById('coords-display');
    var xInput = document.getElementById('ball_x_ratio');
    var yInput = document.getElementById('ball_y_ratio');

    if (picker) {
        picker.addEventListener('click', function (e) {
            var img = document.getElementById('image-preview');
            var rect = img.getBoundingClientRect();
            var x = (e.clientX - rect.left) / rect.width;
            var y = (e.clientY - rect.top) / rect.height;
            x = Math.min(1, Math.max(0, x));
            y = Math.min(1, Math.max(0, y));
            xInput.value = x.toFixed(4);
            yInput.value = y.toFixed(4);
            marker.style.left = (x * 100) + '%';
            marker.style.top = (y * 100) + '%';
            coordsDisplay.textContent = 'x=' + x.toFixed(4) + ', y=' + y.toFixed(4);
        });
    }

    // New image preview click-to-set
    var fileInput = document.getElementById('hidden_image');
    var newWrap = document.getElementById('new-image-picker-wrap');
    var newPreview = document.getElementById('new-image-preview');
    var newPicker = document.getElementById('new-image-picker');
    var newMarker = document.getElementById('new-ball-marker');
    var newCoordsDisplay = document.getElementById('new-coords-display');

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var file = fileInput.files[0];
            if (!file) { newWrap.style.display = 'none'; return; }
            var reader = new FileReader();
            reader.onload = function (e) {
                newPreview.src = e.target.result;
                newWrap.style.display = 'block';
                newMarker.style.display = 'none';
                newCoordsDisplay.textContent = 'not set';
            };
            reader.readAsDataURL(file);
        });

        newPicker.addEventListener('click', function (e) {
            var rect = newPreview.getBoundingClientRect();
            var x = (e.clientX - rect.left) / rect.width;
            var y = (e.clientY - rect.top) / rect.height;
            x = Math.min(1, Math.max(0, x));
            y = Math.min(1, Math.max(0, y));
            xInput.value = x.toFixed(4);
            yInput.value = y.toFixed(4);
            newMarker.style.left = (x * 100) + '%';
            newMarker.style.top = (y * 100) + '%';
            newMarker.style.display = 'block';
            newCoordsDisplay.textContent = 'x=' + x.toFixed(4) + ', y=' + y.toFixed(4);
        });
    }
}());
</script>
@endsection
