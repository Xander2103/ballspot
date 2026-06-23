@extends('admin.layout')

@section('title', 'Edit Challenge')

@section('content')
<div class="mb-3">
    <a href="/admin/challenges" class="text-muted small">&larr; Back to Challenges</a>
</div>
<h1 class="h3 mb-4">Edit Challenge</h1>

<div class="card shadow-sm" style="max-width: 740px;">
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

            <div class="mb-3">
                <label for="challenge_category_id" class="form-label fw-semibold">Category</label>
                <select id="challenge_category_id" name="challenge_category_id" class="form-select @error('challenge_category_id') is-invalid @enderror">
                    <option value="">— Uncategorised —</option>
                    @foreach($categories as $cat)
                    <option value="{{ $cat->id }}" {{ old('challenge_category_id', $challenge->challenge_category_id) == $cat->id ? 'selected' : '' }}>
                        {{ $cat->name }}
                    </option>
                    @endforeach
                </select>
                <div class="form-text">Optional. Used for grouping and future pack filtering.</div>
                @error('challenge_category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Section: Hidden image --}}
            <hr class="my-4">
            <h6 class="fw-semibold mb-1">Hidden Image</h6>
            <p class="text-muted small mb-3">What players see while guessing. Click the image to reposition the ball marker.</p>

            @if($challenge->hidden_image_path)
            <div id="hidden-picker" class="image-picker-container mb-1">
                <img id="hidden-preview" src="{{ asset('storage/' . $challenge->hidden_image_path) }}" alt="Current hidden image">
                <div id="hidden-marker" class="ball-marker" style="
                    left:{{ old('ball_x_ratio', $challenge->ball_x_ratio) * 100 }}%;
                    top:{{ old('ball_y_ratio', $challenge->ball_y_ratio) * 100 }}%;"></div>
            </div>
            <p class="text-muted small mb-3">Current: <span id="coords-display">x={{ old('ball_x_ratio', $challenge->ball_x_ratio) }}, y={{ old('ball_y_ratio', $challenge->ball_y_ratio) }}</span></p>
            @endif

            <div class="mb-3">
                <label for="hidden_image" class="form-label fw-semibold">Replace Hidden Image <span class="text-muted fw-normal small">(optional)</span></label>
                <input type="file" id="hidden_image" name="hidden_image"
                       class="form-control @error('hidden_image') is-invalid @enderror"
                       accept="image/*">
                <div class="form-text">JPEG/PNG, max 5 MB. Leave blank to keep current.</div>
                @error('hidden_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div id="new-hidden-picker-wrap" style="display:none; margin-bottom:1rem;">
                <div id="new-hidden-picker" class="image-picker-container">
                    <img id="new-hidden-preview" src="" alt="New hidden preview">
                    <div id="new-hidden-marker" class="ball-marker" style="display:none;"></div>
                </div>
                <p class="text-muted small mt-1">Click to place the marker on the new image.</p>
            </div>

            {{-- Section: Reveal image --}}
            <hr class="my-4">
            <h6 class="fw-semibold mb-1">Reveal Image</h6>
            <p class="text-muted small mb-3">Shown after guessing. Click to set ball position precisely — the ball is visible here.</p>

            @if($challenge->original_image_path)
            <div id="reveal-picker" class="image-picker-container mb-1">
                <img id="reveal-preview" src="{{ asset('storage/' . $challenge->original_image_path) }}" alt="Current reveal image">
                <div id="reveal-marker" class="ball-marker" style="
                    left:{{ old('ball_x_ratio', $challenge->ball_x_ratio) * 100 }}%;
                    top:{{ old('ball_y_ratio', $challenge->ball_y_ratio) * 100 }}%;"></div>
            </div>
            <p class="text-muted small mb-3">Click above to reposition from the reveal image.</p>
            @endif

            <div class="mb-3">
                <label for="original_image" class="form-label fw-semibold">{{ $challenge->original_image_path ? 'Replace Reveal Image' : 'Add Reveal Image' }} <span class="text-muted fw-normal small">(optional)</span></label>
                <input type="file" id="original_image" name="original_image"
                       class="form-control @error('original_image') is-invalid @enderror"
                       accept="image/*">
                <div class="form-text">JPEG/PNG, max 5 MB. Leave blank to keep current.</div>
                @error('original_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div id="new-reveal-picker-wrap" style="display:none; margin-bottom:1rem;">
                <div id="new-reveal-picker" class="image-picker-container">
                    <img id="new-reveal-preview" src="" alt="New reveal preview">
                    <div id="new-reveal-marker" class="ball-marker" style="display:none;"></div>
                </div>
                <p class="text-muted small mt-1">Click to set position from the new reveal image.</p>
            </div>

            {{-- Ball position --}}
            <hr class="my-4">
            <h6 class="fw-semibold mb-1">Ball Position <span class="text-danger">*</span></h6>
            <p class="text-muted small mb-3">Set by clicking any image above, or enter manually. 0 = left/top, 1 = right/bottom.</p>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="ball_x_ratio" class="form-label fw-semibold">X (horizontal)</label>
                    <input type="number" id="ball_x_ratio" name="ball_x_ratio"
                           class="form-control @error('ball_x_ratio') is-invalid @enderror"
                           value="{{ old('ball_x_ratio', $challenge->ball_x_ratio) }}" min="0" max="1" step="0.001" required>
                    @error('ball_x_ratio')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6 mb-3">
                    <label for="ball_y_ratio" class="form-label fw-semibold">Y (vertical)</label>
                    <input type="number" id="ball_y_ratio" name="ball_y_ratio"
                           class="form-control @error('ball_y_ratio') is-invalid @enderror"
                           value="{{ old('ball_y_ratio', $challenge->ball_y_ratio) }}" min="0" max="1" step="0.001" required>
                    @error('ball_y_ratio')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="mb-4">
                <button type="submit" class="btn btn-primary px-4">Save Changes</button>
                <a href="/admin/challenges" class="btn btn-outline-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

<style>
.image-picker-container {
    position: relative;
    display: inline-block;
    cursor: crosshair;
    max-width: 100%;
    border-radius: 6px;
    overflow: hidden;
    border: 2px solid #dee2e6;
}
.image-picker-container img {
    max-width: 100%;
    max-height: 280px;
    display: block;
}
.ball-marker {
    position: absolute;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #dc3545;
    border: 2px solid #fff;
    box-shadow: 0 1px 4px rgba(0,0,0,.5);
    transform: translate(-50%, -50%);
    pointer-events: none;
}
</style>

<script>
(function () {
    var xInput = document.getElementById('ball_x_ratio');
    var yInput = document.getElementById('ball_y_ratio');
    var coordsDisplay = document.getElementById('coords-display');

    // The IDs of all picker → marker pairs. Some may not exist (e.g. no existing reveal image).
    var pickers = [
        { pickerId: 'hidden-picker',     imgId: 'hidden-preview',     markerId: 'hidden-marker',     wrapId: null },
        { pickerId: 'reveal-picker',     imgId: 'reveal-preview',     markerId: 'reveal-marker',     wrapId: null },
        { pickerId: 'new-hidden-picker', imgId: 'new-hidden-preview', markerId: 'new-hidden-marker', wrapId: 'new-hidden-picker-wrap' },
        { pickerId: 'new-reveal-picker', imgId: 'new-reveal-preview', markerId: 'new-reveal-marker', wrapId: 'new-reveal-picker-wrap' },
    ];

    function isVisible(p) {
        var picker = document.getElementById(p.pickerId);
        if (!picker) return false;
        if (p.wrapId) {
            var wrap = document.getElementById(p.wrapId);
            return wrap && wrap.style.display !== 'none';
        }
        return true; // existing images always visible
    }

    function applyPosition(x, y) {
        xInput.value = x.toFixed(4);
        yInput.value = y.toFixed(4);
        if (coordsDisplay) {
            coordsDisplay.textContent = 'x=' + x.toFixed(4) + ' (' + Math.round(x * 100) + '%), y=' + y.toFixed(4) + ' (' + Math.round(y * 100) + '%)';
        }
        pickers.forEach(function (p) {
            if (!isVisible(p)) return;
            var m = document.getElementById(p.markerId);
            if (!m) return;
            m.style.left    = (x * 100) + '%';
            m.style.top     = (y * 100) + '%';
            m.style.display = 'block';
        });
    }

    pickers.forEach(function (p) {
        var picker = document.getElementById(p.pickerId);
        var img    = document.getElementById(p.imgId);
        if (!picker || !img) return;
        picker.addEventListener('click', function (e) {
            var rect = img.getBoundingClientRect();
            applyPosition(
                Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width)),
                Math.min(1, Math.max(0, (e.clientY - rect.top)  / rect.height))
            );
        });
    });

    function setupFileInput(inputId, wrapId, previewId, markerId) {
        var input = document.getElementById(inputId);
        if (!input) return;
        input.addEventListener('change', function () {
            var file = this.files[0];
            var wrap = document.getElementById(wrapId);
            if (!file) { wrap.style.display = 'none'; return; }
            var reader = new FileReader();
            reader.onload = function (e) {
                document.getElementById(previewId).src = e.target.result;
                document.getElementById(markerId).style.display = 'none';
                wrap.style.display = 'block';
            };
            reader.readAsDataURL(file);
        });
    }

    setupFileInput('hidden_image',   'new-hidden-picker-wrap', 'new-hidden-preview', 'new-hidden-marker');
    setupFileInput('original_image', 'new-reveal-picker-wrap', 'new-reveal-preview', 'new-reveal-marker');
}());
</script>
@endsection
