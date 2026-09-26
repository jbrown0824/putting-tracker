<?php

namespace App\Http\Controllers;

use App\Services\PracticeSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function index(Request $request, PracticeSummary $summary): View
    {
        $user = $request->user();

        return view('log', [
            'user' => $user,
            'putters' => $user->putters()->active()->ordered()->get(),
            'defaultPutter' => $user->defaultPutter(),
            'progress' => $summary->for($user),
        ]);
    }
}
