<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordPutts;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePuttsRequest;
use App\Services\PracticeSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PuttSyncController extends Controller
{
    public function store(StorePuttsRequest $request, RecordPutts $record, PracticeSummary $summary): JsonResponse
    {
        $stored = $record->execute($request->user(), $request->validated('putts'));

        return response()->json([
            'stored' => $stored,
            'progress' => $summary->for($request->user()),
        ]);
    }

    public function progress(Request $request, PracticeSummary $summary): JsonResponse
    {
        return response()->json([
            'progress' => $summary->for($request->user()),
        ]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $request->user()->putts()->where('uuid', $uuid)->delete();

        return response()->json(['deleted' => $uuid]);
    }
}
