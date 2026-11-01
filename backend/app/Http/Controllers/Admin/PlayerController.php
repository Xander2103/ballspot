<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminPlayerService;
use App\Support\AppLog;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Read-only player lookup and player file. Access is admin-only (route
 * middleware) and logged by id and counts. The search text is not logged
 * because it is often someone's name or email.
 */
class PlayerController extends Controller
{
    public function __construct(private AdminPlayerService $players) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'q'      => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:' . implode(',', AdminPlayerService::STATUSES)],
        ]);

        $q      = trim((string) ($data['q'] ?? ''));
        $status = $data['status'] ?? 'all';
        $failed = false;

        try {
            $players = $this->players->search($q, $status)->withQueryString();
        } catch (\Illuminate\Database\QueryException $e) {
            AppLog::error('admin.player_search_failed', ['admin_user_id' => $request->user()->id, 'exception' => class_basename($e)]);
            $players = new LengthAwarePaginator([], 0, AdminPlayerService::PER_PAGE);
            $failed  = true;
        }

        if (!$failed) {
            AppLog::event('admin.player_search', [
                'admin_user_id' => $request->user()->id,
                'q_length'      => mb_strlen($q),
                'status'        => $status,
                'results'       => $players->total(),
            ]);
        }

        return response()
            ->view('admin.players.index', ['players' => $players, 'q' => $q, 'status' => $status, 'failed' => $failed], $failed ? 503 : 200)
            ->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, User $user)
    {
        AppLog::event('admin.player_file_viewed', [
            'admin_user_id'  => $request->user()->id,
            'target_user_id' => $user->id,
        ]);

        return response()
            ->view('admin.players.show', ['player' => $user, 'd' => $this->players->dossier($user)])
            ->header('Cache-Control', 'no-store, private');
    }
}
