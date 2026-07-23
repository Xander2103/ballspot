<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChallengePackResource;
use App\Models\Challenge;
use App\Models\ChallengePack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChallengePackController extends Controller
{
    // GET /api/packs — active + public packs only. Optional sport_id / sport_slug filter.
    public function index(Request $request): JsonResponse
    {
        $packs = ChallengePack::visibleToUsers()
            ->with('sport')
            ->withCount('challenges')
            ->when($request->sport_id, fn ($q, $v) => $q->where('sport_id', $v))
            ->when($request->sport_slug, fn ($q, $v) => $q->whereHas('sport', fn ($s) => $s->where('slug', $v)))
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Attach the user's latest attempt per pack so cards can show progress
        // (In progress / Completed / n of m). One cheap query, no N+1.
        $attempts = \App\Models\PackAttempt::where('user_id', $request->user()->id)
            ->whereIn('challenge_pack_id', $packs->pluck('id'))
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get()
            ->unique('challenge_pack_id')
            ->keyBy('challenge_pack_id');

        $data = $packs->map(function (ChallengePack $pack) use ($attempts) {
            $row = (new ChallengePackResource($pack))->resolve();
            $attempt = $attempts->get($pack->id);
            $row['progress'] = $attempt ? [
                'status'          => $attempt->status,
                'completed_count' => min($attempt->current_index, $attempt->totalChallenges()),
                'total_challenges' => $attempt->totalChallenges(),
            ] : null;

            return $row;
        })->values();

        return response()->json(['data' => $data]);
    }

    // GET /api/packs/{slug} — pack detail + safe challenge summaries (ready only).
    public function show(string $slug): JsonResponse
    {
        // 404 for draft/hidden/archived packs — never leak them to normal users.
        $pack = ChallengePack::visibleToUsers()
            ->with(['sport', 'challenges' => fn ($q) => $q->with('sport', 'category')])
            ->where('slug', $slug)
            ->firstOrFail();

        $challenges = $pack->challenges
            ->filter(fn (Challenge $c) => $c->isReadyForDaily())
            ->values();

        $payload = (new ChallengePackResource($pack))->resolve();
        // challenge_count should reflect what a user can actually play here.
        $payload['challenge_count'] = $challenges->count();
        $payload['challenges'] = $challenges->map(fn (Challenge $c) => [
            'id'               => $c->id,
            'title'            => $c->title,
            'difficulty'       => $c->difficulty,
            // SECURITY: never expose ball_x_ratio / ball_y_ratio / reveal image here.
            'hidden_image_url' => $c->hidden_image_path ? asset('storage/' . $c->hidden_image_path) : null,
            'sport'            => $c->sport ? [
                'slug'  => $c->sport->slug,
                'name'  => $c->sport->name,
                'emoji' => $c->sport->emoji,
            ] : null,
            'category'         => $c->category ? ['name' => $c->category->name, 'slug' => $c->category->slug] : null,
        ])->values();

        return response()->json(['data' => $payload]);
    }
}
