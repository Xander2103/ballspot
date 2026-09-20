<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class NoticeController extends Controller
{
    /**
     * GET /api/notices/active?placement=home_daily_card
     *
     * `{ notice: { placement, type, message } | null }`. The message is
     * already in the caller's language: SetLocale resolved preferred_language
     * → Accept-Language → default before this runs, and the model falls back
     * to English / any filled language. Disabled, out-of-window or empty
     * notices are null — never an error, the Home screen must not depend on it.
     */
    public function active(Request $request): JsonResponse
    {
        $placement = (string) $request->query('placement', AppNotice::PLACEMENT_HOME_DAILY_CARD);
        if (!in_array($placement, AppNotice::PLACEMENTS, true)) {
            return response()->json(['notice' => null]);
        }

        return response()->json([
            'notice' => AppNotice::activeFor($placement, App::getLocale()),
        ]);
    }
}
