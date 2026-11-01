@php
    // Absolute URLs from the configured site address: crawlers need them, and
    // they must not depend on the Host header of the request.
    $site        = rtrim((string) config('ballspot.web_url'), '/');
    $pageUrl     = $site.($pagePath ?? '');
    $ogImage     = $site.'/images/og-image.jpg';
    $title       = __('web.landing.meta_title');
    $description = __('web.landing.meta_description');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $pageUrl }}">
    <meta name="theme-color" content="#07111f">
    <link rel="icon" type="image/png" sizes="96x96" href="/images/favicon-96x96.png">
    <link rel="apple-touch-icon" href="/images/apple-touch-icon.png">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="BallPicker">
    <meta property="og:url" content="{{ $pageUrl }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:secure_url" content="{{ $ogImage }}">
    <meta property="og:image:type" content="image/jpeg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ __('web.landing.headline') }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $ogImage }}">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg: #07111f;
            --surface: #101e33;
            --border: #213450;
            --text: #ffffff;
            --text-secondary: #a9bacd;
            --accent: #c8e614;
        }
        html { -webkit-text-size-adjust: 100%; }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.5;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
        }
        main { flex: 1; }
        a { color: inherit; }
        a:focus-visible { outline: 3px solid var(--accent); outline-offset: 3px; }
        img { display: block; max-width: 100%; }
        .wordmark { width: min(340px, 82vw); height: auto; }
        h1 { font-size: clamp(2rem, 7vw, 3.25rem); line-height: 1.08; font-weight: 800; letter-spacing: -0.02em; text-wrap: balance; }

        .stores { display: flex; flex-wrap: wrap; gap: 12px; }
        .store {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            min-height: 60px;
            min-width: 200px;
            padding: 8px 20px 8px 16px;
            background: #000;
            border: 1px solid #5d6b7c;
            border-radius: 12px;
            color: #fff;
            text-decoration: none;
            transition: border-color .15s, transform .15s;
        }
        .store:hover { border-color: var(--accent); transform: translateY(-1px); }
        .store svg { flex: none; width: 30px; height: 30px; }
        .store small { display: block; font-size: .72rem; line-height: 1.2; color: #d5dde6; }
        .store strong { display: block; font-size: 1.25rem; line-height: 1.2; font-weight: 600; white-space: nowrap; }

        footer { padding: 28px 16px 32px; border-top: 1px solid var(--border); text-align: center; color: var(--text-secondary); font-size: .82rem; }
        footer nav { display: flex; justify-content: center; flex-wrap: wrap; gap: 4px 20px; margin-bottom: 8px; }
        footer a { display: inline-block; padding: 6px 0; }
        @yield('styles')
        @media (prefers-reduced-motion: reduce) { .store { transition: none; } .store:hover { transform: none; } }
    </style>
</head>
<body>
<main>
    @yield('content')
</main>
<footer>
    <nav>
        <a href="/privacy">{{ __('web.nav.privacy') }}</a>
        <a href="/terms">{{ __('web.nav.terms') }}</a>
        <a href="/support">{{ __('web.nav.support') }}</a>
    </nav>
    <p>{{ __('web.footer.made_by') }}</p>
</footer>
@yield('scripts')
</body>
</html>
