<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordPutts;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePuttsRequest;
use App\Models\Challenge;
use App\Models\Putt;
use App\Services\PuttStats;
use Illuminate\Http\JsonResponse;

class PuttSyncController extends Controller
{
    public function store(StorePuttsRequest $request, RecordPutts $record, PuttStats $stats): JsonResponse
    {
        $stored = $record->execute($request->validated('putts'));
        $challenge = Challenge::current();

        return response()->json([
            'stored' => $stored,
            'progress' => $challenge !== null ? $stats->progress($challenge) : null,
        ]);
    }

    public function progress(PuttStats $stats): JsonResponse
    {
        $challenge = Challenge::current();

        return response()->json([
            'progress' => $challenge !== null ? $stats->progress($challenge) : null,
        ]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        Putt::query()->where('uuid', $uuid)->delete();

        return response()->json(['deleted' => $uuid]);
    }
}
