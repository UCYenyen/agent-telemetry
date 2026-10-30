<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b0c0e" media="(prefers-color-scheme: dark)">
    <meta name="theme-color" content="#f4f1ea" media="(prefers-color-scheme: light)">
    <title>AGENT // HOST — Sign in or Create Account</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400..700&family=IBM+Plex+Mono:wght@400;500&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #080a0f;
            --surface: #11141d;
            --surface-alt: #171b26;
            --border: #232836;
            --border-strong: #384056;
            --text: #f3f1ec;
            --muted: #8b92a5;
            --claude: #ff5722;
            --emerald: #10b981;
            --danger: #ff3366;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 20px;
            background: radial-gradient(circle at top, rgba(255, 87, 34, 0.1), transparent 55%), var(--bg);
            color: var(--text);
            font-family: "DM Sans", sans-serif;
        }
        .auth-card {
            width: 100%;
            max-width: 430px;
            padding: 30px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.55);
        }
        .kicker {
            font-family: "IBM Plex Mono", monospace;
            font-size: 11px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--emerald);
            margin-bottom: 6px;
        }
        h1 {
            margin: 0 0 4px;
            font-family: "Instrument Serif", Georgia, serif;
            font-weight: 400;
            font-size: 36px;
        }
        h1 em { color: var(--claude); }
        p.subtitle { margin: 0 0 20px; color: var(--muted); font-size: 14px; line-height: 1.45; }
        .tab-switcher {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            padding: 4px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            margin-bottom: 22px;
        }
        .tab-btn {
            margin: 0;
            padding: 9px 12px;
            border: 0;
            border-radius: 7px;
            background: transparent;
            color: var(--muted);
            font: 600 13px "DM Sans", sans-serif;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .tab-btn.active {
            background: var(--surface-alt);
            color: var(--text);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
        }
        .field { margin-bottom: 14px; }
        label {
            display: block;
            margin-bottom: 6px;
            font-family: "IBM Plex Mono", monospace;
            font-size: 11px;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--muted);
        }
        input {
            width: 100%;
            padding: 11px 12px;
            background: var(--bg);
            color: var(--text);
            border: 1px solid var(--border-strong);
            border-radius: 8px;
            font: inherit;
            font-size: 14px;
        }
        input:focus { outline: 2px solid var(--claude); outline-offset: 1px; }
        .error { margin-top: 6px; color: var(--danger); font-size: 12.5px; }
        button.submit-btn {
            width: 100%;
            margin-top: 8px;
            padding: 12px;
            border: 0;
            border-radius: 9px;
            background: var(--claude);
            color: #fff;
            font: 600 14px "DM Sans", sans-serif;
            cursor: pointer;
        }
        button.submit-btn.signup {
            background: var(--emerald);
            color: #04130d;
        }
        .pane { display: none; }
        .pane.active { display: block; }
    </style>
</head>
<body>
    @php
        $initialTab = ($tab ?? 'signin') === 'signup' || $errors->has('name') || $errors->has('email') || $errors->has('phone') ? 'signup' : 'signin';
    @endphp
    <div class="auth-card">
        <div class="kicker">MULTI-AGENT TELEMETRY & CONCORD HUB</div>
        <h1>Agent <em>Host</em></h1>
        <p class="subtitle">Sign in with your username or email, or create a new account to join the multi-agent group chat & shared memory hub.</p>

        <div class="tab-switcher">
            <button type="button" id="tab-signin-btn" class="tab-btn {{ $initialTab === 'signin' ? 'active' : '' }}" onclick="switchTab('signin')">
                Sign In
            </button>
            <button type="button" id="tab-signup-btn" class="tab-btn {{ $initialTab === 'signup' ? 'active' : '' }}" onclick="switchTab('signup')">
                Sign Up
            </button>
        </div>

        <!-- Sign In Form -->
        <form id="pane-signin" class="pane {{ $initialTab === 'signin' ? 'active' : '' }}" method="post" action="{{ url('login') }}">
            @csrf
            <div class="field">
                <label for="login">Username or Email</label>
                <input id="login" name="login" type="text" value="{{ old('login') }}" placeholder="username or you@example.com" autocomplete="username" autofocus>
                @error('login')
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" placeholder="••••••••" autocomplete="current-password" required>
                @error('password')
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>

            <button type="submit" class="submit-btn">Sign in to Command Deck →</button>
        </form>

        <!-- Sign Up Form -->
        <form id="pane-signup" class="pane {{ $initialTab === 'signup' ? 'active' : '' }}" method="post" action="{{ url('register') }}">
            @csrf
            <div class="field">
                <label for="reg-name">Name / Username</label>
                <input id="reg-name" name="name" type="text" value="{{ old('name') }}" placeholder="e.g. bryan or Bryan Kun" autocomplete="name" required>
                @error('name')
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>

            <div class="field">
                <label for="reg-email">Email Address</label>
                <input id="reg-email" name="email" type="email" value="{{ old('email') }}" placeholder="you@example.com" autocomplete="email" required>
                @error('email')
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>

            <div class="field">
                <label for="reg-phone">Phone Number</label>
                <input id="reg-phone" name="phone" type="tel" value="{{ old('phone') }}" placeholder="+62 812 3456 7890" autocomplete="tel" required>
                @error('phone')
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>

            <div class="field">
                <label for="reg-password">Password</label>
                <input id="reg-password" name="password" type="password" placeholder="At least 6 characters" autocomplete="new-password" minlength="6" required>
            </div>

            <button type="submit" class="submit-btn signup">Create Account & Enter Deck →</button>
        </form>
    </div>

    <script>
        function switchTab(mode) {
            document.getElementById('pane-signin').classList.toggle('active', mode === 'signin');
            document.getElementById('pane-signup').classList.toggle('active', mode === 'signup');
            document.getElementById('tab-signin-btn').classList.toggle('active', mode === 'signin');
            document.getElementById('tab-signup-btn').classList.toggle('active', mode === 'signup');
        }
    </script>
</body>
</html>
