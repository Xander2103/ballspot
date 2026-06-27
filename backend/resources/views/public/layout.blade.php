<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') — BallSpot</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg: #0a1628;
            --surface: #132038;
            --border: #1e3050;
            --text: #ffffff;
            --text-secondary: #8ba0b8;
            --primary: #00c853;
            --radius: 12px;
        }
        html { font-size: 16px; -webkit-text-size-adjust: 100%; }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            min-height: 100vh;
        }
        header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 0 1.25rem;
        }
        .header-inner {
            max-width: 720px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 56px;
        }
        .logo {
            font-weight: 800;
            font-size: 1.1rem;
            color: var(--primary);
            text-decoration: none;
            letter-spacing: -0.3px;
        }
        nav a {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.85rem;
            margin-left: 1.25rem;
        }
        nav a:hover { color: var(--text); }
        main {
            max-width: 720px;
            margin: 0 auto;
            padding: 2.5rem 1.25rem 4rem;
        }
        h1 {
            font-size: 1.75rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            line-height: 1.2;
        }
        .page-meta {
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 2rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--border);
        }
        h2 {
            font-size: 1.05rem;
            font-weight: 700;
            margin: 2rem 0 0.6rem;
            color: var(--text);
        }
        p, li {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin-bottom: 0.75rem;
        }
        ul { padding-left: 1.4rem; margin-bottom: 0.75rem; }
        li { margin-bottom: 0.35rem; }
        a.inline-link { color: var(--primary); text-decoration: underline; }
        .callout {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem 1.25rem;
            margin: 1.5rem 0;
        }
        .callout p { margin-bottom: 0; }
        footer {
            text-align: center;
            padding: 2rem 1.25rem;
            color: var(--text-secondary);
            font-size: 0.8rem;
            border-top: 1px solid var(--border);
        }
        footer a { color: var(--text-secondary); }
    </style>
</head>
<body>
<header>
    <div class="header-inner">
        <a class="logo" href="/">BallSpot</a>
        <nav>
            <a href="/privacy">Privacy</a>
            <a href="/terms">Terms</a>
            <a href="/support">Support</a>
        </nav>
    </div>
</header>

<main>
    @yield('content')
</main>

<footer>
    <p>&copy; {{ date('Y') }} BallSpot &nbsp;·&nbsp; <a href="/privacy">Privacy</a> &nbsp;·&nbsp; <a href="/terms">Terms</a> &nbsp;·&nbsp; <a href="/support">Support</a></p>
</footer>
</body>
</html>
