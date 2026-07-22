<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Exposes the configured rank ladder (config('ballspot.ranks')) so the mobile
 * "All ranks" overview shares a single source of truth with backend progression
 * (PlayerRankService) rather than duplicating thresholds in the app.
 */
class RankController extends Controller
{
    // GET /api/ranks — every rank in ascending XP order.
    public function index(): JsonResponse
    {
        $ranks = collect(config('ballspot.ranks', []))
            ->map(fn (array $rank) => [
                'name'   => $rank['name'],
                'level'  => (int) $rank['level'],
                'min_xp' => (int) $rank['min_xp'],
            ])
            ->sortBy('min_xp')
            ->values();

        return response()->json(['data' => $ranks]);
    }
}
