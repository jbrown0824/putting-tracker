@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <h1 class="pt-2 text-lg font-medium">Settings</h1>

    @if (session('status'))
        <p class="mt-2 rounded bg-emerald-500/10 px-3 py-2 text-xs text-emerald-300">{{ session('status') }}</p>
    @endif

    <section class="mt-4">
        <div class="flex items-baseline justify-between">
            <h2 class="text-sm font-medium text-slate-300">Your putters</h2>
            <a href="{{ route('putters.create') }}" class="text-xs text-emerald-400">+ Add putter</a>
        </div>

        <div class="mt-2 divide-y divide-slate-800 rounded-lg bg-slate-900">
            @forelse ($activePutters as $putter)
                <a href="{{ route('putters.edit', $putter) }}" class="flex items-center justify-between px-3 py-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 text-sm text-slate-100">
                            <span class="truncate">{{ $putter->name }}</span>
                            @if ($putter->is_default)
                                <span class="rounded bg-emerald-500/15 px-1.5 py-0.5 text-[10px] text-emerald-300">Default</span>
                            @endif
                        </div>
                        <div class="mt-0.5 text-[11px] text-slate-500">
                            {{ $putter->description() }} · {{ number_format($putter->putts_count) }} putts
                        </div>
                    </div>
                    <span class="text-slate-600">›</span>
                </a>
            @empty
                <p class="px-3 py-3 text-sm text-slate-400">No putters yet. Add the ones in your bag.</p>
            @endforelse
        </div>

        @if ($retiredPutters->isNotEmpty())
            <h3 class="mt-4 text-[11px] uppercase tracking-wide text-slate-500">Retired</h3>
            <div class="mt-1 divide-y divide-slate-800 rounded-lg bg-slate-900/60">
                @foreach ($retiredPutters as $putter)
                    <a href="{{ route('putters.edit', $putter) }}" class="flex items-center justify-between px-3 py-2.5">
                        <div class="text-sm text-slate-400">
                            {{ $putter->name }}
                            <span class="text-[11px] text-slate-600">· {{ number_format($putter->putts_count) }} putts</span>
                        </div>
                        <span class="text-slate-700">›</span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    <section class="mt-8">
        <h2 class="text-sm font-medium text-slate-300">Account</h2>

        <form method="POST" action="{{ route('settings.account') }}" class="mt-2 space-y-3 rounded-lg bg-slate-900 p-3">
            @csrf
            @method('PATCH')

            <div class="text-[11px] text-slate-500">{{ $user->email }}</div>

            @include('partials.field', ['name' => 'name', 'label' => 'Name', 'value' => $user->name])

            <div x-data>
                <label for="field-timezone" class="text-[11px] uppercase tracking-wide text-slate-500">Time zone</label>
                <select id="field-timezone" name="timezone"
                        class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2.5 text-base text-slate-100 focus:outline-none">
                    @foreach (\DateTimeZone::listIdentifiers() as $zone)
                        <option value="{{ $zone }}" @selected(old('timezone', $user->timezone) === $zone)>{{ str_replace('_', ' ', $zone) }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-[10px] text-slate-600">Decides where your practice day starts and ends for daily goals.</p>
                @error('timezone')
                    <p class="mt-1 text-xs text-rose-400">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="w-full rounded-lg border border-slate-700 py-2 text-sm text-slate-300">Save</button>
        </form>

        {{-- Pages are cached for offline use, so clear them before the next person signs in. --}}
        <form method="POST" action="{{ route('logout') }}" class="mt-4 pb-4" x-data
              @submit="window.caches?.keys().then((keys) => keys.forEach((key) => caches.delete(key)))">
            @csrf
            <button type="submit" class="w-full rounded-lg py-2 text-sm text-rose-400">Log out</button>
        </form>
    </section>
@endsection
