@extends('public.layout')
@section('title', $ok ? __('web.result.ok_title') : ($state === 'failed' ? __('web.result.failed_title') : __('web.result.expired_title')))

@section('content')
<style>
    .btn { display: inline-flex; align-items: center; justify-content: center; width: 100%; height: 52px; border-radius: 12px; border: 0; font-size: 1rem; font-weight: 700; cursor: pointer; text-decoration: none; }
    .btn-primary { background: var(--primary); color: #041017; }
    .btn-secondary { background: var(--bg); color: var(--text); border: 1px solid var(--border); margin-top: 0.75rem; }
    .status-icon { font-size: 3rem; margin-bottom: 0.5rem; }
</style>

@if($ok)
    <div class="status-icon">✅</div>
    <h1>{{ __('web.result.ok_heading') }}</h1>
    <p class="page-meta">{{ __('web.result.ok_intro') }}</p>
    <div class="callout">
        <p>{{ __('web.result.ok_callout') }}</p>
    </div>
@elseif($state === 'failed')
    <div class="status-icon">⚠️</div>
    <h1>{{ __('web.result.failed_heading') }}</h1>
    <p class="page-meta">{{ $message }}</p>
    <div class="callout">
        <p>{{ __('web.result.failed_callout') }}</p>
    </div>
    @if($retryUrl)
        <a class="btn btn-primary" href="{{ $retryUrl }}">{{ __('web.result.try_again') }}</a>
    @endif
    <a class="btn btn-secondary" href="{{ route('password.request', ['lang' => app()->getLocale()]) }}">{{ __('web.result.request_new') }}</a>
@else
    <div class="status-icon">⏰</div>
    <h1>{{ __('web.result.expired_heading') }}</h1>
    <p class="page-meta">{{ $message }}</p>
    <div class="callout">
        <p>{{ __('web.result.expired_callout') }}</p>
    </div>
    <a class="btn btn-primary" href="{{ route('password.request', ['lang' => app()->getLocale()]) }}">{{ __('web.result.request_new') }}</a>
@endif
@endsection
