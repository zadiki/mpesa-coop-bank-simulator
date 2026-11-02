<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · M-Pesa Simulator</title>
    @stack('head')
    <style>
        :root {
            --bg: #f6f7f5; --surface: #ffffff; --surface-2: #f1f3ef; --border: #e2e5df;
            --text: #1c211d; --muted: #66706a; --accent: #2f8f3a; --accent-ink: #ffffff; --accent-soft: #e5f3e6;
            --ok: #2f8f3a; --ok-soft: #e5f3e6; --warn: #a86a00; --warn-soft: #fbf0d9;
            --bad: #c0362c; --bad-soft: #fbe5e3; --info: #2e5f9e; --info-soft: #e3ecf8; --idle: #6b716d; --idle-soft: #eceeeb;
            --code-bg: #f1f3ef;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #121513; --surface: #1a1e1b; --surface-2: #222723; --border: #2e342f;
                --text: #e6ebe7; --muted: #98a29b; --accent: #4cb758; --accent-ink: #0c130d; --accent-soft: #1f3322;
                --ok: #5cc468; --ok-soft: #1f3322; --warn: #e0a640; --warn-soft: #362a14;
                --bad: #ef6b60; --bad-soft: #3a1d1a; --info: #78a7e6; --info-soft: #1b2a3d; --idle: #9aa39d; --idle-soft: #262b27;
                --code-bg: #161a17;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        header { background: var(--surface); border-bottom: 1px solid var(--border); }
        .bar { max-width: 1200px; margin: 0 auto; padding: 0 16px; display: flex; align-items: center; gap: 24px; height: 56px; overflow-x: auto; }
        .brand { font-weight: 700; color: var(--text); white-space: nowrap; display: flex; align-items: center; gap: 8px; }
        .brand i { width: 10px; height: 10px; border-radius: 50%; background: var(--accent); display: inline-block; }
        nav { display: flex; gap: 4px; }
        nav a { color: var(--muted); padding: 6px 10px; border-radius: 6px; white-space: nowrap; }
        nav a:hover { text-decoration: none; background: var(--surface-2); color: var(--text); }
        nav a.active { color: var(--text); background: var(--surface-2); font-weight: 600; }
        main { max-width: 1200px; margin: 0 auto; padding: 24px 16px 64px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 0 0 12px; }
        .sub { color: var(--muted); margin: 0 0 20px; }
        .grid { display: grid; gap: 16px; }
        .g2 { grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); }
        .g4 { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 16px; min-width: 0; }
        .stat .n { font-size: 26px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .stat .l { color: var(--muted); font-size: 12px; }
        .stack > * + * { margin-top: 16px; }
        table { width: 100%; border-collapse: collapse; }
        .table-wrap { overflow-x: auto; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--border); white-space: nowrap; vertical-align: top; }
        th { font-size: 12px; color: var(--muted); font-weight: 600; }
        tr:last-child td { border-bottom: 0; }
        td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .mono, code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; }
        pre { background: var(--code-bg); border: 1px solid var(--border); border-radius: 8px; padding: 12px; overflow: auto; max-height: 420px; margin: 0; white-space: pre; }
        code { background: var(--code-bg); padding: 1px 5px; border-radius: 4px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .b-completed, .b-success, .b-2xx, .b-active { color: var(--ok); background: var(--ok-soft); }
        .b-pending { color: var(--warn); background: var(--warn-soft); }
        .b-failed, .b-rejected, .b-err, .b-closed, .b-blocked { color: var(--bad); background: var(--bad-soft); }
        .b-timeout, .b-partial, .b-dormant { color: var(--warn); background: var(--warn-soft); }
        .b-no_callback { color: var(--idle); background: var(--idle-soft); }
        .b-type { color: var(--info); background: var(--info-soft); text-transform: uppercase; }
        form.inline { display: inline; }
        label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 4px; font-weight: 600; }
        input, select { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text); font: inherit; }
        input:focus, select:focus { outline: 2px solid var(--accent); outline-offset: -1px; }
        .fields { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
        .fields .wide { grid-column: 1 / -1; }
        button, .btn { display: inline-block; padding: 8px 14px; border-radius: 6px; border: 1px solid var(--accent); background: var(--accent); color: var(--accent-ink); font: inherit; font-weight: 600; cursor: pointer; }
        button.ghost, .btn.ghost { background: transparent; color: var(--text); border-color: var(--border); }
        button.small { padding: 4px 10px; font-size: 12px; }
        button.danger { background: transparent; color: var(--bad); border-color: var(--border); }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 14px; }
        .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
        .flash.ok { background: var(--ok-soft); color: var(--ok); }
        .flash.error { background: var(--bad-soft); color: var(--bad); }
        .muted { color: var(--muted); }
        .kv { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; }
        .kv dt { color: var(--muted); }
        .kv dd { margin: 0; word-break: break-all; }
        .row-between { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
        .row-between h2 { margin: 0; }
        .empty { color: var(--muted); padding: 20px 0; text-align: center; }
        .pagination { margin-top: 12px; }
        .pagination nav { display: flex; justify-content: space-between; flex-wrap: wrap; }
        .pagination svg { width: 16px; height: 16px; }
        .subnav { display: flex; gap: 4px; margin: -8px 0 20px; flex-wrap: wrap; }
        .subnav a { padding: 5px 12px; border-radius: 999px; border: 1px solid var(--border); color: var(--muted); }
        .subnav a.active { background: var(--accent-soft); color: var(--accent); border-color: transparent; font-weight: 600; }
        .subnav a:hover { text-decoration: none; color: var(--text); }
        td.neg { color: var(--bad); }
        td.pos { color: var(--ok); }
        .reason { color: var(--bad); font-weight: 600; }
        .bal { font-size: 24px; font-weight: 700; font-variant-numeric: tabular-nums; }
        details summary { cursor: pointer; color: var(--muted); margin: 8px 0; }
    </style>
</head>
<body>
<header>
    <div class="bar">
        <a href="{{ route('dashboard') }}" class="brand"><i></i> M-Pesa Simulator</a>
        <nav>
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
            <a href="{{ route('transactions.index') }}" class="{{ request()->routeIs('transactions.*') ? 'active' : '' }}">Transactions</a>
            <a href="{{ route('c2b.index') }}" class="{{ request()->routeIs('c2b.*') ? 'active' : '' }}">C2B</a>
            <a href="{{ route('b2c.index') }}" class="{{ request()->routeIs('b2c.*') ? 'active' : '' }}">B2C</a>
 <a href="{{ route('coop.accounts') }}" class="{{ request()->routeIs('coop.*') ? 'active' : '' }}">Co-op Bank</a>
            <a href="{{ route('settings') }}" class="{{ request()->routeIs('settings*') ? 'active' : '' }}">Settings</a>
        </nav>
    </div>
</header>
<main>
    @if (session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif
    @if (session('error'))<div class="flash error">{{ session('error') }}</div>@endif
    @if ($errors->any())
        <div class="flash error">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif
    @yield('content')
</main>
<script>
    // Live-refresh any element with data-refresh="<url>" while the page is visible.
    document.querySelectorAll('[data-refresh]').forEach(function (el) {
        setInterval(function () {
            if (document.hidden) return;
            fetch(el.dataset.refresh, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.text() : null; })
                .then(function (html) { if (html !== null) el.innerHTML = html; })
                .catch(function () {});
        }, 3000);
    });
</script>
</body>
</html>
