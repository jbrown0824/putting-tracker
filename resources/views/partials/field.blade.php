@php
    /**
     * A labelled text input that refills from old input and shows its own error.
     *
     * @var string $name
     * @var string $label
     * @var string|null $type
     * @var mixed $value
     */
    $type ??= 'text';
    $value = $type === 'password' ? null : old($name, $value ?? null);
@endphp

<div>
    <label for="field-{{ $name }}" class="text-[11px] uppercase tracking-wide text-slate-500">{{ $label }}</label>
    <input id="field-{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $value }}"
           @if (! empty($autocomplete)) autocomplete="{{ $autocomplete }}" @endif
           @if (! empty($autofocus)) autofocus @endif
           @if (! empty($placeholder)) placeholder="{{ $placeholder }}" @endif
           @if (! empty($step)) step="{{ $step }}" inputmode="decimal" @endif
           class="mt-1 w-full rounded-lg border bg-slate-900 px-3 py-2.5 text-base text-slate-100 placeholder:text-slate-600 focus:outline-none {{ $errors->has($name) ? 'border-rose-500/70' : 'border-slate-700 focus:border-slate-500' }}">
    @error($name)
        <p class="mt-1 text-xs text-rose-400">{{ $message }}</p>
    @enderror
</div>
