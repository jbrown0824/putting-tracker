<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Putts">
    <meta name="theme-color" content="#0f172a">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <title>@yield('title', 'Putting Tracker')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-950 text-slate-100 antialiased select-none">
    <div class="mx-auto flex min-h-full w-full max-w-md flex-col">
        <main class="flex-1 px-4 pt-[max(0.75rem,env(safe-area-inset-top))]">
            @yield('content')
        </main>

        <nav class="sticky bottom-0 mt-4 grid grid-cols-3 border-t border-slate-800 bg-slate-950/95 pb-[max(0.5rem,env(safe-area-inset-bottom))] pt-2 backdrop-blur">
            @php
                $tabs = [
                    ['route' => 'log', 'label' => 'Log'],
                    ['route' => 'stats', 'label' => 'Stats'],
                    ['route' => 'sessions.index', 'label' => 'History'],
                ];
            @endphp
            @foreach ($tabs as $tab)
                <a href="{{ route($tab['route']) }}"
                   class="py-2 text-center text-sm font-medium {{ request()->routeIs($tab['route']) ? 'text-emerald-400' : 'text-slate-500' }}">
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </nav>
    </div>
</body>
</html>
