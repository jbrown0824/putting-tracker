<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAccountRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(Request $request): View
    {
        $putters = $request->user()->putters()
            ->withCount('putts')
            ->ordered()
            ->get();

        return view('settings.index', [
            'user' => $request->user(),
            'activePutters' => $putters->whereNull('retired_at')->values(),
            'retiredPutters' => $putters->whereNotNull('retired_at')->values(),
        ]);
    }

    public function updateAccount(UpdateAccountRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return redirect()->route('settings')->with('status', 'Account saved.');
    }
}
