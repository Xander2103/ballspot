<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SportResource;
use App\Models\Sport;
use Illuminate\Http\Request;

class SportController extends Controller
{
    /**
     * GET /api/sports
     *
     * Returns sports for app selection: active (selectable) and coming_soon
     * (visible but disabled). Hidden sports are never returned to normal users.
     * No admin-sensitive fields are exposed.
     */
    public function index(Request $request)
    {
        $sports = Sport::query()
            ->visible()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return SportResource::collection($sports);
    }
}
