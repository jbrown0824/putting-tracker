@extends('layouts.app')

@section('title', 'Create account')

@section('content')
    <div class="py-10">
        <a href="{{ route('home') }}" class="text-[11px] uppercase tracking-[0.2em] text-emerald-500">Putting Tracker</a>
        <h1 class="mt-3 text-2xl font-medium">Create account</h1>

        <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4"
              x-data x-init="$refs.timezone.value = Intl.DateTimeFormat().resolvedOptions().timeZone">
            @csrf
            {{-- Daily goals need to know where your day starts and ends. --}}
            <input type="hidden" name="timezone" x-ref="timezone">

            @include('partials.field', ['name' => 'name', 'label' => 'Name', 'autocomplete' => 'name', 'autofocus' => true])
            @include('partials.field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'autocomplete' => 'username'])
            @include('partials.field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'autocomplete' => 'new-password'])
            @include('partials.field', ['name' => 'password_confirmation', 'label' => 'Confirm password', 'type' => 'password', 'autocomplete' => 'new-password'])

            <button type="submit" class="w-full rounded-lg bg-emerald-500 py-3 text-sm font-medium text-slate-950">Create account</button>
        </form>

        <p class="mt-6 text-center text-sm text-slate-500">
            Already have an account? <a href="{{ route('login') }}" class="text-emerald-400">Log in</a>
        </p>
    </div>
@endsection
