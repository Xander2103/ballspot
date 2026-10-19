@extends('club-portal.layout', ['guestPage' => true])

@section('title', __('club_portal.verify.title'))

@section('content')
<div class="auth-card">
    <div class="portal-card p-4 shadow-sm">
        <h1 class="h4 mb-2">{{ __('club_portal.verify.heading') }}</h1>
        <p class="text-muted small mb-1">{{ __('club_portal.verify.intro', ['email' => $maskedEmail]) }}</p>
        <p class="text-muted small">{{ __('club_portal.verify.expires_note', ['minutes' => $expiryMinutes]) }}</p>
        <form method="POST" action="{{ route('club-portal.verify.submit') }}" novalidate>
            @csrf
            <div class="mb-3">
                <label for="code" class="form-label">{{ __('club_portal.verify.code_label') }}</label>
                <input id="code" type="text" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="9" required autofocus autocomplete="one-time-code" class="form-control code-input @error('code') is-invalid @enderror">
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary w-100">{{ __('club_portal.verify.submit') }}</button>
        </form>
        <form method="POST" action="{{ route('club-portal.verify.resend') }}" class="mt-3">
            @csrf
            <button type="submit" class="btn btn-link btn-sm p-0" @if($resendIn > 0) disabled @endif>{{ __('club_portal.verify.resend') }}</button>
            @if($resendIn > 0)<div class="small text-muted">{{ __('club_portal.verify.resend_in', ['seconds' => $resendIn]) }}</div>@endif
        </form>
        <form method="POST" action="{{ route('club-portal.logout') }}" class="mt-2">
            @csrf
            <button type="submit" class="btn btn-link btn-sm p-0 text-muted">{{ __('club_portal.verify.other_account') }}</button>
        </form>
    </div>
</div>
@endsection
