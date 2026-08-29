@extends('admin.layout')

@section('title', 'New Challenge')

@section('content')
@include('admin.partials.backup-reminder')
<div class="mb-3">
    <a href="/admin/challenges" class="text-muted small">&larr; Back to Challenges</a>
</div>
<h1 class="h3 mb-4">New Challenge</h1>

<div class="card shadow-sm" style="max-width: 740px;">
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

            @include('admin.challenges._usage_pool', ['current' => old('usage_pool', \App\Models\Challenge::POOL_GENERAL)])

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="sport_id" class="form-label fw-semibold">Sport</label>
                    <select id="sport_id" name="sport_id" class="form-select @error('sport_id') is-invalid @enderror">
                        @foreach($sports as $sport)
                        <option value="{{ $sport->id }}"
                            {{ (string) old('sport_id', $sports->firstWhere('slug','football')?->id) === (string) $sport->id ? 'selected' : '' }}>
                            {{ $sport->emoji }} {{ $sport->name }}{{ $sport->is_active ? '' : ' (inactive)' }}
                        </option>
                        @endforeach
                    </select>
                    <div class="form-text">Defaults to Football, the current live sport.</div>
                    @error('sport_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6 mb-3">
                    <label for="challenge_category_id" class="form-label fw-semibold">Category</label>
                    <select id="challenge_category_id" name="challenge_category_id" class="form-select @error('challenge_category_id') is-invalid @enderror">
                        <option value="">— Uncategorised —</option>
                        @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ old('challenge_category_id') == $cat->id ? 'selected' : '' }}>
                            {{ $cat->name }}
                        </option>
                        @endforeach
                    </select>
                    <div class="form-text">Optional. Used for grouping and future pack filtering.</div>
                    @error('challenge_category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="mb-3">
                <label for="tags" class="form-label fw-semibold">Tags <span class="text-muted fw-normal">(optional)</span></label>
                <input type="text" id="tags" name="tags" class="form-control @error('tags') is-invalid @enderror"
                       value="{{ old('tags') }}" maxlength="500" placeholder="e.g. Premier League, England, corner kick">
                <div class="form-text">Comma-separated text tags (team, country, league, moment type…). Text only — never use copyrighted logos.</div>
                @error('tags')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            @include('admin.challenges._subcategories', ['selected' => collect(old('subcategories', []))])

            {{-- Section: Hidden image --}}
            <hr class="my-4">
            <h6 class="fw-semibold mb-1">Hidden Image <span class="text-danger">*</span></h6>
            <p class="text-muted small mb-3">What players see while guessing — crop or blur so the ball is not obvious. Click the preview to mark the ball position.</p>

            <div class="mb-3">
                <input type="file" id="hidden_image" name="hidden_image"
                       class="form-control @error('hidden_image') is-invalid @enderror"
                       accept="image/*" required>
                <div class="form-text">JPEG/PNG, 4:3 ratio recommended, max 5 MB.</div>
                @error('hidden_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div id="hidden-picker-wrap" style="display:none; margin-bottom:1rem;">
                <div id="hidden-picker" class="image-picker-container">
                    <img id="hidden-preview" src="" alt="Hidden preview">
                    <div id="hidden-marker" class="ball-marker" style="display:none;"></div>
                </div>
                <p class="text-muted small mt-1">Click above to place the ⚽ marker.</p>
            </div>

            {{-- Section: Reveal image --}}
            <hr class="my-4">
            <h6 class="fw-semibold mb-1">Reveal Image <span class="text-muted fw-normal">(optional)</span></h6>
            <p class="text-muted small mb-3">The original, unaltered image shown to players after guessing. Tip: click it to set the ball position precisely — the ball is visible here.</p>

            <div class="mb-3">
                <input type="file" id="original_image" name="original_image"
                       class="form-control @error('original_image') is-invalid @enderror"
                       accept="image/*">
                <div class="form-text">JPEG/PNG, max 5 MB. Leave blank if no reveal image.</div>
                @error('original_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div id="reveal-picker-wrap" style="display:none; margin-bottom:1rem;">
                <div id="reveal-picker" class="image-picker-container">
                    <img id="reveal-preview" src="" alt="Reveal preview">
                    <div id="reveal-marker" class="ball-marker" style="display:none;"></div>
                </div>
                <p class="text-muted small mt-1">Click above to set the ball position from the reveal image.</p>
            </div>

            {{-- Ball position --}}
            <hr class="my-4">
            <h6 class="fw-semibold mb-1">Ball Position <span class="text-danger">*</span></h6>
            <p class="text-muted small mb-3">Set by clicking either image above, or enter manually. 0 = left/top edge, 1 = right/bottom edge.</p>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="ball_x_ratio" class="form-label fw-semibold">X (horizontal)</label>
                    <input type="number" id="ball_x_ratio" name="ball_x_ratio"
                           class="form-control @error('ball_x_ratio') is-invalid @enderror"
                           value="{{ old('ball_x_ratio') }}" min="0" max="1" step="0.001" required>
                    @error('ball_x_ratio')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6 mb-3">
                    <label for="ball_y_ratio" class="form-label fw-semibold">Y (vertical)</label>
                    <input type="number" id="ball_y_ratio" name="ball_y_ratio"
                           class="form-control @error('ball_y_ratio') is-invalid @enderror"
                           value="{{ old('ball_y_ratio') }}" min="0" max="1" step="0.001" required>
                    @error('ball_y_ratio')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <p id="coords-display" class="text-muted small">Ball position: not set</p>

            <button type="submit" class="btn btn-primary px-4">Create Challenge</button>
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

    // Coordinates are stored as 0..1 ratios. Three decimals is ~1px on a
    // 1000px image — precise enough for scoring, short enough to hand-edit.
    // Number -> String is locale independent, so this always emits dots.
    function round3(v) {
        return Math.round(v * 1000) / 1000;
    }

    // Update position inputs + display, then refresh all visible markers
    function applyPosition(x, y) {
        x = round3(x);
        y = round3(y);
        xInput.value = x;
        yInput.value = y;
        coordsDisplay.textContent = 'Ball position: x=' + x +
            ' (' + Math.round(x * 100) + '%), y=' + y +
            ' (' + Math.round(y * 100) + '%)';
        [['hidden-marker', 'hidden-picker-wrap'], ['reveal-marker', 'reveal-picker-wrap']].forEach(function (pair) {
            var m = document.getElementById(pair[0]);
            var w = document.getElementById(pair[1]);
            if (m && w && w.style.display !== 'none') {
                m.style.left    = (x * 100) + '%';
                m.style.top     = (y * 100) + '%';
                m.style.display = 'block';
            }
        });
    }

    function attachPicker(pickerId, imgId) {
        var picker = document.getElementById(pickerId);
        var img    = document.getElementById(imgId);
        if (!picker) return;
        picker.addEventListener('click', function (e) {
            var rect = img.getBoundingClientRect();
            applyPosition(
                Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width)),
                Math.min(1, Math.max(0, (e.clientY - rect.top)  / rect.height))
            );
        });
    }

    function setupFileInput(inputId, wrapId, previewId, markerId) {
        document.getElementById(inputId).addEventListener('change', function () {
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

    setupFileInput('hidden_image',   'hidden-picker-wrap', 'hidden-preview', 'hidden-marker');
    setupFileInput('original_image', 'reveal-picker-wrap', 'reveal-preview', 'reveal-marker');

    attachPicker('hidden-picker', 'hidden-preview');
    attachPicker('reveal-picker', 'reveal-preview');
}());
</script>
@endsection
