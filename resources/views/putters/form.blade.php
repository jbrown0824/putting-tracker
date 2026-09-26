@extends('layouts.app')

@section('title', $putter->exists ? 'Edit putter' : 'Add putter')

@section('content')
    <div class="flex items-center justify-between pt-2">
        <h1 class="text-lg font-medium">{{ $putter->exists ? $putter->name : 'Add putter' }}</h1>
        <a href="{{ route('settings') }}" class="text-xs text-slate-500">Cancel</a>
    </div>

    <form method="POST" action="{{ $putter->exists ? route('putters.update', $putter) : route('putters.store') }}" class="mt-4 space-y-4">
        @csrf
        @if ($putter->exists)
            @method('PUT')
        @endif

        @include('partials.field', ['name' => 'name', 'label' => 'Name', 'value' => $putter->name, 'placeholder' => 'What you call it', 'autofocus' => ! $putter->exists])

        <div>
            <span class="text-[11px] uppercase tracking-wide text-slate-500">Head</span>
            <div class="mt-1 grid grid-cols-3 gap-1.5">
                @foreach (\App\Enums\PutterHeadType::cases() as $type)
                    <label class="cursor-pointer">
                        <input type="radio" name="head_type" value="{{ $type->value }}" class="peer sr-only"
                               @checked(old('head_type', $putter->head_type?->value) === $type->value)>
                        <span class="block rounded-lg border border-slate-700 py-2 text-center text-sm text-slate-400 peer-checked:border-transparent peer-checked:bg-slate-100 peer-checked:text-slate-900">
                            {{ $type->label() }}
                        </span>
                    </label>
                @endforeach
            </div>
            @error('head_type')
                <p class="mt-1 text-xs text-rose-400">{{ $message }}</p>
            @enderror
        </div>

        <div class="grid grid-cols-2 gap-2">
            @include('partials.field', ['name' => 'brand', 'label' => 'Brand', 'value' => $putter->brand, 'placeholder' => 'Optional'])
            @include('partials.field', ['name' => 'model', 'label' => 'Model', 'value' => $putter->model, 'placeholder' => 'Optional'])
        </div>

        @include('partials.field', ['name' => 'length_in', 'label' => 'Length (inches)', 'type' => 'number', 'step' => '0.5', 'value' => $putter->length_in, 'placeholder' => 'Optional'])

        <div>
            <label for="field-notes" class="text-[11px] uppercase tracking-wide text-slate-500">Notes</label>
            <textarea id="field-notes" name="notes" rows="2" placeholder="Optional"
                      class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2.5 text-base text-slate-100 placeholder:text-slate-600 focus:outline-none">{{ old('notes', $putter->notes) }}</textarea>
        </div>

        <div class="space-y-2 rounded-lg bg-slate-900 p-3">
            <input type="hidden" name="is_default" value="0">
            <label class="flex items-center gap-2 text-sm text-slate-300">
                <input type="checkbox" name="is_default" value="1" @checked(old('is_default', $putter->is_default))
                       class="size-4 rounded border-slate-700 bg-slate-900 accent-emerald-500">
                Default putter
            </label>

            @if ($putter->exists)
                <input type="hidden" name="retired" value="0">
                <label class="flex items-center gap-2 text-sm text-slate-300">
                    <input type="checkbox" name="retired" value="1" @checked(old('retired', $putter->isRetired()))
                           class="size-4 rounded border-slate-700 bg-slate-900 accent-emerald-500">
                    Retired
                </label>
                <p class="text-[10px] leading-relaxed text-slate-600">
                    A retired putter drops out of the logger but keeps its history in your stats.
                </p>
            @endif
        </div>

        <button type="submit" class="w-full rounded-lg bg-emerald-500 py-3 text-sm font-medium text-slate-950">
            {{ $putter->exists ? 'Save' : 'Add putter' }}
        </button>
    </form>

    @if ($putter->exists)
        @error('putter')
            <p class="mt-3 rounded bg-rose-500/10 px-3 py-2 text-xs text-rose-300">{{ $message }}</p>
        @enderror

        @if (($puttCount ?? 0) === 0)
            <form method="POST" action="{{ route('putters.destroy', $putter) }}" class="mt-4 pb-4"
                  onsubmit="return confirm('Delete this putter?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="w-full py-2 text-sm text-rose-400">Delete putter</button>
            </form>
        @endif
    @endif
@endsection
