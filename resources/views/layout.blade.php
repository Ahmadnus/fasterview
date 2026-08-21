<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Occupancy Tracker') — Faster</title>
    <style>
        :root {
            --bg: #f5f6f8;
            --panel: #fff;
            --ink: #1c2430;
            --muted: #697586;
            --line: #dfe3e8;
            --accent: #14508c;
            --good: #d8f2dc;
            --bad: #fadcdc;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font: 14px/1.5 -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
        }

        header {
            background: var(--accent);
            color: #fff;
            padding: 14px 22px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        header h1 { font-size: 17px; margin: 0; font-weight: 600; }
        header a { color: #cfe0f2; text-decoration: none; }
        header a:hover { color: #fff; }
        header .spacer { margin-left: auto; }

        main { padding: 22px; max-width: 1500px; margin: 0 auto; }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .filters { display: flex; gap: 18px; align-items: flex-end; flex-wrap: wrap; }
        .filters label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 4px; }
        select, input[type=text], input[type=email], input[type=password] {
            padding: 7px 10px;
            border: 1px solid var(--line);
            border-radius: 5px;
            background: #fff;
            font-size: 14px;
            min-width: 190px;
        }

        button, .btn {
            padding: 8px 14px;
            border: 1px solid var(--accent);
            border-radius: 5px;
            background: var(--accent);
            color: #fff;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        button.secondary, .btn.secondary { background: #fff; color: var(--accent); }
        button:hover { opacity: .9; }

        table { border-collapse: collapse; width: 100%; background: #fff; }
        th, td { padding: 8px 10px; border-bottom: 1px solid var(--line); text-align: left; white-space: nowrap; }
        th {
            background: #eef1f4;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .03em;
            cursor: pointer;
            user-select: none;
            position: sticky;
            top: 0;
        }
        th .arrow { color: var(--muted); font-size: 10px; margin-left: 3px; }
        tbody tr:hover { background: #f7f9fb; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }

        /* Occupancy only — views are deliberately left uncoloured, since an
           absolute view count means very different things per category. */
        td.occ-high { background: var(--good); }
        td.occ-low  { background: var(--bad); }

        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .02em;
        }
        .tag-rented { background: #2f9e57; color: #fff; }
        .tag-maint  { background: #d08a1e; color: #fff; }
        .tag-parked { background: #b9c0c8; color: #33383d; }
        .tag-faster { background: #d6e7f7; color: #14508c; border: 1px solid #a9c8e6; }
        .tag-lent   { background: #f0ece2; color: #7a6320; border: 1px solid #ddd2b8; }

        td.plate {
            font-family: Consolas, "Courier New", monospace;
            letter-spacing: .04em;
        }

        .muted { color: var(--muted); }
        .small { font-size: 12px; }
        .flash { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
        .flash.ok { background: #dff3e2; border: 1px solid #a9d9b4; }
        .flash.err { background: #fbe0e0; border: 1px solid #e6a9a9; }
        .table-wrap { overflow-x: auto; max-height: 70vh; overflow-y: auto; }
    </style>
    @stack('head')
</head>
<body>
<header>
    <h1>Faster · Occupancy Tracker</h1>
    <a href="{{ route('occupancy.index') }}">Occupancy</a>
    <div class="spacer"></div>
    @auth
        <span class="small">{{ auth()->user()->name }}</span>
        <form method="POST" action="{{ route('logout') }}" style="margin:0">
            @csrf
            <button class="secondary" style="padding:5px 10px">Log out</button>
        </form>
    @endauth
</header>

<main>
    @if (session('status'))
        <div class="flash ok">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="flash err">{{ session('error') }}</div>
    @endif

    @yield('content')
</main>
@stack('scripts')
</body>
</html>
