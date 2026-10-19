<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BadgeResource;
use App\Models\Badge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BadgeController extends Controller
{
    // GET /api/badges — the full catalogue of virtual trophies. Global badges
    // only: club pack trophies (badges.club_id set) are private to the club.
    public function index(): JsonResponse
    {
        $badges = Badge::global()->orderBy('sort_order')->get();

        return response()->json(['data' => BadgeResource::collection($badges)->resolve()]);
    }

    // GET /api/me/badges — every global badge with earned state for the
    // current user (club trophies are shown in the club section, not here).
    public function mine(Request $request): JsonResponse
    {
        $user  = $request->user();
        $earned = $user->badges()->global()->get()->keyBy('id'); // pivot carries earned_at
        $all    = Badge::global()->orderBy('sort_order')->get();

        $badges = $all->map(function (Badge $badge) use ($earned) {
            $mine = $earned->get($badge->id);
            return array_merge((new BadgeResource($badge))->resolve(), [
                'earned'    => $mine !== null,
                'earned_at' => $mine?->pivot?->earned_at,
            ]);
        });

        return response()->json([
            'earned_count' => $earned->count(),
            'total_count'  => $all->count(),
            'badges'       => $badges,
        ]);
    }
}
