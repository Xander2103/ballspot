<?php

namespace App\Http\Controllers\Admin\Clubs;

use App\Models\ClubPhotoSubmission;
use Illuminate\Support\Facades\Gate;

/**
 * Global photo review queue (admin.club-photos.index).
 *
 * PLACEHOLDER by B2 (Central Admin) so the Clubs navigation is complete.
 * Owned by D1 (Photos): replace this body and
 * resources/views/admin/clubs/photos/index.blade.php with the review queue
 * (assign / prepare / convert / reject); keep the route name
 * admin.club-photos.index stable. The nav badge counts open submissions
 * (ClubPhotoSubmission::open()) in admin/layout.blade.php.
 */
class ClubPhotoController extends AdminClubController
{
    public function index()
    {
        Gate::authorize('admin.manage');

        return view('admin.clubs.photos.index', [
            'open' => ClubPhotoSubmission::query()->open()->count(),
        ]);
    }
}
