{{-- Overall or a single practice session. Navigates on change so the whole page
     re-renders scoped, keeping every section below driven by one source. --}}
<div class="mt-3" x-data>
    <label for="stats-scope" class="sr-only">Stats scope</label>
    <select id="stats-scope" @change="window.location = $event.target.value"
            class="w-full appearance-none rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-200 focus:outline-none">
        <option value="{{ route('stats') }}" @selected($session === null)>Overall</option>

        @foreach ($sessions as $option)
            <option value="{{ route('stats', ['session' => $option->id]) }}" @selected($session?->id === $option->id)>
                {{ $option->started_at->tz(auth()->user()->timezone)->format('M j, g:ia') }} · {{ $option->whereLabel() }} · {{ $option->putter->name }} · {{ $option->putts_count }} putts
            </option>
        @endforeach
    </select>
</div>
