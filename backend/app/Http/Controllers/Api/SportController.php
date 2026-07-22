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
     * Returns selectable sports/categories for onboarding and settings.
     * By default only active sports are returned (football ships active;
     * others are activated by an admin once content exists). Pass
     * ?include_inactive=1 to also list inactive sports (used by UIs that
     * want to show "Coming soon" cards).
     */
    public function index(Request $request)
    {
        $query = Sport::query()->orderBy('sort_order')->orderBy('name');

        if (!$request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return SportResource::collection($query->get());
    }
}
