@extends('admin.layout')

@section('title', 'New Challenge')

@section('content')
<div class="mb-3">
    <a href="/admin/challenges" class="text-muted small">&larr; Back to Challenges</a>
</div>
<h1 class="h3 mb-4">New Challenge</h1>

<div class="card shadow-sm" style="max-width: 700px;">
    <div class="card-body">
        <form action="/admin/challenges" method="POST" enctype="multipart/form-data" id="challenge-form">
            @csrf

            <div class="mb-3">
                <label for="title" class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                <input type="text" id="title" name="title" class="form-control @error('title') is-invalid @enderror"
                       value="{{ old('title') }}" required maxlength="255">
                @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="difficulty" class="form-label fw-semibold">Difficulty <span class="text-danger">*</span></label>
                    <select id="difficulty" name="difficulty" class="form-select @error('difficulty') is-invalid @enderror" required>
                        <option value="">— Select —</option>
                        @foreach(['easy','medium','hard'] as $d)
                        <option value="{{ $d }}" {{ old('difficulty') === $d ? 'selected' : '' }}>{{ ucfirst($d) }}</option>
                        @endforeach
                    </select>
                    @error('difficulty')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6 mb-3">
                    <label for="status" class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                    <select id="status" name="status" class="form-select @error('status') is-invalid @enderror" required>
                        <option value="">— Select —</option>
                        @foreach(['draft','active','archived'] as $s)
                        <option value="{{ $s }}" {{ old('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                    @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            {{-- Hidden image + click-to-set --}}
            <div class="mb-3">
                <label for="hidden_image" class="form-label fw-semibold">Hidden Image <span class="text-danger">*</span></label>
                <input type="file" id="hidden_image" name="hidden_image"
                       class="form-control @error('hidden_image') is-invalid @enderror"
                       accept="image/*" required>
                <div class="form-text">Max 5 MB. After selecting, click the image to mark the ball position.</div>
                @error('hidden_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div id="image-picker-wrap" style="display:none; margin-bottom:1rem;">
                <p class="text-muted small mb-1">Click on the image to set the ball position:</p>
                <div id="image-picker" style="position:relative; display:inline-block; cursor:crosshair; max-width:100%;">
                    <img id="image-preview" src="" alt="Preview" style="max-width:100%; display:block; border-radius:6px;">
                    <div id="ball-marker" style="display:none; position:absolute; width:20px; height:20px; border-radius:50%; background:red; border:2px solid #fff; transform:translate(-50%,-50%); pointer-events:none;"></div>
                </div>
                <p class="text-muted small mt-1">Ball position: <span id="coords-display">not set</span></p>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="ball_x_ratio" class="form-label fw-semibold">Ball X Ratio <span class="text-danger">*</span></label>
                    <input type="number" id="ball_x_ratio" name="ball_x_ratio"
                           class="form-control @error('ball_x_ratio') is-invalid @enderror"
                           value="{{ old('ball_x_ratio') }}" min="0" max="1" step="0.001" required>
                    <div class="form-text">0 = left, 1 = right. Set by clicking the image above.</div>
                    @error('ball_x_ratio')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6 mb-3">
                    <label for="ball_y_ratio" class="form-label fw-semibold">Ball Y Ratio <span class="text-danger">*</span></label>
                    <input type="number" id="ball_y_ratio" name="ball_y_ratio"
                           class="form-control @error('ball_y_ratio') is-invalid @enderror"
                           value="{{ old('ball_y_ratio') }}" min="0" max="1" step="0.001" required>
                    <div class="form-text">0 = top, 1 = bottom. Set by clicking the image above.</div>
                    @error('ball_y_ratio')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="mb-4">
                <label for="original_image" class="form-label fw-semibold">Original Image <span class="text-muted small">(optional)</span></label>
                <input type="file" id="original_image" name="original_image"
                       class="form-control @error('original_image') is-invalid @enderror"
                       accept="image/*">
                <div class="form-text">Max 5 MB. The unaltered reference image.</div>
                @error('original_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <button type="submit" class="btn btn-primary px-4">Create Challenge</button>
        </form>
    </div>
</div>

<script>
(function () {
    var fileInput = document.getElementById('hidden_image');
    var wrap = document.getElementById('image-picker-wrap');
    var preview = document.getElementById('image-preview');
    var picker = document.getElementById('image-picker');
    var marker = document.getElementById('ball-marker');
    var coordsDisplay = document.getElementById('coords-display');
    var xInput = document.getElementById('ball_x_ratio');
    var yInput = document.getElementById('ball_y_ratio');

    fileInput.addEventListener('change', function () {
        var file = fileInput.files[0];
        if (!file) { wrap.style.display = 'none'; return; }
        var reader = new FileReader();
        reader.onload = function (e) {
            preview.src = e.target.result;
            wrap.style.display = 'block';
            marker.style.display = 'none';
            coordsDisplay.textContent = 'not set';
        };
        reader.readAsDataURL(file);
    });

    picker.addEventListener('click', function (e) {
        var rect = preview.getBoundingClientRect();
        var x = (e.clientX - rect.left) / rect.width;
        var y = (e.clientY - rect.top) / rect.height;
        x = Math.min(1, Math.max(0, x));
        y = Math.min(1, Math.max(0, y));
        xInput.value = x.toFixed(4);
        yInput.value = y.toFixed(4);
        marker.style.left = (x * 100) + '%';
        marker.style.top = (y * 100) + '%';
        marker.style.display = 'block';
        coordsDisplay.textContent = 'x=' + x.toFixed(4) + ', y=' + y.toFixed(4);
    });
}());
</script>
@endsection
