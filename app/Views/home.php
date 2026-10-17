<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Greyhound Hub API</title>
    <style>
        :root { color-scheme: dark; font-family: Inter, system-ui, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0d1526; color: #edf2ff; }
        main { width: min(680px, calc(100% - 48px)); padding: clamp(20px, 6vw, 48px); border: 1px solid #31415f; border-radius: 18px; background: #111d35; box-shadow: 0 18px 60px #02071380; overflow-wrap: anywhere; }
        .eyebrow { color: #89adff; font-size: .8rem; font-weight: 700; letter-spacing: .11em; text-transform: uppercase; }
        h1 { margin: .5rem 0 1rem; font-size: clamp(2rem, 7vw, 3.6rem); }
        p { color: #c2cde2; line-height: 1.6; }
        .links { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 28px; }
        a, .secondary { border-radius: 9px; padding: 11px 15px; font-weight: 650; text-decoration: none; }
        .primary { background: #5b8cff; color: white; }
        .secondary { border: 1px solid #53698f; color: #dce6fa; }
        code { background: #202f4c; border-radius: 5px; padding: 2px 5px; }
    </style>
</head>
<body>
<main>
    <div class="eyebrow">CodeIgniter 4</div>
    <h1>Greyhound Hub</h1>
    <p>The backend API is running. Authenticate through <code>POST /api/v1/auth/login</code>, then use a bearer token to manage media, folders, tags, recents, and audit logs.</p>
    <div class="links">
        <a class="primary" href="<?= esc(site_url('api/v1/health')) ?>">Check API health</a>
        <span class="secondary">API guide: <code>docs/api.md</code></span>
    </div>
</main>
</body>
</html>
