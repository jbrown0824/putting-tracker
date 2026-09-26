<?php

namespace App\Http\Controllers;

use App\Enums\PutterHeadType;
use App\Http\Requests\SavePutterRequest;
use App\Models\Putter;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PutterController extends Controller
{
    public function create(): View
    {
        return view('putters.form', [
            'putter' => new Putter(['head_type' => PutterHeadType::Blade]),
        ]);
    }

    public function store(SavePutterRequest $request): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($request, $user): void {
            $putter = $user->putters()->create([
                ...$this->attributes($request),
                'sort_order' => (int) $user->putters()->max('sort_order') + 1,
            ]);

            $this->settleDefault($user, $putter);
        });

        return redirect()->route('settings')->with('status', 'Putter added.');
    }

    public function edit(Request $request, Putter $putter): View
    {
        Gate::authorize('update', $putter);

        return view('putters.form', [
            'putter' => $putter,
            'puttCount' => $putter->putts()->count(),
        ]);
    }

    public function update(SavePutterRequest $request, Putter $putter): RedirectResponse
    {
        Gate::authorize('update', $putter);

        DB::transaction(function () use ($request, $putter): void {
            $putter->update($this->attributes($request));

            $this->settleDefault($request->user(), $putter);
        });

        return redirect()->route('settings')->with('status', 'Putter saved.');
    }

    /**
     * Only a putter nothing was ever hit with can be deleted. Anything with history
     * is retired instead, so its putts keep a putter to belong to.
     */
    public function destroy(Request $request, Putter $putter): RedirectResponse
    {
        Gate::authorize('delete', $putter);

        if ($putter->putts()->exists()) {
            return back()->withErrors(['putter' => 'This putter has putts logged with it. Retire it instead to keep that history.']);
        }

        $putter->delete();
        $this->settleDefault($request->user());

        return redirect()->route('settings')->with('status', 'Putter deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(SavePutterRequest $request): array
    {
        $retired = $request->boolean('retired');

        return [
            ...$request->safe()->except(['retired', 'is_default']),
            // A retired putter cannot be the one the logger reaches for.
            'is_default' => $request->boolean('is_default') && ! $retired,
            'retired_at' => $retired ? ($request->route('putter')?->retired_at ?? now()) : null,
        ];
    }

    /**
     * Keep exactly one active default: the one just marked, or else the first
     * active putter when the old default went away.
     */
    private function settleDefault(User $user, ?Putter $changed = null): void
    {
        if ($changed?->is_default) {
            $user->putters()->whereKeyNot($changed->id)->update(['is_default' => false]);

            return;
        }

        if ($user->putters()->active()->where('is_default', true)->exists()) {
            return;
        }

        $user->putters()->active()->ordered()->first()?->update(['is_default' => true]);
    }
}
