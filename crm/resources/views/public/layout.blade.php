<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · Colombia Birdfair</title>
    <style>
        :root { --bg: #FAF8F4; --card: #FFFFFF; --text: #1C1A17; --muted: #6B655C; --primary: #2F5D2A; --on-primary: #FFFFFF; --border: #E4DED3; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #141311; --card: #1E1C19; --text: #F3EFE8; --muted: #B3AA9C; --primary: #7CC46A; --on-primary: #10200D; --border: #34302A; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--text);
            font: 16px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { width: 100%; max-width: 440px; background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 32px; }
        .brand { color: var(--primary); font-weight: 600; font-size: 14px; margin: 0 0 24px; }
        h1 { font-size: 22px; line-height: 1.3; margin: 0 0 8px; }
        p { color: var(--muted); margin: 0 0 24px; }
        button { width: 100%; min-height: 44px; border: 0; border-radius: 8px; background: var(--primary); color: var(--on-primary); font: inherit; font-weight: 600; cursor: pointer; }
        button:focus-visible { outline: 3px solid var(--primary); outline-offset: 3px; }
        a { color: var(--primary); }
    </style>
    @stack('head')
</head>
<body>
    <main>
        <p class="brand">Colombia Birdfair</p>
        @yield('content')
    </main>
</body>
</html>
