<?php

namespace App\Http\Controllers\Api\Clubs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clubs\Api\CursorPageRequest;
use App\Http\Requests\Clubs\Api\UploadClubPhotoRequest;
use App\Http\Resources\Clubs\PhotoSubmissionResource;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubPhotoSubmission;
use App\Services\Clubs\ClubAccess;
use App\Services\Clubs\ClubCursor;
use App\Services\Clubs\ClubPhotoReviewService;
use Illuminate\Http\JsonResponse;

/**
 * Club-admin API (Wave F): photo submissions. Upload = the portal's path —
 * ClubPhotoReviewService::submit() with source `club_upload`, the rights
 * confirmation and actor kind `club_admin` — so it lands in BallPicker's
 * review queue (/admin/club-photos). Reviewing, preparing and converting stay
 * platform tasks. Suspended clubs: list only.
 */
class ClubPhotoController extends Controller
{
    public function __construct(private ClubPhotoReviewService $photos) {}

    // GET /api/clubs/{club}/photos?cursor=&limit= — newest first.
    public function index(CursorPageRequest $request, Club $club): JsonResponse
    {
        ClubAccess::requireManager($club, $request->user());

        $page = ClubCursor::page(
            ClubPhotoSubmission::query()->where('club_photo_submissions.club_id', $club->id)->select('club_photo_submissions.*'),
            [['club_photo_submissions.id', 'desc']],
            $request->pageCursor(),
            ClubCursor::perPage($request->pageLimit()),
        );

        return response()->json([
            'data' => PhotoSubmissionResource::collection($page['items'])->resolve($request),
            'meta' => ['next_cursor' => $page['next_cursor'], 'per_page' => $page['per_page']],
        ]);
    }

    // POST /api/clubs/{club}/photos (multipart: photo, rights_confirmed, note?) → 201 { data: PhotoSubmission }
    public function store(UploadClubPhotoRequest $request, Club $club): JsonResponse
    {
        $submission = $this->photos->submit(
            $club,
            $request->file('photo'),
            $request->user(),
            ClubPhotoSubmission::SOURCE_CLUB_UPLOAD,
            $request->validated('note'),
            $request->boolean('rights_confirmed'),
            ClubAuditEvent::ACTOR_CLUB_ADMIN,
        );

        return response()->json(['data' => (new PhotoSubmissionResource($submission->refresh()))->resolve($request)], 201);
    }
}
