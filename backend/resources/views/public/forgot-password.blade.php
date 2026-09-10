@extends('public.layout')
@section('title', $sent ? __('web.forgot.sent_title') : __('web.forgot.title'))

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
    .status-icon { font-size: 3rem; margin-bottom: 0.5rem; }
</style>

@if($sent)
    <div class="status-icon">📧</div>
    <h1>{{ __('web.forgot.sent_heading') }}</h1>
    <p class="page-meta">{{ __('web.forgot.sent_intro') }}</p>
    <div class="callout">
        <p>{{ __('web.forgot.sent_callout') }}</p>
    </div>
    <a class="btn btn-primary" href="{{ route('password.request', ['lang' => app()->getLocale()]) }}">{{ __('web.forgot.send_another') }}</a>
@else
    <h1>{{ __('web.forgot.heading') }}</h1>
    <p class="page-meta">{{ __('web.forgot.intro') }}</p>
    <div class="form-card">
        <form method="POST" action="{{ route('password.email') }}">
            @csrf
            <input type="hidden" name="lang" value="{{ app()->getLocale() }}">
            <div class="field">
                <label for="email">{{ __('web.forgot.email') }}</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
                @error('email')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <button class="btn btn-primary" type="submit">{{ __('web.forgot.submit') }}</button>
        </form>
    </div>
@endif
@endsection
