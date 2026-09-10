@extends('public.layout')
@section('title', __('web.reset.title'))

@section('content')
<style>
    .form-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem 1.25rem; margin: 1.5rem 0; }
    .field { margin-bottom: 1rem; }
    .field label { display: block; font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.35rem; font-weight: 600; }
    .field input { width: 100%; height: 48px; border-radius: 10px; border: 1px solid var(--border); background: var(--bg); color: var(--text); padding: 0 0.9rem; font-size: 1rem; }
    .field input:focus { outline: 2px solid var(--primary); border-color: var(--primary); }
    .field-error { color: #ff6b6b; font-size: 0.85rem; margin-top: 0.35rem; }
    .btn { display: inline-flex; align-items: center; justify-content: center; width: 100%; height: 52px; border-radius: 12px; border: 0; font-size: 1rem; font-weight: 700; cursor: pointer; text-decoration: none; }
    .btn-primary { background: var(--primary); color: #041017; }
    .btn-secondary { background: var(--bg); color: var(--text); border: 1px solid var(--border); margin-top: 0.75rem; }
    .muted { color: var(--text-secondary); font-size: 0.85rem; margin-top: 1rem; }
</style>

<h1>{{ __('web.reset.heading') }}</h1>
<p class="page-meta">{{ __('web.reset.intro') }}</p>

@if(!$token)
    <div class="callout">
        <p>{{ __('web.reset.needs_link') }}</p>
    </div>
    <a class="btn btn-primary" href="{{ route('password.request', ['lang' => app()->getLocale()]) }}">{{ __('web.reset.request_new') }}</a>
@else
    <div class="form-card">
        <form method="POST" action="{{ route('password.update') }}" autocomplete="off">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="lang" value="{{ app()->getLocale() }}">

            <div class="field">
                <label for="email">{{ __('web.reset.email') }}</label>
                <input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="username" required>
                @error('email')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="field">
                <label for="password">{{ __('web.reset.password') }}</label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
                @error('password')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="field">
                <label for="password_confirmation">{{ __('web.reset.password_confirm') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required>
            </div>

            @error('token')<div class="field-error">{{ $message }}</div>@enderror

            <button class="btn btn-primary" type="submit">{{ __('web.reset.submit') }}</button>
        </form>
    </div>

    @if($deepLink)
        <a class="btn btn-secondary" href="{{ $deepLink }}">{{ __('web.reset.open_in_app') }}</a>
        <p class="muted">{{ __('web.reset.open_in_app_hint') }}</p>
    @endif

    <p class="muted">{{ __('web.reset.link_not_working') }} <a class="inline-link" href="{{ route('password.request', ['lang' => app()->getLocale()]) }}">{{ __('web.reset.request_new') }}</a>.</p>
@endif
@endsection
