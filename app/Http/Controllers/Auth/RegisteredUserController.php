<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = User::query()->create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'timezone' => $request->validated('timezone') ?? 'UTC',
        ]);

        // Every putt has to be filed against a putter, so start everyone with one
        // they can rename rather than an empty bag the logger cannot work with.
        $user->defaultPutter();

        event(new Registered($user));

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route('settings')->with('status', 'Welcome! Rename your putter or add the rest of your bag, then head to Log.');
    }
}
