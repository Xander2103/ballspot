<?php

namespace App\Http\Controllers\ClubPortal;

use App\Enums\PhotoSubmissionStatus;
use App\Exceptions\ClubDomainException;
use App\Http\Requests\Clubs\Portal\PortalPhotoUploadRequest;
use App\Http\Requests\Clubs\Portal\PortalPhotoWithdrawRequest;
use App\Models\Club;
use App\Models\ClubPhotoSubmission;
use App\Services\Clubs\ClubPhotoReviewService;
use Illuminate\Http\Request;

/**
 * Portal Photos: multi-upload (source club_upload, actorKind club_admin) into
 * BallPicker's review queue, the club's submissions with status and
 * rejection reasons, and withdrawing a pending upload nobody picked up yet.
 * Preparing / converting / publishing challenges and removing the ball stay
 * BallPicker (platform) tasks. {photoSubmission} is scoped to {club}.
 */
class PhotoController extends PortalController
{
    public function __construct(private ClubPhotoReviewService $photos) {}

    public function index(Request $request, Club $club)
    {
        $status = in_array($request->query('status'), PhotoSubmissionStatus::values(), true) ? (string) $request->query('status') : '';

        return view('club-portal.photos', [
            'club'      => $club,
            'photos'    => $this->photos->forClub($club, $status !== '' ? $status : null, 24),
            'counts'    => $this->photos->statusCounts($club),
            'status'    => $status,
            'maxFiles'  => PortalPhotoUploadRequest::MAX_FILES,
            'canUpload' => $request->user()->can('uploadPhotos', $club),
        ]);
    }

    public function store(PortalPhotoUploadRequest $request, Club $club)
    {
        $stored = 0;
        $failed = [];
        foreach ($request->file('photos', []) as $file) {
            try {
                $this->photos->submit(
                    $club, $file, $request->user(), ClubPhotoSubmission::SOURCE_CLUB_UPLOAD,
                    $request->input('club_note'), $request->boolean('rights_confirmed'), self::ACTOR,
                );
                $stored++;
            } catch (ClubDomainException $e) {
                // One bad file never blocks the rest; club-level refusals stop the batch.
                if (in_array($e->errorCode(), [ClubDomainException::CLUB_STATUS_INVALID, ClubDomainException::CLUB_UNAVAILABLE, ClubDomainException::PHOTO_PENDING_LIMIT], true)) {
                    if ($stored === 0) {
                        throw $e;
                    }
                    $failed[] = $e->getMessage();
                    break;
                }
                $failed[] = mb_substr((string) $file->getClientOriginalName(), 0, 80) . ': ' . $e->getMessage();
            }
        }

        $this->logPortal($request, 'photos_uploaded', ['club_id' => $club->id, 'stored' => $stored, 'failed' => count($failed)]);

        $redirect = redirect()->route('club-portal.photos.index', $club);
        if ($stored > 0) {
            $redirect->with('success', trans_choice('club_portal.photos.flash.uploaded', $stored, ['count' => $stored]));
        }
        if ($failed !== []) {
            $redirect->with($stored > 0 ? 'warning' : 'error', __('club_portal.photos.flash.not_added', ['list' => implode(' · ', $failed)]));
        }

        return $redirect;
    }

    public function withdraw(PortalPhotoWithdrawRequest $request, Club $club, ClubPhotoSubmission $photoSubmission)
    {
        $photoSubmission->setRelation('club', $club);
        $this->photos->withdraw($photoSubmission, $request->user(), self::ACTOR);
        $this->logPortal($request, 'photo_withdrawn', ['club_id' => $club->id, 'submission_id' => $photoSubmission->id]);

        return back()->with('success', __('club_portal.photos.flash.withdrawn'));
    }
}
