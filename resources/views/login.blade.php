<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b0c0e" media="(prefers-color-scheme: dark)">
    <meta name="theme-color" content="#f4f1ea" media="(prefers-color-scheme: light)">
    <title>AGENT // HOST — Sign in</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400..700&family=IBM+Plex+Mono:wght@400;500&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0b0c0e;
            --surface: #111318;
            --border: #232730;
            --border-strong: #383e4c;
            --text: #f3f1ec;
            --muted: #8b8f99;
            --claude: #ff5722;
            --danger: #ff3366;
        }
        @media (prefers-color-scheme: light) {
            :root {
                --bg: #f3efe6;
                --surface: #faf7f0;
                --border: #d8d0bf;
                --border-strong: #b9ae98;
                --text: #15171c;
                --muted: #5f6470;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 16px;
            background: var(--bg);
            color: var(--text);
            font-family: "DM Sans", sans-serif;
        }
        form {
            width: 100%;
            max-width: 360px;
            padding: 28px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
        }
        h1 {
            margin: 0 0 4px;
            font-family: "Instrument Serif", Georgia, serif;
            font-weight: 400;
            font-size: 34px;
        }
        h1 em { color: var(--claude); }
        p { margin: 0 0 22px; color: var(--muted); font-size: 14px; }
        label { display: block; margin-bottom: 6px; font-family: "IBM Plex Mono", monospace; font-size: 12px; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
        input {
            width: 100%;
            padding: 11px 12px;
            background: var(--bg);
            color: var(--text);
            border: 1px solid var(--border-strong);
            border-radius: 8px;
            font: inherit;
        }
        input:focus { outline: 2px solid var(--claude); outline-offset: 1px; }
        .error { margin-top: 8px; color: var(--danger); font-size: 13px; }
        button {
            width: 100%;
            margin-top: 18px;
            padding: 11px;
            border: 0;
            border-radius: 8px;
            background: var(--claude);
            color: #fff;
            font: 600 14px "DM Sans", sans-serif;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <form method="post" action="{{ url('login') }}">
        @csrf
        <h1>Agent <em>Host</em></h1>
        <p>Sign in to the command deck.</p>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
        @error('password')
            <div class="error">{{ $message }}</div>
        @enderror
        <button type="submit">Sign in</button>
    </form>
</body>
</html>
