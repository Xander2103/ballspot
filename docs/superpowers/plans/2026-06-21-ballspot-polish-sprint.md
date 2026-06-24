# BallSpot Polish Sprint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Polish BallSpot v1 for real friend testing by adding admin auth, demo images, UX improvements, and updated docs.

**Architecture:** Laravel 12 REST API backend + Expo 56 React Native mobile app; SQLite dev DB; Sanctum bearer token auth. All changes are additive or cosmetic — no breaking changes to existing API contracts or DB schema (except adding `is_admin` to users).

**Tech Stack:** PHP 8.2 / Laravel 12 / SQLite / Blade/Bootstrap 5 / Expo 56 / React Native 0.85 / TypeScript

## Global Constraints

- No real money, no gambling, no subscriptions, no ads, no chat, no AI, no realtime/websockets
- Football only (no other sports in this sprint)
- Score calculation stays backend-only — never trust client calculations
- Positions stored as decimal ratios 0..1 (never pixels)
- Keep app simple, testable, and extendable
- Backend: PHP 8.2, Laravel 12, SQLite in dev
- Mobile: Expo 56, React Native 0.85.3, TypeScript strict (0 errors)
- Admin routes: `Route::prefix('admin')->middleware('admin')` after this sprint
- Admin login: email `admin@ballspot.local`, password `password` (seeded)
- Ratio inputs: always `between:0,1` validation on backend
- `hidden_image_url` always generated as `asset('storage/' . $challenge->hidden_image_path)`
- Mobile API base: `process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://127.0.0.1:8000/api'`
- Bearer token stored in expo-secure-store under key `ballspot_token`

---

### Task 1: Admin Authentication

**Files:**
- Create: `backend/database/migrations/2026_06_21_000001_add_is_admin_to_users.php`
- Create: `backend/app/Http/Middleware/EnsureIsAdmin.php`
- Create: `backend/app/Http/Controllers/Admin/AuthController.php`
- Create: `backend/resources/views/admin/login.blade.php`
- Modify: `backend/app/Models/User.php` — add `is_admin` to fillable + casts
- Modify: `backend/bootstrap/app.php` — register EnsureIsAdmin middleware alias
- Modify: `backend/routes/web.php` — protect admin prefix with `admin` middleware, add login routes
- Modify: `backend/database/seeders/DatabaseSeeder.php` — seed `admin@ballspot.local / password / is_admin=true`
- Modify: `backend/resources/views/admin/layout.blade.php` — add logout link in navbar

**Interfaces:**
- Produces: `EnsureIsAdmin` middleware that checks `auth()->check() && auth()->user()->is_admin`; redirects unauthenticated to `/admin/login`; returns 403 for non-admin authenticated users
- Produces: `Auth::attempt()` session-based login (Blade area uses Laravel session, not Sanctum)
- Produces: `admin@ballspot.local` user with `is_admin = true` in seeder

- [ ] **Step 1: Create the migration**

Create `backend/database/migrations/2026_06_21_000001_add_is_admin_to_users.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
```

- [ ] **Step 2: Update User model**

In `backend/app/Models/User.php`, add `'is_admin'` to `$fillable` and add it to `casts()`:

```php
protected $fillable = ['name', 'username', 'email', 'password', 'is_admin'];

protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_admin' => 'boolean',
    ];
}
```

- [ ] **Step 3: Create EnsureIsAdmin middleware**

Create `backend/app/Http/Middleware/EnsureIsAdmin.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return redirect()->route('admin.login');
        }

        if (!auth()->user()->is_admin) {
            abort(403, 'Access denied.');
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register middleware in bootstrap/app.php**

Open `backend/bootstrap/app.php`. Add the middleware alias inside `withMiddleware`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'admin' => \App\Http\Middleware\EnsureIsAdmin::class,
    ]);
})
```

(If `withMiddleware` already exists, add the alias inside it.)

- [ ] **Step 5: Create admin AuthController**

Create `backend/app/Http/Controllers/Admin/AuthController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (auth()->check() && auth()->user()->is_admin) {
            return redirect('/admin/challenges');
        }
        return view('admin.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, remember: true)) {
            if (!auth()->user()->is_admin) {
                Auth::logout();
                return back()->withErrors(['email' => 'Access denied.']);
            }
            $request->session()->regenerate();
            return redirect('/admin/challenges');
        }

        return back()->withErrors(['email' => 'Invalid credentials.'])->withInput();
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('admin.login');
    }
}
```

- [ ] **Step 6: Create login Blade view**

Create `backend/resources/views/admin/login.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BallSpot Admin – Login</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>body { background: #f8f9fa; }</style>
</head>
<body>
<div class="d-flex justify-content-center align-items-center" style="min-height:100vh;">
    <div class="card shadow-sm" style="width:360px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-1 fw-bold">BallSpot Admin</h1>
            <p class="text-muted small mb-4">Sign in to manage challenges</p>

            @if($errors->any())
                <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('admin.login.submit') }}">
                @csrf
                <div class="mb-3">
                    <label for="email" class="form-label fw-semibold">Email</label>
                    <input type="email" id="email" name="email" class="form-control"
                           value="{{ old('email') }}" autocomplete="email" required>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label fw-semibold">Password</label>
                    <input type="password" id="password" name="password" class="form-control"
                           autocomplete="current-password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Sign In</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
```

- [ ] **Step 7: Update web.php routes**

Replace `backend/routes/web.php` with:

```php
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Admin auth (unguarded)
Route::get('/admin/login', [\App\Http\Controllers\Admin\AuthController::class, 'showLogin'])->name('admin.login');
Route::post('/admin/login', [\App\Http\Controllers\Admin\AuthController::class, 'login'])->name('admin.login.submit');
Route::post('/admin/logout', [\App\Http\Controllers\Admin\AuthController::class, 'logout'])->name('admin.logout');

// Admin protected area
Route::prefix('admin')->middleware('admin')->group(function () {
    Route::resource('challenges', \App\Http\Controllers\Admin\ChallengeController::class);
});
```

- [ ] **Step 8: Update layout with logout link**

In `backend/resources/views/admin/layout.blade.php`, replace the `<nav>` block with:

```html
<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container d-flex align-items-center">
        <a class="navbar-brand me-auto" href="/admin/challenges">BallSpot Admin</a>
        <span class="text-secondary small me-3">v1</span>
        <form action="{{ route('admin.logout') }}" method="POST" class="mb-0">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-secondary text-white">Logout</button>
        </form>
    </div>
</nav>
```

- [ ] **Step 9: Update DatabaseSeeder to seed admin user**

In `backend/database/seeders/DatabaseSeeder.php`, add admin user creation before or after existing seeders:

```php
public function run(): void
{
    \App\Models\User::firstOrCreate(
        ['email' => 'admin@ballspot.local'],
        [
            'name' => 'Admin',
            'username' => 'admin',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'is_admin' => true,
        ]
    );

    $this->call([
        SportSeeder::class,
        ChallengeSeeder::class,
    ]);
}
```

- [ ] **Step 10: Run migration and seed**

```bash
cd backend
php artisan migrate
php artisan db:seed
```

Expected output: migration runs, admin user seeded.

- [ ] **Step 11: Manual smoke test**

```bash
# In browser
# 1. Go to http://127.0.0.1:8000/admin/challenges → should redirect to /admin/login
# 2. Login with admin@ballspot.local / password → should land on challenges list
# 3. Click Logout → should return to /admin/login
```

- [ ] **Step 12: Commit**

```bash
git add backend/database/migrations/2026_06_21_000001_add_is_admin_to_users.php \
        backend/app/Http/Middleware/EnsureIsAdmin.php \
        backend/app/Http/Controllers/Admin/AuthController.php \
        backend/resources/views/admin/login.blade.php \
        backend/app/Models/User.php \
        backend/bootstrap/app.php \
        backend/routes/web.php \
        backend/database/seeders/DatabaseSeeder.php \
        backend/resources/views/admin/layout.blade.php
git commit -m "feat: add admin authentication (is_admin, EnsureIsAdmin middleware, login/logout)"
```

---

### Task 2: Click-to-Set Ball Position in Admin

**Files:**
- Modify: `backend/resources/views/admin/challenges/create.blade.php` — add image preview + click-to-set JS
- Modify: `backend/resources/views/admin/challenges/edit.blade.php` — same, plus show existing marker

**Interfaces:**
- Consumes: hidden_image file input (existing)
- Produces: when user picks image file → preview shown; click on preview → `ball_x_ratio` and `ball_y_ratio` hidden inputs updated; red dot marker shown at clicked position

- [ ] **Step 1: Update create.blade.php**

In `backend/resources/views/admin/challenges/create.blade.php`, after the `hidden_image` file input block (before the closing `</div>` of that `.mb-3`), add a preview container. Also change the `ball_x_ratio` and `ball_y_ratio` inputs to `readonly` (filled by JS) but keep manual entry as fallback. Replace the entire form section with the following (full file replacement):

```blade
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
```

- [ ] **Step 2: Update edit.blade.php**

Read existing `backend/resources/views/admin/challenges/edit.blade.php` first. Then replace the entire content with a version that includes the click-to-set picker. The edit form shows the current image and pre-fills the marker if x/y ratios are already set:

```blade
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
```

- [ ] **Step 3: Commit**

```bash
git add backend/resources/views/admin/challenges/create.blade.php \
        backend/resources/views/admin/challenges/edit.blade.php
git commit -m "feat: add click-to-set ball position in admin challenge forms"
```

---

### Task 3: Demo SVG Images

**Files:**
- Create: `backend/public/demo/challenges/corner-kick.svg`
- Create: `backend/public/demo/challenges/center-field.svg`
- Create: `backend/public/demo/challenges/penalty-spot.svg`
- Create: `backend/public/demo/challenges/crowd-scene.svg`
- Create: `backend/public/demo/challenges/goal-line.svg`
- Create: `backend/public/demo/challenges/kick-off.svg`
- Modify: `backend/database/seeders/ChallengeSeeder.php` — copy SVGs to storage/public, update paths

**Interfaces:**
- Produces: 6 SVG files in `backend/public/demo/challenges/` (16:9, 1600x900 viewBox, football scenes)
- Produces: ChallengeSeeder copies each SVG to `storage/app/public/challenges/hidden/` so `hidden_image_path = 'challenges/hidden/{slug}.svg'` resolves via `asset('storage/...')`

- [ ] **Step 1: Create corner-kick.svg**

Create `backend/public/demo/challenges/corner-kick.svg`:

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 900" width="1600" height="900">
  <!-- Sky -->
  <rect width="1600" height="900" fill="#87CEEB"/>
  <!-- Pitch -->
  <rect y="400" width="1600" height="500" fill="#3a7a3a"/>
  <!-- Pitch markings -->
  <line x1="0" y1="400" x2="1600" y2="400" stroke="#fff" stroke-width="4"/>
  <!-- Corner arc left -->
  <path d="M 0 400 Q 60 400 80 480" fill="none" stroke="#fff" stroke-width="3"/>
  <!-- Corner flag left -->
  <line x1="20" y1="380" x2="20" y2="450" stroke="#fff" stroke-width="3"/>
  <polygon points="20,380 50,395 20,410" fill="red"/>
  <!-- Crowd right side -->
  <rect x="800" y="100" width="800" height="300" fill="#6b3a2a" opacity="0.6"/>
  <!-- Players cluster -->
  <ellipse cx="200" cy="520" rx="18" ry="30" fill="#cc0000"/>
  <ellipse cx="250" cy="510" rx="18" ry="30" fill="#0000cc"/>
  <ellipse cx="300" cy="530" rx="18" ry="30" fill="#cc0000"/>
  <ellipse cx="350" cy="515" rx="18" ry="30" fill="#ffffff"/>
  <ellipse cx="160" cy="540" rx="18" ry="30" fill="#0000cc"/>
  <!-- Ball (hidden, near corner) - at x_ratio=0.12, y_ratio=0.85 => x=192, y=765 -->
  <circle cx="192" cy="765" r="14" fill="#f5f5f5" stroke="#333" stroke-width="2"/>
  <path d="M192 751 L200 758 L197 770 L187 770 L184 758 Z" fill="#333"/>
</svg>
```

- [ ] **Step 2: Create center-field.svg**

Create `backend/public/demo/challenges/center-field.svg`:

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 900" width="1600" height="900">
  <!-- Sky -->
  <rect width="1600" height="900" fill="#87CEEB"/>
  <!-- Pitch -->
  <rect y="350" width="1600" height="550" fill="#3a7a3a"/>
  <!-- Center line -->
  <line x1="800" y1="350" x2="800" y2="900" stroke="#fff" stroke-width="4"/>
  <!-- Center circle -->
  <ellipse cx="800" cy="600" rx="120" ry="80" fill="none" stroke="#fff" stroke-width="3"/>
  <!-- Center spot (obscured area) -->
  <ellipse cx="800" cy="580" rx="5" ry="4" fill="#fff"/>
  <!-- Players -->
  <ellipse cx="650" cy="560" rx="16" ry="28" fill="#cc0000"/>
  <ellipse cx="700" cy="580" rx="16" ry="28" fill="#0000cc"/>
  <ellipse cx="900" cy="570" rx="16" ry="28" fill="#cc0000"/>
  <ellipse cx="870" cy="550" rx="16" ry="28" fill="#0000cc"/>
  <ellipse cx="750" cy="540" rx="16" ry="28" fill="#ffffff" stroke="#ccc" stroke-width="1"/>
  <!-- Crowd stands both sides -->
  <rect x="0" y="100" width="300" height="250" fill="#5a4030" opacity="0.7"/>
  <rect x="1300" y="100" width="300" height="250" fill="#5a4030" opacity="0.7"/>
  <!-- Ball (hidden center) - x_ratio=0.50, y_ratio=0.50 => x=800, y=450 -->
  <circle cx="800" cy="450" r="13" fill="#f0f0f0" stroke="#333" stroke-width="2"/>
  <path d="M800 437 L808 444 L805 456 L795 456 L792 444 Z" fill="#333"/>
</svg>
```

- [ ] **Step 3: Create penalty-spot.svg**

Create `backend/public/demo/challenges/penalty-spot.svg`:

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 900" width="1600" height="900">
  <!-- Sky -->
  <rect width="1600" height="450" fill="#87CEEB"/>
  <!-- Pitch -->
  <rect y="450" width="1600" height="450" fill="#3a7a3a"/>
  <!-- Goal (centered) -->
  <rect x="550" y="350" width="500" height="200" fill="none" stroke="#fff" stroke-width="6"/>
  <!-- Net lines -->
  <line x1="550" y1="380" x2="1050" y2="380" stroke="#ddd" stroke-width="1" stroke-dasharray="10"/>
  <line x1="550" y1="410" x2="1050" y2="410" stroke="#ddd" stroke-width="1" stroke-dasharray="10"/>
  <line x1="550" y1="440" x2="1050" y2="440" stroke="#ddd" stroke-width="1" stroke-dasharray="10"/>
  <line x1="550" y1="470" x2="1050" y2="470" stroke="#ddd" stroke-width="1" stroke-dasharray="10"/>
  <line x1="600" y1="350" x2="600" y2="550" stroke="#ddd" stroke-width="1" stroke-dasharray="10"/>
  <line x1="650" y1="350" x2="650" y2="550" stroke="#ddd" stroke-width="1" stroke-dasharray="10"/>
  <line x1="700" y1="350" x2="700" y2="550" stroke="#ddd" stroke-width="1" stroke-dasharray="10"/>
  <line x1="750" y1="350" x2="750" y2="550" stroke="#ddd" stroke-width="1" stroke-dasharray="10"/>
  <line x1="800" y1="350" x2="800" y2="550" stroke="#ddd" stroke-width="1" stroke-dasharray="10"/>
  <line x1="850" y1="350" x2="850" y2="550" stroke="#ddd" stroke-width="1" stroke-dasharray="10"/>
  <line x1="900" y1="350" x2="900" y2="550" stroke="#ddd" stroke-width="1" stroke-dasharray="10"/>
  <line x1="950" y1="350" x2="950" y2="550" stroke="#ddd" stroke-width="1" stroke-dasharray="10"/>
  <line x1="1000" y1="350" x2="1000" y2="550" stroke="#ddd" stroke-width="1" stroke-dasharray="10"/>
  <!-- Penalty area line -->
  <rect x="350" y="450" width="900" height="300" fill="none" stroke="#fff" stroke-width="3"/>
  <!-- Goalkeeper -->
  <ellipse cx="800" cy="470" rx="20" ry="60" fill="#ffaa00"/>
  <!-- Penalty taker (far) -->
  <ellipse cx="800" cy="750" rx="16" ry="28" fill="#cc0000"/>
  <!-- Ball at penalty spot - x_ratio=0.50, y_ratio=0.78 => x=800, y=702 -->
  <circle cx="800" cy="702" r="14" fill="#f5f5f5" stroke="#333" stroke-width="2"/>
  <path d="M800 688 L808 695 L805 707 L795 707 L792 695 Z" fill="#333"/>
  <!-- Crowd -->
  <rect x="0" y="0" width="1600" height="220" fill="#4a3020" opacity="0.8"/>
</svg>
```

- [ ] **Step 4: Create crowd-scene.svg**

Create `backend/public/demo/challenges/crowd-scene.svg`:

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 900" width="1600" height="900">
  <!-- Sky -->
  <rect width="1600" height="900" fill="#6a8fbb"/>
  <!-- Large stand fills most of view -->
  <rect x="0" y="0" width="1600" height="600" fill="#5a3520"/>
  <!-- Crowd rows - many colored dots/rectangles -->
  <g opacity="0.9">
    <rect x="0" y="50" width="1600" height="30" fill="#cc2200" opacity="0.6"/>
    <rect x="0" y="100" width="1600" height="30" fill="#0022cc" opacity="0.6"/>
    <rect x="0" y="150" width="1600" height="30" fill="#cc2200" opacity="0.6"/>
    <rect x="0" y="200" width="1600" height="30" fill="#ffffff" opacity="0.3"/>
    <rect x="0" y="250" width="1600" height="30" fill="#cc2200" opacity="0.6"/>
    <rect x="0" y="300" width="1600" height="30" fill="#0022cc" opacity="0.6"/>
    <rect x="0" y="350" width="1600" height="30" fill="#cc2200" opacity="0.6"/>
    <rect x="0" y="400" width="1600" height="30" fill="#ffffff" opacity="0.3"/>
    <rect x="0" y="450" width="1600" height="30" fill="#0022cc" opacity="0.6"/>
    <rect x="0" y="500" width="1600" height="30" fill="#cc2200" opacity="0.6"/>
  </g>
  <!-- Pitch strip at bottom -->
  <rect y="600" width="1600" height="300" fill="#3a7a3a"/>
  <!-- Pitch line -->
  <line x1="0" y1="600" x2="1600" y2="600" stroke="#fff" stroke-width="4"/>
  <!-- Players in pitch area -->
  <ellipse cx="500" cy="680" rx="16" ry="28" fill="#cc0000"/>
  <ellipse cx="600" cy="660" rx="16" ry="28" fill="#0000cc"/>
  <ellipse cx="900" cy="670" rx="16" ry="28" fill="#cc0000"/>
  <ellipse cx="1100" cy="650" rx="16" ry="28" fill="#0000cc"/>
  <!-- Ball hidden in crowd transition - x_ratio=0.33, y_ratio=0.60 => x=528, y=540 -->
  <circle cx="528" cy="540" r="12" fill="#f5f5f5" stroke="#555" stroke-width="1.5" opacity="0.7"/>
  <path d="M528 528 L535 535 L532 546 L524 546 L521 535 Z" fill="#555" opacity="0.7"/>
</svg>
```

- [ ] **Step 5: Create goal-line.svg**

Create `backend/public/demo/challenges/goal-line.svg`:

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 900" width="1600" height="900">
  <!-- Sky/stadium interior -->
  <rect width="1600" height="900" fill="#87CEEB"/>
  <!-- Pitch -->
  <rect y="500" width="1600" height="400" fill="#3a7a3a"/>
  <!-- Goal line -->
  <line x1="0" y1="500" x2="1600" y2="500" stroke="#fff" stroke-width="5"/>
  <!-- Large goal right side -->
  <rect x="1100" y="250" width="500" height="300" fill="none" stroke="#fff" stroke-width="6"/>
  <!-- Net -->
  <line x1="1100" y1="300" x2="1600" y2="300" stroke="#ddd" stroke-width="1" stroke-dasharray="8"/>
  <line x1="1100" y1="380" x2="1600" y2="380" stroke="#ddd" stroke-width="1" stroke-dasharray="8"/>
  <line x1="1100" y1="460" x2="1600" y2="460" stroke="#ddd" stroke-width="1" stroke-dasharray="8"/>
  <line x1="1200" y1="250" x2="1200" y2="550" stroke="#ddd" stroke-width="1" stroke-dasharray="8"/>
  <line x1="1300" y1="250" x2="1300" y2="550" stroke="#ddd" stroke-width="1" stroke-dasharray="8"/>
  <line x1="1400" y1="250" x2="1400" y2="550" stroke="#ddd" stroke-width="1" stroke-dasharray="8"/>
  <line x1="1500" y1="250" x2="1500" y2="550" stroke="#ddd" stroke-width="1" stroke-dasharray="8"/>
  <!-- Goal area box -->
  <rect x="1100" y="500" width="500" height="200" fill="none" stroke="#fff" stroke-width="3"/>
  <!-- Goalkeeper diving -->
  <ellipse cx="1200" cy="450" rx="22" ry="36" fill="#ffaa00" transform="rotate(-30 1200 450)"/>
  <!-- Crowd stand top -->
  <rect x="0" y="0" width="1600" height="200" fill="#5a3520" opacity="0.7"/>
  <!-- Players -->
  <ellipse cx="1050" cy="520" rx="16" ry="28" fill="#cc0000"/>
  <ellipse cx="950" cy="510" rx="16" ry="28" fill="#0000cc"/>
  <!-- Ball near goal line right - x_ratio=0.72, y_ratio=0.92 => x=1152, y=828 -->
  <circle cx="1152" cy="828" r="14" fill="#f5f5f5" stroke="#333" stroke-width="2"/>
  <path d="M1152 814 L1160 821 L1157 833 L1147 833 L1144 821 Z" fill="#333"/>
</svg>
```

- [ ] **Step 6: Create kick-off.svg**

Create `backend/public/demo/challenges/kick-off.svg`:

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 900" width="1600" height="900">
  <!-- Sky -->
  <rect width="1600" height="900" fill="#87CEEB"/>
  <!-- Pitch -->
  <rect y="300" width="1600" height="600" fill="#3a7a3a"/>
  <!-- Pitch line horizon -->
  <line x1="0" y1="300" x2="1600" y2="300" stroke="#fff" stroke-width="4"/>
  <!-- Center line -->
  <line x1="800" y1="300" x2="800" y2="900" stroke="#fff" stroke-width="4"/>
  <!-- Center circle (large, perspective) -->
  <ellipse cx="800" cy="550" rx="200" ry="110" fill="none" stroke="#fff" stroke-width="3"/>
  <!-- Center spot -->
  <circle cx="800" cy="520" r="6" fill="#fff"/>
  <!-- Both halves darker/lighter alternating stripes -->
  <rect x="0" y="300" width="1600" height="90" fill="#4a8a4a" opacity="0.3"/>
  <rect x="0" y="480" width="1600" height="90" fill="#4a8a4a" opacity="0.3"/>
  <rect x="0" y="660" width="1600" height="90" fill="#4a8a4a" opacity="0.3"/>
  <rect x="0" y="840" width="1600" height="90" fill="#4a8a4a" opacity="0.3"/>
  <!-- Players for kick-off -->
  <ellipse cx="750" cy="520" rx="17" ry="30" fill="#cc0000"/>
  <ellipse cx="850" cy="530" rx="17" ry="30" fill="#0000cc"/>
  <ellipse cx="700" cy="560" rx="17" ry="30" fill="#cc0000"/>
  <ellipse cx="900" cy="545" rx="17" ry="30" fill="#0000cc"/>
  <ellipse cx="650" cy="600" rx="17" ry="30" fill="#cc0000"/>
  <!-- Crowd stands behind both goals (left/right) -->
  <rect x="0" y="0" width="400" height="300" fill="#5a3520" opacity="0.6"/>
  <rect x="1200" y="0" width="400" height="300" fill="#5a3520" opacity="0.6"/>
  <!-- Ball at kick-off - x_ratio=0.50, y_ratio=0.48 => x=800, y=432 -->
  <circle cx="800" cy="432" r="14" fill="#f5f5f5" stroke="#333" stroke-width="2"/>
  <path d="M800 418 L808 425 L805 437 L795 437 L792 425 Z" fill="#333"/>
</svg>
```

- [ ] **Step 7: Update ChallengeSeeder to copy SVGs to storage**

Replace `backend/database/seeders/ChallengeSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Challenge;
use App\Models\Sport;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class ChallengeSeeder extends Seeder
{
    public function run(): void
    {
        $sport = Sport::where('slug', 'football')->first();

        $challenges = [
            ['title' => 'Corner Kick',  'slug' => 'corner-kick',  'ball_x_ratio' => 0.12, 'ball_y_ratio' => 0.85, 'difficulty' => 'easy'],
            ['title' => 'Center Field', 'slug' => 'center-field', 'ball_x_ratio' => 0.50, 'ball_y_ratio' => 0.50, 'difficulty' => 'easy'],
            ['title' => 'Penalty Spot', 'slug' => 'penalty-spot', 'ball_x_ratio' => 0.50, 'ball_y_ratio' => 0.78, 'difficulty' => 'medium'],
            ['title' => 'Crowd Scene',  'slug' => 'crowd-scene',  'ball_x_ratio' => 0.33, 'ball_y_ratio' => 0.60, 'difficulty' => 'hard'],
            ['title' => 'Goal Line',    'slug' => 'goal-line',    'ball_x_ratio' => 0.72, 'ball_y_ratio' => 0.92, 'difficulty' => 'hard'],
            ['title' => 'Kick Off',     'slug' => 'kick-off',     'ball_x_ratio' => 0.50, 'ball_y_ratio' => 0.48, 'difficulty' => 'medium'],
        ];

        foreach ($challenges as $c) {
            $svgSource = public_path("demo/challenges/{$c['slug']}.svg");
            $storagePath = "challenges/hidden/{$c['slug']}.svg";

            if (file_exists($svgSource)) {
                Storage::disk('public')->put($storagePath, file_get_contents($svgSource));
            }

            Challenge::firstOrCreate(
                ['title' => $c['title'], 'sport_id' => $sport->id],
                [
                    'hidden_image_path' => $storagePath,
                    'ball_x_ratio'      => $c['ball_x_ratio'],
                    'ball_y_ratio'      => $c['ball_y_ratio'],
                    'difficulty'        => $c['difficulty'],
                    'status'            => 'active',
                ]
            );
        }
    }
}
```

- [ ] **Step 8: Re-seed and verify**

```bash
cd backend
php artisan migrate:fresh --seed
php artisan storage:link
```

Expected: 6 SVG files copied to `storage/app/public/challenges/hidden/`, accessible at `http://127.0.0.1:8000/storage/challenges/hidden/corner-kick.svg`.

- [ ] **Step 9: Commit**

```bash
git add backend/public/demo/challenges/ \
        backend/database/seeders/ChallengeSeeder.php
git commit -m "feat: add 6 demo SVG football scenes, update seeder to copy to storage"
```

---

### Task 4: Completion Flow Improvements

**Files:**
- Modify: `backend/app/Http/Controllers/Api/LeagueController.php` — update `currentRound()` to return `has_current_round`, `reason`, and `progress`
- Modify: `mobile/src/api/roundApi.ts` — update `CurrentRoundResponse` type
- Modify: `mobile/src/screens/LeagueDetailScreen.tsx` — show progress bar/text

**Interfaces:**
- `currentRound` API response (new shape):
  ```json
  {
    "current_round": {...} | null,
    "has_current_round": true | false,
    "completed": true | false,
    "reason": "has_pending_round" | "all_rounds_complete",
    "progress": { "completed": 2, "total": 5, "remaining": 3, "pct": 40 }
  }
  ```
- Mobile `CurrentRoundResponse` type updated to match

- [ ] **Step 1: Update LeagueController::currentRound()**

Replace the `currentRound` method in `backend/app/Http/Controllers/Api/LeagueController.php`:

```php
public function currentRound(Request $request, League $league)
{
    $userId = $request->user()->id;
    if (!$league->members()->where('user_id', $userId)->exists()) {
        return response()->json(['message' => 'Not a member of this league'], 403);
    }

    $totalRounds = $league->rounds()->where('status', 'open')->count();
    $completedRounds = $league->rounds()
        ->whereHas('guesses', fn($q) => $q->where('user_id', $userId))
        ->count();
    $remaining = max(0, $totalRounds - $completedRounds);
    $pct = $totalRounds > 0 ? (int) round($completedRounds / $totalRounds * 100) : 0;

    $progress = [
        'completed' => $completedRounds,
        'total' => $totalRounds,
        'remaining' => $remaining,
        'pct' => $pct,
    ];

    $round = $league->rounds()
        ->where('status', 'open')
        ->whereDoesntHave('guesses', fn($q) => $q->where('user_id', $userId))
        ->orderBy('round_number')
        ->with('challenge')
        ->first();

    if (!$round) {
        return response()->json([
            'current_round' => null,
            'has_current_round' => false,
            'completed' => true,
            'reason' => 'all_rounds_complete',
            'progress' => $progress,
        ]);
    }

    return response()->json([
        'current_round' => new LeagueRoundResource($round),
        'has_current_round' => true,
        'completed' => false,
        'reason' => 'has_pending_round',
        'progress' => $progress,
    ]);
}
```

- [ ] **Step 2: Update mobile CurrentRoundResponse type**

In `mobile/src/api/roundApi.ts`, update the `CurrentRoundResponse` interface and/or add the new fields. Read the file first to see the current shape, then update:

```typescript
export interface CurrentRoundProgress {
  completed: number;
  total: number;
  remaining: number;
  pct: number;
}

export interface CurrentRoundResponse {
  current_round: LeagueRound | null;
  has_current_round: boolean;
  completed: boolean;
  reason: 'has_pending_round' | 'all_rounds_complete';
  progress: CurrentRoundProgress;
}
```

Export `CurrentRoundProgress` and `CurrentRoundResponse` from `roundApi.ts` so they can be imported in screens.

- [ ] **Step 3: Update LeagueDetailScreen to show progress**

In `mobile/src/screens/LeagueDetailScreen.tsx`, add a `progress` state and display it. Add after the `hasRound` state:

```typescript
const [progress, setProgress] = useState<{ completed: number; total: number; pct: number } | null>(null);
```

Inside `load()`, after `setHasRound(...)`:

```typescript
if (cr.progress) setProgress(cr.progress);
```

In the JSX, add a progress row in the `actions` view, below the play/done banner and above the Full Leaderboard button:

```tsx
{progress && (
  <View style={styles.progressBox}>
    <View style={styles.progressBarBg}>
      <View style={[styles.progressBarFill, { width: `${progress.pct}%` }]} />
    </View>
    <Text style={styles.progressText}>
      {progress.completed}/{progress.total} rounds completed ({progress.pct}%)
    </Text>
  </View>
)}
```

Add styles:

```typescript
progressBox: { marginTop: 4 },
progressBarBg: { height: 6, backgroundColor: colors.border, borderRadius: 3, overflow: 'hidden' },
progressBarFill: { height: 6, backgroundColor: colors.primary, borderRadius: 3 },
progressText: { fontSize: 12, color: colors.textSecondary, marginTop: 4, textAlign: 'center' },
```

- [ ] **Step 4: Run TypeScript check**

```bash
cd mobile
npx tsc --noEmit
```

Expected: 0 errors.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/Api/LeagueController.php \
        mobile/src/api/roundApi.ts \
        mobile/src/screens/LeagueDetailScreen.tsx
git commit -m "feat: add progress tracking to currentRound response, show in LeagueDetail"
```

---

### Task 5: Result Screen Improvements

**Files:**
- Modify: `mobile/src/screens/ResultScreen.tsx` — add score rating text, add "Play Next Round" button

**Interfaces:**
- Score rating text: `score >= 95` → "Perfect! 🎯", `score >= 75` → "Very close!", `score >= 50` → "Not bad", `score >= 25` → "Far away", `score < 25` → "Missed"
- Buttons: always show "Play Next Round" (navigates to LeagueDetail, which then offers Play if round available) and keep "Back to League"
- `leagueName` is already threaded through Result params

- [ ] **Step 1: Update ResultScreen**

Replace `mobile/src/screens/ResultScreen.tsx` with:

```typescript
import React, { useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { ImageGuessPicker } from '../components/ImageGuessPicker';
import { roundApi } from '../api/roundApi';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { GuessResult } from '../types/guess';

type Props = NativeStackScreenProps<RootStackParamList, 'Result'>;

function getScoreRating(score: number): string {
  if (score >= 95) return 'Perfect! 🎯';
  if (score >= 75) return 'Very close!';
  if (score >= 50) return 'Not bad';
  if (score >= 25) return 'Far away';
  return 'Missed';
}

export function ResultScreen({ route, navigation }: Props) {
  const { roundId, leagueId, imageUrl, leagueName } = route.params;
  const [result, setResult] = useState<GuessResult | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    roundApi.result(roundId)
      .then(setResult)
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [roundId]);

  if (loading) {
    return <View style={styles.center}><ActivityIndicator color={colors.primary} size="large" /></View>;
  }

  if (!result) {
    return (
      <Screen padding>
        <Text style={{ color: colors.text }}>No result found.</Text>
        <AppButton title="Back to League" onPress={() => navigation.goBack()} style={{ marginTop: spacing.lg }} />
      </Screen>
    );
  }

  const scoreColor = result.score >= 80 ? colors.success : result.score >= 50 ? colors.warning : colors.error;
  const rating = getScoreRating(result.score);

  return (
    <Screen scroll padding>
      <View style={styles.scoreBox}>
        <Text style={styles.scoreLabel}>Your Score</Text>
        <Text style={[styles.score, { color: scoreColor }]}>{result.score}</Text>
        <Text style={styles.rating}>{rating}</Text>
        <Text style={styles.distance}>Distance: {(result.distance * 100).toFixed(1)}%</Text>
      </View>

      {imageUrl ? (
        <ImageGuessPicker
          imageUri={imageUrl}
          interactive={false}
          markers={[
            { x_ratio: result.guess_x_ratio, y_ratio: result.guess_y_ratio, color: colors.accent, label: 'U' },
            { x_ratio: result.ball_x_ratio, y_ratio: result.ball_y_ratio, color: colors.success, label: 'B' },
          ]}
        />
      ) : null}

      <View style={styles.coordsBox}>
        <View style={styles.coordRow}>
          <View style={[styles.dot, { backgroundColor: colors.accent }]} />
          <Text style={styles.coordText}>Your guess: ({result.guess_x_ratio.toFixed(3)}, {result.guess_y_ratio.toFixed(3)})</Text>
        </View>
        <View style={styles.coordRow}>
          <View style={[styles.dot, { backgroundColor: colors.success }]} />
          <Text style={styles.coordText}>Ball position: ({result.ball_x_ratio.toFixed(3)}, {result.ball_y_ratio.toFixed(3)})</Text>
        </View>
      </View>

      <AppButton
        title="Play Next Round"
        onPress={() => navigation.navigate('LeagueDetail', { leagueId, leagueName })}
        style={styles.nextBtn}
      />
      <AppButton
        title="Back to League"
        onPress={() => navigation.navigate('LeagueDetail', { leagueId, leagueName })}
        variant="secondary"
        style={styles.backBtn}
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  center: { flex: 1, backgroundColor: colors.background, alignItems: 'center', justifyContent: 'center' },
  scoreBox: { alignItems: 'center', paddingVertical: spacing.xxl, backgroundColor: colors.surface, borderRadius: 16, marginBottom: spacing.lg },
  scoreLabel: { fontSize: 14, color: colors.textSecondary, textTransform: 'uppercase', letterSpacing: 1 },
  score: { fontSize: 72, fontWeight: '800', marginVertical: spacing.sm },
  rating: { fontSize: 20, fontWeight: '600', color: colors.text, marginBottom: spacing.xs },
  distance: { fontSize: 15, color: colors.textSecondary },
  coordsBox: { backgroundColor: colors.surface, borderRadius: 12, padding: spacing.md, marginBottom: spacing.lg, gap: spacing.sm },
  coordRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  dot: { width: 12, height: 12, borderRadius: 6 },
  coordText: { color: colors.textSecondary, fontSize: 13 },
  nextBtn: { marginBottom: spacing.sm },
  backBtn: { marginTop: 0 },
});
```

- [ ] **Step 2: Check spacing.xs exists in theme**

Read `mobile/src/theme/spacing.ts`. If `xs` is missing, add it (value 4). The spacing file likely has: `xs`, `sm`, `md`, `lg`, `xl`, `xxl`. If `xs` is missing, add `xs: 4` to the export.

- [ ] **Step 3: TypeScript check**

```bash
cd mobile
npx tsc --noEmit
```

Expected: 0 errors.

- [ ] **Step 4: Commit**

```bash
git add mobile/src/screens/ResultScreen.tsx mobile/src/theme/spacing.ts
git commit -m "feat: add score rating text and Play Next Round button to ResultScreen"
```

---

### Task 6: Leaderboard Improvements

**Files:**
- Modify: `backend/app/Http/Controllers/Api/LeaderboardController.php` — add `avg_score`, `is_current_user`
- Modify: `backend/app/Http/Resources/LeaderboardEntryResource.php` — expose `avg_score`, `is_current_user`
- Modify: `mobile/src/types/guess.ts` — add `avg_score`, `is_current_user` to `LeaderboardEntry`
- Modify: `mobile/src/components/LeaderboardList.tsx` — highlight current user, show avg_score
- Modify: `mobile/src/screens/LeaderboardScreen.tsx` — pass current user id for highlighting

**Interfaces:**
- `LeaderboardEntry` adds `avg_score: number` (rounded to 1 decimal) and `is_current_user: boolean`
- API query: add `ROUND(AVG(guesses.score), 1) as avg_score` to the DB query; compare `user_id` to `$request->user()->id`
- `LeaderboardList` props add optional `currentUserId?: number`; rows highlighted when `item.is_current_user`

- [ ] **Step 1: Update LeaderboardController**

Replace `backend/app/Http/Controllers/Api/LeaderboardController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaderboardEntryResource;
use App\Models\League;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaderboardController extends Controller
{
    public function index(Request $request, League $league)
    {
        if (!$league->members()->where('user_id', $request->user()->id)->exists()) {
            return response()->json(['message' => 'Not a member of this league'], 403);
        }

        $currentUserId = $request->user()->id;

        $entries = DB::table('guesses')
            ->join('league_rounds', 'guesses.league_round_id', '=', 'league_rounds.id')
            ->join('users', 'guesses.user_id', '=', 'users.id')
            ->where('league_rounds.league_id', $league->id)
            ->select(
                'users.id as user_id',
                'users.username',
                'users.name',
                DB::raw('SUM(guesses.score) as total_score'),
                DB::raw('COUNT(guesses.id) as guesses_count'),
                DB::raw('ROUND(AVG(guesses.score), 1) as avg_score')
            )
            ->groupBy('users.id', 'users.username', 'users.name')
            ->orderByDesc('total_score')
            ->get()
            ->map(fn($row, $i) => array_merge((array) $row, [
                'rank' => $i + 1,
                'is_current_user' => (int) $row->user_id === $currentUserId,
            ]));

        return LeaderboardEntryResource::collection($entries);
    }
}
```

- [ ] **Step 2: Update LeaderboardEntryResource**

Replace `backend/app/Http/Resources/LeaderboardEntryResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LeaderboardEntryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'rank' => $this->resource['rank'],
            'user_id' => $this->resource['user_id'],
            'username' => $this->resource['username'],
            'name' => $this->resource['name'],
            'total_score' => $this->resource['total_score'],
            'guesses_count' => $this->resource['guesses_count'],
            'avg_score' => $this->resource['avg_score'],
            'is_current_user' => $this->resource['is_current_user'],
        ];
    }
}
```

- [ ] **Step 3: Update mobile LeaderboardEntry type**

In `mobile/src/types/guess.ts`, add `avg_score` and `is_current_user` to `LeaderboardEntry`:

```typescript
export interface LeaderboardEntry {
  rank: number;
  user_id: number;
  username: string;
  name: string;
  total_score: number;
  guesses_count: number;
  avg_score: number;
  is_current_user: boolean;
}
```

- [ ] **Step 4: Update LeaderboardList to highlight current user and show avg**

Replace `mobile/src/components/LeaderboardList.tsx`:

```typescript
import React from 'react';
import { View, Text, StyleSheet, FlatList } from 'react-native';
import { LeaderboardEntry } from '../types/guess';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

interface Props {
  entries: LeaderboardEntry[];
}

function getRankEmoji(rank: number) {
  if (rank === 1) return '🥇';
  if (rank === 2) return '🥈';
  if (rank === 3) return '🥉';
  return `#${rank}`;
}

function EntryRow({ item }: { item: LeaderboardEntry }) {
  return (
    <View style={[styles.row, item.is_current_user && styles.rowHighlight]}>
      <Text style={styles.rank}>{getRankEmoji(item.rank)}</Text>
      <View style={styles.info}>
        <Text style={[styles.name, item.is_current_user && styles.nameHighlight]}>
          {item.name}{item.is_current_user ? ' (you)' : ''}
        </Text>
        <Text style={styles.username}>@{item.username}</Text>
      </View>
      <View style={styles.scoreBox}>
        <Text style={[styles.score, item.is_current_user && styles.scoreHighlight]}>{item.total_score}</Text>
        <Text style={styles.guesses}>avg {item.avg_score} · {item.guesses_count} rounds</Text>
      </View>
    </View>
  );
}

export function LeaderboardList({ entries }: Props) {
  if (entries.length === 0) {
    return (
      <View style={styles.emptyWrap}>
        <Text style={styles.emptyIcon}>🏆</Text>
        <Text style={styles.emptyTitle}>No scores yet</Text>
        <Text style={styles.emptyText}>Play some rounds to see the leaderboard!</Text>
      </View>
    );
  }
  return (
    <FlatList
      data={entries}
      keyExtractor={(item) => String(item.user_id)}
      renderItem={({ item }) => <EntryRow item={item} />}
      ItemSeparatorComponent={() => <View style={styles.separator} />}
    />
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing.md, paddingHorizontal: spacing.md },
  rowHighlight: { backgroundColor: colors.surfaceElevated },
  rank: { fontSize: 22, width: 44, textAlign: 'center' },
  info: { flex: 1, marginLeft: spacing.sm },
  name: { fontSize: 15, fontWeight: '700', color: colors.text },
  nameHighlight: { color: colors.primary },
  username: { fontSize: 12, color: colors.textSecondary },
  scoreBox: { alignItems: 'flex-end' },
  score: { fontSize: 20, fontWeight: '700', color: colors.primary },
  scoreHighlight: { color: colors.warning },
  guesses: { fontSize: 11, color: colors.textMuted },
  separator: { height: 1, backgroundColor: colors.border, marginLeft: spacing.md },
  emptyWrap: { padding: spacing.xxl, alignItems: 'center' },
  emptyIcon: { fontSize: 48, marginBottom: spacing.md },
  emptyTitle: { fontSize: 18, fontWeight: '700', color: colors.text, marginBottom: spacing.sm },
  emptyText: { fontSize: 14, color: colors.textSecondary, textAlign: 'center' },
});
```

- [ ] **Step 5: TypeScript check**

```bash
cd mobile
npx tsc --noEmit
```

Expected: 0 errors.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Api/LeaderboardController.php \
        backend/app/Http/Resources/LeaderboardEntryResource.php \
        mobile/src/types/guess.ts \
        mobile/src/components/LeaderboardList.tsx
git commit -m "feat: add avg_score and current user highlight to leaderboard"
```

---

### Task 7: Mobile Developer Docs

**Files:**
- Create: `mobile/.env.example`
- Modify: `docs/test-report.md` — update physical device section

**Interfaces:**
- `mobile/.env.example` contains `EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8000/api` with comments
- Physical device docs: explain `--host=0.0.0.0` flag and `EXPO_PUBLIC_API_BASE_URL`

- [ ] **Step 1: Create mobile/.env.example**

Create `mobile/.env.example`:

```
# BallSpot Mobile — Environment Variables
# Copy to .env and update values for your environment.
# Expo only exposes variables prefixed with EXPO_PUBLIC_ to the app.

# API base URL (no trailing slash)
# Simulator/emulator: use 127.0.0.1
# Physical device: use your computer's LAN IP (e.g. 192.168.1.x)
EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8000/api
```

- [ ] **Step 2: Update test-report.md physical device section**

In `docs/test-report.md`, replace the current "How to Run the Mobile App" section with:

```markdown
## How to Run the Mobile App

```bash
cd mobile

# Install dependencies (first time)
npm install

# Copy env file (first time)
cp .env.example .env

# Start Expo dev server (simulator/emulator)
npx expo start

# Then press:
#   a → Android emulator
#   i → iOS simulator
#   w → Web browser
```

**Physical device (iOS/Android):**

1. Find your computer's LAN IP: `ipconfig` (Windows) or `ifconfig` (Mac/Linux)
2. Edit `mobile/.env` and set:
   ```
   EXPO_PUBLIC_API_BASE_URL=http://192.168.1.x:8000/api
   ```
3. Start the backend allowing LAN connections:
   ```bash
   cd backend
   php artisan serve --host=0.0.0.0 --port=8000
   ```
4. Start Expo:
   ```bash
   cd mobile
   npx expo start --host=lan
   ```
5. Scan the QR code with Expo Go (Android) or Camera (iOS)
```

- [ ] **Step 3: Commit**

```bash
git add mobile/.env.example docs/test-report.md
git commit -m "docs: add mobile .env.example and physical device setup instructions"
```

---

### Task 8: Visual Polish

**Files:**
- Modify: `mobile/src/screens/HomeScreen.tsx` — add subtitle "Guess the hidden ball. Beat your friends.", improve league cards
- Modify: `mobile/src/screens/GuessScreen.tsx` — add tap instruction text before image

**Interfaces:**
- Subtitle displayed below app name or greeting in HomeScreen header/top bar
- League card: add a `status` badge or round indicator, slightly more polish
- GuessScreen: brief instruction "Tap the image to place your guess" visible before user taps

- [ ] **Step 1: Update HomeScreen**

Replace the `topBar` section in `mobile/src/screens/HomeScreen.tsx` to add a subtitle. Replace the full return JSX in `HomeScreen` with:

```tsx
return (
  <Screen padding={false}>
    <View style={styles.topBar}>
      <View style={styles.topBarLeft}>
        <Text style={styles.greeting}>Hey, {user?.name || '…'} 👋</Text>
        <Text style={styles.sub}>@{user?.username || '…'}</Text>
      </View>
      <AppButton title="Logout" onPress={handleLogout} variant="secondary" style={styles.logoutBtn} />
    </View>
    <View style={styles.heroBar}>
      <Text style={styles.heroText}>Guess the hidden ball. Beat your friends.</Text>
    </View>
    <FlatList
      data={leagues}
      keyExtractor={(l) => String(l.id)}
      renderItem={renderLeague}
      contentContainerStyle={styles.list}
      ListEmptyComponent={!loading ? (
        <View style={styles.emptyWrap}>
          <Text style={styles.emptyIcon}>⚽</Text>
          <Text style={styles.emptyTitle}>No leagues yet</Text>
          <Text style={styles.emptyText}>Create a league and invite friends to play!</Text>
        </View>
      ) : null}
      ListFooterComponent={
        <View style={styles.actions}>
          <AppButton title="+ Create League" onPress={() => navigation.navigate('CreateLeague')} style={styles.actionBtn} />
          <AppButton title="Join League" onPress={() => navigation.navigate('JoinLeague')} variant="secondary" />
        </View>
      }
    />
  </Screen>
);
```

Update the styles object (replace/add):

```typescript
topBar: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', padding: spacing.md, backgroundColor: colors.surface },
topBarLeft: { flex: 1 },
greeting: { fontSize: 18, fontWeight: '700', color: colors.text },
sub: { fontSize: 13, color: colors.textSecondary },
logoutBtn: { height: 36, paddingHorizontal: spacing.md },
heroBar: { backgroundColor: colors.surfaceElevated, paddingVertical: spacing.sm, paddingHorizontal: spacing.md, borderBottomWidth: 1, borderBottomColor: colors.border },
heroText: { fontSize: 13, color: colors.textSecondary, fontStyle: 'italic', textAlign: 'center' },
list: { padding: spacing.md, gap: spacing.sm },
card: { backgroundColor: colors.surface, borderRadius: 14, padding: spacing.md, borderWidth: 1, borderColor: colors.border },
leagueName: { fontSize: 16, fontWeight: '700', color: colors.text, marginBottom: 6 },
leagueMeta: { fontSize: 13, color: colors.textSecondary },
emptyWrap: { paddingVertical: spacing.xxl, alignItems: 'center' },
emptyIcon: { fontSize: 48, marginBottom: spacing.md },
emptyTitle: { fontSize: 18, fontWeight: '700', color: colors.text, marginBottom: spacing.sm },
emptyText: { fontSize: 14, color: colors.textSecondary, textAlign: 'center' },
empty: { textAlign: 'center', color: colors.textSecondary, padding: spacing.xl },
actions: { gap: spacing.sm, marginTop: spacing.md },
actionBtn: { marginBottom: 0 },
```

- [ ] **Step 2: Update GuessScreen instruction text**

In `mobile/src/screens/GuessScreen.tsx`, the `ImageGuessPicker` already shows "Tap on the image to guess the ball position" via its `hint` text. Add an additional instruction above the image in the `info` block. In the `<View style={styles.info}>`, add after `<Text style={styles.difficulty}>`:

```tsx
<Text style={styles.instruction}>Tap the image below to mark where you think the ball is hidden.</Text>
```

Add style:

```typescript
instruction: { fontSize: 12, color: colors.textSecondary, marginTop: 6, fontStyle: 'italic' },
```

- [ ] **Step 3: TypeScript check**

```bash
cd mobile
npx tsc --noEmit
```

Expected: 0 errors.

- [ ] **Step 4: Commit**

```bash
git add mobile/src/screens/HomeScreen.tsx mobile/src/screens/GuessScreen.tsx
git commit -m "feat: add hero subtitle, improved empty state, and GuessScreen instruction"
```

---

### Task 9: Tests

**Files:**
- Create: `backend/tests/Feature/AdminTest.php` — admin route access tests
- No new mobile test files (TypeScript check IS the test)

**Interfaces:**
- 3 test cases:
  1. Unauthenticated request to `/admin/challenges` → redirect to `/admin/login`
  2. Authenticated non-admin request → 403
  3. Authenticated admin request → 200

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/AdminTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $response = $this->get('/admin/challenges');
        $response->assertRedirect('/admin/login');
    }

    public function test_non_admin_user_gets_403(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $response = $this->actingAs($user)->get('/admin/challenges');
        $response->assertStatus(403);
    }

    public function test_admin_user_can_access_challenges(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $response = $this->actingAs($admin)->get('/admin/challenges');
        $response->assertStatus(200);
    }
}
```

- [ ] **Step 2: Update UserFactory to support is_admin**

In `backend/database/factories/UserFactory.php`, the `definition()` method should already have all User fields. Add `'is_admin' => false` to the default definition:

```php
public function definition(): array
{
    return [
        'name' => fake()->name(),
        'username' => fake()->unique()->userName(),
        'email' => fake()->unique()->safeEmail(),
        'email_verified_at' => now(),
        'password' => static::$password ??= Hash::make('password'),
        'remember_token' => Str::random(10),
        'is_admin' => false,
    ];
}
```

- [ ] **Step 3: Run the backend tests**

```bash
cd backend
php artisan test
```

Expected: all tests pass (14 total: 11 existing + 3 new admin tests).

- [ ] **Step 4: Run TypeScript check**

```bash
cd mobile
npx tsc --noEmit
```

Expected: 0 errors.

- [ ] **Step 5: Commit**

```bash
git add backend/tests/Feature/AdminTest.php \
        backend/database/factories/UserFactory.php
git commit -m "test: add admin route access tests (unauthenticated redirect, 403, 200)"
```

---

### Task 10: Documentation Update

**Files:**
- Modify: `docs/api-contract.md` — update `current-round` response shape, leaderboard response shape, admin section
- Modify: `docs/database-schema.md` — add `is_admin` to users table
- Modify: `docs/test-report.md` — update test count, known limitations, next steps
- Modify: `docs/v1-plan.md` — update key decisions re admin auth
- Create: `README.md` (root) — quick-start overview

**Interfaces:**
- All docs reflect the state of the app after Tasks 1-9

- [ ] **Step 1: Update docs/api-contract.md**

In the `current-round` endpoint section, replace the response example with:

```json
// Response 200 — round available
{
  "current_round": { "id": 5, "round_number": 2, "status": "open", "challenge": { "id": 3, "title": "Corner Kick", "difficulty": "easy", "hidden_image_url": "http://..." } },
  "has_current_round": true,
  "completed": false,
  "reason": "has_pending_round",
  "progress": { "completed": 1, "total": 3, "remaining": 2, "pct": 33 }
}

// Response 200 — all rounds done
{
  "current_round": null,
  "has_current_round": false,
  "completed": true,
  "reason": "all_rounds_complete",
  "progress": { "completed": 3, "total": 3, "remaining": 0, "pct": 100 }
}
```

In the leaderboard section, update the entry example to include `avg_score` and `is_current_user`:

```json
{ "rank": 1, "user_id": 1, "username": "xander", "name": "Xander", "total_score": 250, "guesses_count": 3, "avg_score": 83.3, "is_current_user": true }
```

In the Admin section, update description to "(Blade, session auth in v1)" and note that `/admin/login` is the entry point (no auth token needed for that route).

- [ ] **Step 2: Update docs/database-schema.md**

In the `## users` table, add `is_admin` row:

```
| is_admin | boolean | default false — true grants admin Blade access |
```

- [ ] **Step 3: Update docs/test-report.md**

- Update backend test count from 11 to 14 (add 3 AdminTest entries)
- Update the admin known limitation: was "The `/admin` area is publicly accessible." → now "The `/admin` area requires session login as `admin@ballspot.local / password`."
- Remove the click-to-set limitation (now implemented)
- Add new known limitation: "SVG images may not render on all React Native Image versions — if blank, replace with JPEG/PNG via the admin upload form."

- [ ] **Step 4: Update docs/v1-plan.md**

Under "Key Decisions", update: "Admin area is unauthenticated in v1" → "Admin area uses Laravel session auth (not Sanctum); login at `/admin/login`."

- [ ] **Step 5: Create root README.md**

Create `README.md` at repo root:

```markdown
# BallSpot

A social football guessing game. Spot the hidden ball. Beat your friends.

## What it is

BallSpot shows football images with the ball hidden. Players tap where they think the ball is and earn points based on accuracy. Leagues, leaderboards, and friends.

## Structure

```
backend/   Laravel 12 REST API + Blade admin area
mobile/    Expo 56 React Native (iOS + Android)
docs/      API contract, database schema, test report
```

## Quick Start

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve
# → http://127.0.0.1:8000
# Admin: http://127.0.0.1:8000/admin/login
# Admin credentials: admin@ballspot.local / password
```

### Mobile

```bash
cd mobile
npm install
cp .env.example .env       # edit EXPO_PUBLIC_API_BASE_URL if needed
npx expo start
# Press: a=Android  i=iOS  w=Web
```

**Physical device:** set `EXPO_PUBLIC_API_BASE_URL=http://<your-LAN-IP>:8000/api` in `mobile/.env` and start backend with `php artisan serve --host=0.0.0.0`.

## Tests

```bash
cd backend && php artisan test          # 14 backend feature tests
cd mobile && npx tsc --noEmit          # 0 TypeScript errors
```

## Docs

- [API Contract](docs/api-contract.md)
- [Database Schema](docs/database-schema.md)
- [Test Report](docs/test-report.md)

## Constraints

- No real money, no gambling, no subscriptions, no ads
- No chat, no AI, no realtime/websockets
- Football only (v1)
- Score calculation is backend-only
- Positions stored as ratios 0..1 (device-independent)
```

- [ ] **Step 6: Commit**

```bash
git add docs/api-contract.md \
        docs/database-schema.md \
        docs/test-report.md \
        docs/v1-plan.md \
        README.md
git commit -m "docs: update all docs and add root README for polish sprint"
```
