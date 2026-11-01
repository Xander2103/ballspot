@extends('public.marketing', ['pagePath' => '/download'])

@section('styles')
        .card { max-width: 480px; margin: 0 auto; padding: 56px 16px; text-align: center; }
        .card .wordmark { margin: 0 auto 32px; }
        .card h1 { font-size: clamp(1.8rem, 7vw, 2.4rem); }
        .card p { margin: 12px 0 28px; color: var(--text-secondary); }
        .card .stores { justify-content: center; }
        .card .store { flex: 1 1 200px; justify-content: flex-start; text-align: left; }
@endsection

@section('content')
<section class="card">
    <a href="/"><img class="wordmark" src="/images/wordmark.png" width="760" height="164" alt="BallPicker"></a>
    <h1>{{ __('web.landing.download_heading') }}</h1>
    <p>{{ __('web.landing.download_intro') }}</p>
    @include('public.partials.store-buttons')
</section>
@endsection

@section('scripts')
{{-- iPadOS Safari reports itself as a Mac, so the server cannot recognise it. --}}
<script>
    if (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1) {
        location.replace(@json($stores['ios']));
    }
</script>
@endsection
