<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FriendRequest;
use App\Models\Friendship;
use App\Models\User;
use App\Services\PlayerRankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FriendController extends Controller
{
    /** Hard cap on any self-scoped list, mirroring ProfileController. */
    public const MAX_LIST_ROWS = 200;

    /** How long a declined request blocks the same requester from retrying. */
    public const REJECTED_COOLDOWN_DAYS = 30;

    public function __construct(private PlayerRankService $rankService) {}

    // GET /api/me/friend-code
    public function friendCode(Request $request): JsonResponse
    {
        $user = $request->user();

        // Defensive: accounts created before the backfill migration.
        if (empty($user->friend_code)) {
            $user->friend_code = User::generateFriendCode();
            $user->save();
        }

        return response()->json(['friend_code' => $user->friend_code]);
    }

    // GET /api/friends
    public function index(Request $request): JsonResponse
    {
        $friends = $request->user()->friends()
            ->orderBy('username')
            ->limit(self::MAX_LIST_ROWS)
            ->get();

        return response()->json([
            'data' => $friends->map(fn (User $u) => $this->summary($u))->values(),
        ]);
    }

    // GET /api/friends/requests
    public function requests(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $incoming = FriendRequest::where('recipient_id', $userId)
            ->where('status', FriendRequest::STATUS_PENDING)
            ->with('requester')
            ->orderByDesc('created_at')
            ->limit(self::MAX_LIST_ROWS)
            ->get()
            ->map(fn (FriendRequest $r) => $this->requestItem($r, $r->requester));

        $outgoing = FriendRequest::where('requester_id', $userId)
            ->where('status', FriendRequest::STATUS_PENDING)
            ->with('recipient')
            ->orderByDesc('created_at')
            ->limit(self::MAX_LIST_ROWS)
            ->get()
            ->map(fn (FriendRequest $r) => $this->requestItem($r, $r->recipient));

        return response()->json([
            'incoming' => $incoming->values(),
            'outgoing' => $outgoing->values(),
        ]);
    }

    // GET /api/friends/suggestions
    public function suggestions(Request $request, \App\Services\FriendSuggestionService $suggestions): JsonResponse
    {
        // Same public-safe field set as the friends list, plus a reason label.
        $rows = collect($suggestions->forUser($request->user()))
            ->map(fn (array $row) => $this->summary($row['user']) + ['reason' => $row['reason']]);

        return response()->json(['data' => $rows->values()]);
    }

    // POST /api/friends/requests
    public function store(Request $request): JsonResponse
    {
        // Two lookup paths: a shared friend code (manual/QR add) or a user id
        // (friend suggestions). Exactly one of the two must be sent. Both paths
        // refuse anonymized (deleted) accounts.
        $data = $request->validate([
            'friend_code' => ['required_without:user_id', 'prohibits:user_id', 'string', 'min:4', 'max:12'],
            'user_id'     => ['required_without:friend_code', 'integer'],
        ]);

        $me = $request->user();

        if (isset($data['user_id'])) {
            $target = User::whereKey($data['user_id'])->whereNull('anonymized_at')->first();
        } else {
            $code   = strtoupper(trim($data['friend_code']));
            $target = User::where('friend_code', $code)->whereNull('anonymized_at')->first();
        }

        if (!$target) {
            return response()->json(['message' => 'No player found with that friend code.'], 404);
        }
        if ((int) $target->id === (int) $me->id) {
            return response()->json(['message' => 'You cannot add yourself as a friend.'], 422);
        }
        if ($me->isFriendsWith($target)) {
            return response()->json(['message' => 'You are already friends with this player.'], 422);
        }

        $existing = FriendRequest::where('status', FriendRequest::STATUS_PENDING)
            ->where(function ($q) use ($me, $target) {
                $q->where(fn ($w) => $w->where('requester_id', $me->id)->where('recipient_id', $target->id))
                  ->orWhere(fn ($w) => $w->where('requester_id', $target->id)->where('recipient_id', $me->id));
            })
            ->exists();

        if ($existing) {
            return response()->json(['message' => 'There is already a pending request with this player.'], 422);
        }

        // A rejection must stick for a while. Without this the duplicate guard
        // above (pending-only) lets a rejected requester immediately re-open the
        // same row, so someone holding a friend code can re-request forever —
        // there is no block feature to fall back on.
        $rejected = FriendRequest::where('requester_id', $me->id)
            ->where('recipient_id', $target->id)
            ->where('status', FriendRequest::STATUS_REJECTED)
            ->where('updated_at', '>', now()->subDays(self::REJECTED_COOLDOWN_DAYS))
            ->exists();

        if ($rejected) {
            return response()->json([
                'message' => 'This player declined your request. You can try again later.',
            ], 422);
        }

        // updateOrCreate so a previously rejected/cancelled request in the same
        // direction is reopened instead of colliding with the unique index.
        $friendRequest = FriendRequest::updateOrCreate(
            ['requester_id' => $me->id, 'recipient_id' => $target->id],
            ['status' => FriendRequest::STATUS_PENDING],
        );

        return response()->json(['data' => $this->requestItem($friendRequest, $target)], 201);
    }

    // POST /api/friends/requests/{friendRequest}/accept
    public function accept(Request $request, FriendRequest $friendRequest): JsonResponse
    {
        $me = $request->user();

        if ((int) $friendRequest->recipient_id !== (int) $me->id) {
            return response()->json(['message' => 'This request is not addressed to you.'], 403);
        }
        if (!$friendRequest->isPending()) {
            return response()->json(['message' => 'This request is no longer pending.'], 422);
        }

        $requester = $friendRequest->requester;

        DB::transaction(function () use ($friendRequest, $me, $requester) {
            $friendRequest->update(['status' => FriendRequest::STATUS_ACCEPTED]);
            Friendship::firstOrCreate(['user_id' => $me->id,        'friend_id' => $requester->id]);
            Friendship::firstOrCreate(['user_id' => $requester->id, 'friend_id' => $me->id]);
        });

        return response()->json(['data' => $this->summary($requester)]);
    }

    // POST /api/friends/requests/{friendRequest}/reject
    public function reject(Request $request, FriendRequest $friendRequest): JsonResponse
    {
        if ((int) $friendRequest->recipient_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'This request is not addressed to you.'], 403);
        }
        if (!$friendRequest->isPending()) {
            return response()->json(['message' => 'This request is no longer pending.'], 422);
        }

        $friendRequest->update(['status' => FriendRequest::STATUS_REJECTED]);

        return response()->json(['message' => 'Request rejected.']);
    }

    // DELETE /api/friends/{user}
    public function destroy(Request $request, User $user)
    {
        $me = $request->user();

        $deleted = Friendship::where(function ($q) use ($me, $user) {
            $q->where(fn ($w) => $w->where('user_id', $me->id)->where('friend_id', $user->id))
              ->orWhere(fn ($w) => $w->where('user_id', $user->id)->where('friend_id', $me->id));
        })->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'You are not friends with this player.'], 404);
        }

        return response()->noContent();
    }

    /** Public-safe summary of another player. Never includes email/auth data. */
    private function summary(User $user): array
    {
        $rank = $this->rankService->forUser($user);

        return [
            'id'         => $user->id,
            'name'       => $user->name,
            'username'   => $user->username,
            'avatar_url' => $user->avatarUrl(),
            'rank_name'  => $rank['name'],
            'level'      => $rank['level'],
            'total_xp'   => $rank['total_xp'],
        ];
    }

    private function requestItem(FriendRequest $r, User $other): array
    {
        return [
            'id'         => $r->id,
            'status'     => $r->status,
            'created_at' => $r->created_at?->toISOString(),
            'user'       => $this->summary($other),
        ];
    }
}
