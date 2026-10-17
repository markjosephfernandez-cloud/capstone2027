<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Sign in | Greyhound Hub</title>
    <link href="<?= esc(base_url('assets/vendor/bootstrap/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= esc(base_url('assets/vendor/bootstrap-icons/bootstrap-icons.css')) ?>" rel="stylesheet">
    <style>
        :root { --hub-maroon: #9a1b2f; --hub-maroon-dark: #751321; --hub-navy: #172743; --hub-muted: #65718a; }
        * { box-sizing: border-box; }
        body.hub-login { background: #edf2f9; color: var(--hub-navy); font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; min-height: 100vh; }
        .hub-login-main { align-items: center; display: flex; justify-content: center; min-height: 100vh; padding: 28px; position: relative; }
        .hub-login-main::before { background: linear-gradient(135deg, rgba(13, 35, 71, .76), rgba(117, 19, 33, .38)), url('<?= esc(base_url('assets/img/background-1.jpg')) ?>') center / cover; content: ""; inset: 0; opacity: .18; position: fixed; }
        .login-shell { background: #fff; border-radius: 24px; box-shadow: 0 24px 70px rgba(30, 45, 78, .22); display: grid; grid-template-columns: minmax(320px, .95fr) minmax(360px, .8fr); max-width: 1040px; min-height: 600px; overflow: hidden; position: relative; width: 100%; }
        .login-hero { background: linear-gradient(150deg, rgba(12, 33, 67, .84), rgba(93, 18, 36, .74)), url('<?= esc(base_url('assets/img/background-1.jpg')) ?>') center / cover; color: #fff; display: flex; flex-direction: column; justify-content: space-between; padding: 42px; }
        .brand-mark { align-items: center; display: flex; gap: 13px; }
        .brand-mark img { background: #fff; border-radius: 50%; box-shadow: 0 8px 24px rgba(0, 0, 0, .22); height: 58px; object-fit: cover; width: 58px; }
        .brand-name { font-size: 1.22rem; font-weight: 750; letter-spacing: -.02em; margin: 0; }
        .brand-subtitle { color: rgba(255, 255, 255, .76); font-size: .78rem; letter-spacing: .08em; margin: 2px 0 0; text-transform: uppercase; }
        .hero-copy { max-width: 400px; }
        .hero-kicker { align-items: center; color: #f8d8dd; display: flex; font-size: .8rem; font-weight: 700; gap: 8px; letter-spacing: .1em; margin-bottom: 16px; text-transform: uppercase; }
        .hero-copy h1 { font-size: clamp(2rem, 4vw, 3.2rem); font-weight: 760; letter-spacing: -.055em; line-height: 1.05; margin: 0 0 18px; }
        .hero-copy p { color: rgba(255, 255, 255, .83); font-size: 1rem; line-height: 1.65; margin: 0; }
        .hero-footer { color: rgba(255, 255, 255, .72); font-size: .82rem; }
        .login-panel { align-items: center; display: flex; padding: 48px; }
        .login-form-wrap { margin: 0 auto; max-width: 350px; width: 100%; }
        .mobile-brand { display: none; }
        .login-heading { color: var(--hub-navy); font-size: 1.75rem; font-weight: 750; letter-spacing: -.04em; margin: 0 0 8px; }
        .login-intro { color: var(--hub-muted); font-size: .94rem; line-height: 1.55; margin: 0 0 28px; }
        .login-label { color: #40506e; font-size: .82rem; font-weight: 700; margin-bottom: 8px; }
        .field-wrap { position: relative; }
        .field-icon { color: #8997ae; left: 15px; pointer-events: none; position: absolute; top: 50%; transform: translateY(-50%); }
        .login-field { border: 1px solid #dbe2ee; border-radius: 10px; box-shadow: none; color: var(--hub-navy); height: 50px; padding: 0 44px; }
        .login-field::placeholder { color: #9aa6b8; }
        .login-field:focus { border-color: #8aaee8; box-shadow: 0 0 0 4px rgba(57, 117, 214, .13); }
        .password-toggle { background: transparent; border: 0; color: #71809a; padding: 8px; position: absolute; right: 5px; top: 50%; transform: translateY(-50%); }
        .password-toggle:hover, .password-toggle:focus-visible { color: var(--hub-maroon); }
        .login-submit { background: var(--hub-maroon); border: 1px solid var(--hub-maroon); border-radius: 10px; box-shadow: 0 8px 18px rgba(154, 27, 47, .22); font-weight: 700; height: 50px; transition: background .15s ease, box-shadow .15s ease, transform .15s ease; width: 100%; }
        .login-submit:hover, .login-submit:focus { background: var(--hub-maroon-dark); border-color: var(--hub-maroon-dark); box-shadow: 0 10px 22px rgba(154, 27, 47, .28); transform: translateY(-1px); }
        .security-note { align-items: center; color: var(--hub-muted); display: flex; font-size: .78rem; gap: 7px; justify-content: center; margin: 23px 0 0; }
        .security-note i { color: #29956c; }
        .login-alert { border-radius: 10px; font-size: .88rem; }
        @media (max-width: 800px) { .hub-login-main { padding: 18px; } .login-shell { grid-template-columns: 1fr; max-width: 480px; min-height: 0; } .login-hero { display: none; } .login-panel { padding: 38px 28px; } .mobile-brand { align-items: center; display: flex; gap: 11px; margin-bottom: 30px; } .mobile-brand img { border-radius: 50%; height: 48px; width: 48px; } .mobile-brand strong { display: block; font-size: 1.05rem; } .mobile-brand span { color: var(--hub-muted); font-size: .76rem; } }
        @media (max-width: 380px) { .login-panel { padding: 32px 21px; } .login-heading { font-size: 1.55rem; } }
    </style>
</head>
<body class="hub-login">
<main class="hub-login-main">
    <section class="login-shell" aria-label="Greyhound Hub sign in">
        <aside class="login-hero">
            <div class="brand-mark"><img src="<?= esc(base_url('assets/img/logoo.png')) ?>" alt="Greyhound Hub logo"><div><p class="brand-name">Greyhound Hub</p><p class="brand-subtitle">Foundation University</p></div></div>
            <div class="hero-copy"><div class="hero-kicker"></i> Creative Department</div><h1>Every story, ready when you need it.</h1><p>Securely organise, review, and release your team’s media through one intelligent workspace.</p></div>
            <div class="hero-footer">Greyhound Hub 2.0 &middot; Internal media management</div>
        </aside>
        <section class="login-panel">
            <div class="login-form-wrap">
                <div class="mobile-brand"><img src="<?= esc(base_url('assets/img/logoo.png')) ?>" alt="Greyhound Hub logo"><div><strong>Greyhound Hub</strong><span>Foundation University</span></div></div>
                <h1 class="login-heading">Welcome back</h1>
                <p class="login-intro">Sign in with your Foundation University employee account to continue.</p>
                <?php if (! empty($error)): ?><div class="alert alert-danger login-alert" role="alert" data-auto-dismiss><i class="bi bi-exclamation-circle me-1"></i><?= esc($error) ?></div><?php endif; ?>
                <form action="<?= esc(site_url('login')) ?>" method="post">
                    <?= csrf_field() ?>
                    <div class="mb-3"><label class="login-label" for="yourEmployeeID">Employee ID</label><div class="field-wrap"><i class="bi bi-person-badge field-icon"></i><input type="text" inputmode="numeric" name="employee_id" class="form-control login-field" id="yourEmployeeID" placeholder="Enter your employee ID" autocomplete="username" value="<?= esc((string) ($employee_id ?? '')) ?>" required autofocus></div></div>
                    <div class="mb-4"><label class="login-label" for="yourPassword">Password</label><div class="field-wrap"><i class="bi bi-lock field-icon"></i><input type="password" name="password" class="form-control login-field" id="yourPassword" placeholder="Enter your password" autocomplete="current-password" required><button class="password-toggle" type="button" id="passwordToggle" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye"></i></button></div></div>
                    <button class="btn btn-primary login-submit" type="submit">Sign in <i class="bi bi-arrow-right ms-1"></i></button>
                </form>
                <p class="security-note"><i class="bi bi-shield-check"></i> Secure access for authorized Creative Department staff.</p>
            </div>
        </section>
    </section>
</main>
<script>
document.getElementById('passwordToggle').addEventListener('click', function () {
    var password = document.getElementById('yourPassword');
    var visible = password.type === 'text';
    password.type = visible ? 'password' : 'text';
    this.setAttribute('aria-label', visible ? 'Show password' : 'Hide password');
    this.setAttribute('aria-pressed', visible ? 'false' : 'true');
    this.querySelector('i').className = visible ? 'bi bi-eye' : 'bi bi-eye-slash';
});
</script>
<script src="<?= esc(base_url('assets/js/notifications.js')) ?>"></script>
<script>window.GreyhoundNotifications.init();</script>
</body>
</html>
