<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') — {{ config('app.name') }}</title>
    {{--
        Self-contained CSS on purpose: these pages must render for a user who is mid-way
        through leaving, and on an admin box, without depending on a CDN or a Vite build.
        Palette matches the marketing page (welcome.blade.php).
    --}}
    <style>
        :root {
            --bg: #131313;
            --surface: #1f2020;
            --surface-high: #2a2a2a;
            --line: #3a3a3a;
            --text: #e4e2e1;
            --muted: #a3a6a6;
            --accent: #e9c349;
            --on-accent: #3c2f00;
            --danger: #ffb4ab;
            --danger-bg: #93000a;
            --ok: #86efac;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 2.5rem 1.25rem 5rem;
            background: var(--bg);
            color: var(--text);
            font: 400 15px/1.6 "Hanken Grotesk", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .shell { max-width: @yield('width', '560px'); margin: 0 auto; }
        h1 { font-size: 1.6rem; line-height: 1.25; margin: 0 0 .5rem; }
        h2 { font-size: 1.05rem; margin: 2rem 0 .75rem; }
        p { margin: 0 0 1rem; }
        a { color: var(--accent); }
        .muted { color: var(--muted); }
        .small { font-size: .82rem; }
        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: .5rem;
            padding: 1.5rem;
        }
        a.card { display: block; text-decoration: none; color: inherit; transition: border-color .15s; }
        a.card:hover { border-color: var(--accent); }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
        label { display: block; font-size: .85rem; color: var(--muted); margin-bottom: .35rem; }
        input[type=email], input[type=password], input[type=text], input[type=number], select, textarea {
            width: 100%;
            padding: .6rem .7rem;
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: .25rem;
            color: var(--text);
            font: inherit;
        }
        textarea { resize: vertical; min-height: 5rem; }
        input:focus, select:focus, textarea:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
        .field { margin-bottom: 1.1rem; }
        .field small { display: block; margin-top: .35rem; color: var(--muted); }
        .check { display: flex; gap: .6rem; align-items: flex-start; margin-bottom: 1.25rem; }
        .check input { margin-top: .25rem; flex: none; }
        .check label { color: var(--text); font-size: .9rem; margin: 0; }
        button {
            font: inherit;
            font-weight: 600;
            padding: .55rem 1.1rem;
            border-radius: .25rem;
            border: 1px solid transparent;
            cursor: pointer;
        }
        .btn-primary { background: var(--accent); color: var(--on-accent); }
        .btn-danger { background: var(--danger-bg); color: var(--danger); }
        .btn-quiet { background: transparent; color: var(--text); border-color: var(--line); }
        button:hover { filter: brightness(1.12); }
        .errors, .flash, .note {
            border-radius: .25rem;
            padding: .75rem 1rem;
            margin-bottom: 1.25rem;
            font-size: .88rem;
        }
        .errors { background: rgba(147, 0, 10, .25); border: 1px solid var(--danger-bg); color: var(--danger); }
        .errors ul { margin: 0; padding-left: 1.1rem; }
        .flash { background: rgba(134, 239, 172, .12); border: 1px solid rgba(134, 239, 172, .35); color: var(--ok); }
        .note { background: var(--surface-high); border: 1px solid var(--line); color: var(--muted); }
        table { width: 100%; border-collapse: collapse; font-size: .88rem; }
        th, td { text-align: left; padding: .6rem .7rem; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { color: var(--muted); font-weight: 600; font-size: .78rem; text-transform: uppercase; letter-spacing: .06em; }
        .table-wrap { overflow-x: auto; }
        .tag {
            display: inline-block; padding: .1rem .5rem; border-radius: .75rem;
            font-size: .74rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em;
        }
        .tag-pending, .tag-trialing, .tag-past_due, .tag-incomplete { background: rgba(233, 195, 73, .18); color: var(--accent); }
        .tag-ignored, .tag-incomplete_expired { background: var(--surface-high); color: var(--muted); }
        .tag-deleted, .tag-canceled, .tag-unpaid { background: rgba(147, 0, 10, .3); color: var(--danger); }
        .tag-active { background: rgba(134, 239, 172, .18); color: var(--ok); }
        .topbar {
            display: flex; flex-wrap: wrap; gap: 1rem; align-items: baseline;
            justify-content: space-between; margin-bottom: 1.5rem;
        }
        .tabs { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1rem; }
        .tabs a {
            padding: .3rem .75rem; border-radius: .75rem; text-decoration: none;
            background: var(--surface); border: 1px solid var(--line); color: var(--muted); font-size: .82rem;
        }
        .tabs a[aria-current] { background: var(--accent); border-color: var(--accent); color: var(--on-accent); font-weight: 600; }
        .actions { display: flex; gap: .4rem; }
        .inline-form { display: inline; }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .85em; }
    </style>
</head>
<body>
<div class="shell">
    @yield('content')
</div>
</body>
</html>
