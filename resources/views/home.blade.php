@extends('layouts.app')

@section('title', 'Putting Tracker')

@section('content')
    <div class="flex min-h-[80vh] flex-col justify-center py-10">
        <div class="text-[11px] uppercase tracking-[0.2em] text-emerald-500">Putting Tracker</div>
        <h1 class="mt-3 text-3xl font-medium leading-tight text-slate-50">Every putt counted. Every miss explained.</h1>
        <p class="mt-4 text-sm leading-relaxed text-slate-400">
            Log each putt with one tap on your phone, even with no signal. See whether you miss on
            speed or on line, which putter actually holds up on real greens, and where around the
            hole you lose strokes. Then set your own challenges and drills to practise against.
        </p>

        <ul class="mt-6 space-y-3 text-sm text-slate-300">
            @foreach ([
                ['One-tap logging', 'Tap where the ball finished — short, long, left, right or in. Works offline and syncs later.'],
                ['Your whole bag', 'Track every putter you own and compare any two, adjusted so neither gets credit for easier putts.'],
                ['Challenges and drills', 'Volume goals, daily targets and guided ladder drills, stacked however you like.'],
                ['Inside and out', 'A mat in the basement and a real green are tracked separately, so the carpet never flatters you.'],
            ] as [$headline, $detail])
                <li class="rounded-lg bg-slate-900 p-3">
                    <div class="font-medium text-slate-100">{{ $headline }}</div>
                    <div class="mt-0.5 text-xs leading-relaxed text-slate-400">{{ $detail }}</div>
                </li>
            @endforeach
        </ul>

        <div class="mt-8 grid grid-cols-2 gap-2">
            <a href="{{ route('register') }}" class="rounded-lg bg-emerald-500 py-3 text-center text-sm font-medium text-slate-950">Create account</a>
            <a href="{{ route('login') }}" class="rounded-lg border border-slate-700 py-3 text-center text-sm font-medium text-slate-300">Log in</a>
        </div>
    </div>
@endsection
