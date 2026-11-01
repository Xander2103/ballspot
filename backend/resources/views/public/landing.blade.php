@extends('public.marketing')

@section('styles')
        .hero {
            position: relative;
            background: linear-gradient(90deg, rgba(7,17,31,.96) 0%, rgba(7,17,31,.82) 50%, rgba(7,17,31,.25) 100%), url('/images/hero-stadium.jpg') 72% center / cover no-repeat;
            border-bottom: 1px solid var(--border);
        }
        .hero-inner { max-width: 1040px; margin: 0 auto; padding: 56px 16px 64px; }
        .hero h1 { margin-top: 32px; max-width: 620px; }
        .hero p { margin: 18px 0 30px; max-width: 540px; font-size: 1.1rem; color: #dbe4ee; }
        .features { max-width: 1040px; margin: 0 auto; padding: 48px 16px 56px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; list-style: none; }
        .feature { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
        .feature img { width: 100%; height: 150px; object-fit: cover; }
        .feature div { padding: 16px 18px 20px; }
        .feature h2 { font-size: 1.1rem; line-height: 1.3; }
        .feature p { margin-top: 4px; font-size: .94rem; color: var(--text-secondary); }
        @media (max-width: 760px) {
            .hero { background: linear-gradient(180deg, rgba(7,17,31,.9) 0%, rgba(7,17,31,.86) 100%), url('/images/hero-stadium.jpg') 72% center / cover no-repeat; }
            .hero-inner { padding: 40px 16px 44px; }
            .features { grid-template-columns: 1fr; padding-top: 32px; padding-bottom: 40px; }
            .store { flex: 1 1 100%; }
        }
@endsection

@section('content')
<section class="hero">
    <div class="hero-inner">
        <img class="wordmark" src="/images/wordmark.png" width="760" height="164" alt="BallPicker">
        <h1>{{ __('web.landing.headline') }}</h1>
        <p>{{ __('web.landing.intro') }}</p>
        @include('public.partials.store-buttons')
    </div>
</section>
<ul class="features" aria-label="{{ __('web.landing.features_label') }}">
    <li class="feature">
        <img src="/images/feature-daily.jpg" width="720" height="201" alt="" loading="lazy">
        <div><h2>{{ __('web.landing.daily_title') }}</h2><p>{{ __('web.landing.daily_body') }}</p></div>
    </li>
    <li class="feature">
        <img src="/images/feature-packs.jpg" width="720" height="392" alt="" loading="lazy">
        <div><h2>{{ __('web.landing.packs_title') }}</h2><p>{{ __('web.landing.packs_body') }}</p></div>
    </li>
    <li class="feature">
        <img src="/images/feature-tournaments.jpg" width="720" height="384" alt="" loading="lazy">
        <div><h2>{{ __('web.landing.tournaments_title') }}</h2><p>{{ __('web.landing.tournaments_body') }}</p></div>
    </li>
</ul>
@endsection
