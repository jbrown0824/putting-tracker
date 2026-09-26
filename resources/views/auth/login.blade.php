@extends('layouts.app')

@section('title', 'Log in')

@section('content')
    <div class="py-10">
        <a href="{{ route('home') }}" class="text-[11px] uppercase tracking-[0.2em] text-emerald-500">Putting Tracker</a>
        <h1 class="mt-3 text-2xl font-medium">Log in</h1>

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf

            @include('partials.field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'autocomplete' => 'username', 'autofocus' => true])
            @include('partials.field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'autocomplete' => 'current-password'])

            <label class="flex items-center gap-2 text-sm text-slate-400">
                <input type="checkbox" name="remember" value="1" checked class="size-4 rounded border-slate-700 bg-slate-900 accent-emerald-500">
                Keep me logged in
            </label>

            <button type="submit" class="w-full rounded-lg bg-emerald-500 py-3 text-sm font-medium text-slate-950">Log in</button>
        </form>

        <p class="mt-6 text-center text-sm text-slate-500">
            New here? <a href="{{ route('register') }}" class="text-emerald-400">Create an account</a>
        </p>
    </div>
@endsection
