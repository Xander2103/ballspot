<?php

namespace App\Http\Controllers\Admin\Clubs;

use App\Enums\PhotoSubmissionStatus;
use App\Exceptions\ClubDomainException;
use App\Models\ChallengeCategory;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubPhotoSubmission;
use App\Models\Sport;
use App\Services\Clubs\ClubContentScope;
use App\Services\Clubs\ClubMediaService;
use App\Services\Clubs\ClubPhotoReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Club photo review (D1, plan §7.2 / §8.5): global queue, upload on behalf of
 * a club, prepare page (download original → offline ball removal → upload the
 * ball-removed image + mark the ball → convert), assign / in review / start
 * preparation / reject. All workflow rules live in ClubPhotoReviewService.
 */
class ClubPhotoController extends AdminClubController
{
    /** Max files per "upload on behalf" request. */
    public const MAX_FILES = 20;

    public function __construct(
        private ClubPhotoReviewService $photos,
        private ClubMediaService $media,
        private ClubContentScope $scope,
    ) {}

    /** GET /admin/club-photos — status / club / assignee filters, oldest first. */
    public function index(Request $request)
    {
        Gate::authorize('admin.manage');

        $filters = [
            'status'   => (string) $request->query('status', ClubPhotoReviewService::FILTER_OPEN),
            'club'     => $request->query('club'),
            'assignee' => (string) $request->query('assignee', ''),
        ];

        return view('admin.clubs.photos.index', [
            'submissions' => $this->photos->queue($filters, $request->user()),
            'filters'     => $filters,
            'counts'      => $this->photos->statusCounts(),
            'clubOptions' => $this->scope->clubOptions(),
            'media'       => $this->media,
        ]);
    }

    /** POST /admin/clubs/{club}/photos — admin upload on behalf of the club (multi-file). */
    public function store(Request $request, Club $club)
    {
        $this->authorize('admin.manage', $club);

        $data = $request->validate([
            'photos'          => ['required', 'array', 'min:1', 'max:' . self::MAX_FILES],
            'photos.*'        => ['required', 'file', 'max:' . (int) config('ballspot.clubs.media.max_kb', 15360)],
            'club_note'       => ['nullable', 'string', 'max:500'],
            'rights_confirmed' => ['nullable', 'boolean'],
        ], [
            'photos.required' => 'Choose at least one photo.',
            'photos.max'      => 'Upload at most ' . self::MAX_FILES . ' photos at once.',
        ]);

        $stored = 0;
        $failed = [];
        foreach ($request->file('photos', []) as $i => $file) {
            try {
                $this->photos->submit(
                    $club, $file, $request->user(), ClubPhotoSubmission::SOURCE_ADMIN_UPLOAD,
                    $data['club_note'] ?? null, $request->boolean('rights_confirmed'), static::ACTOR,
                );
                $stored++;
            } catch (ClubDomainException $e) {
                // One bad file never blocks the rest of the batch.
                $failed[] = mb_substr((string) $file->getClientOriginalName(), 0, 80) . ': ' . $e->getMessage();
                if (in_array($e->errorCode(), [ClubDomainException::CLUB_STATUS_INVALID, ClubDomainException::CLUB_UNAVAILABLE], true)) {
                    throw $e;
                }
            }
        }

        $this->logAdmin($request, 'photos_uploaded', ['club_id' => $club->id, 'stored' => $stored, 'failed' => count($failed)]);

        $redirect = redirect()->route('admin.clubs.show', ['club' => $club, 'tab' => 'photos']);
        if ($stored > 0) {
            $redirect->with('success', $stored . ' ' . ($stored === 1 ? 'photo' : 'photos') . ' added to the review queue.');
        }
        if ($failed !== []) {
            $redirect->with($stored > 0 ? 'warning' : 'error', 'Not added — ' . implode(' · ', $failed));
        }

        return $redirect;
    }

    /** GET /admin/club-photos/{submission}/prepare — detail + conversion form. */
    public function prepare(ClubPhotoSubmission $submission)
    {
        $club = $submission->club()->firstOrFail();
        $this->authorize('admin.manage', $club);

        $submission->load(['uploadedBy:id,username', 'assignedTo:id,username', 'reviewedBy:id,username', 'challenge']);

        return view('admin.clubs.photos.prepare', [
            'submission'  => $submission,
            'club'        => $club,
            'photoUrl'    => ClubMediaService::isSafePath((string) $submission->path) ? $this->media->signedUrl($submission->path) : null,
            'allowedNext' => $this->photos->allowedNext($submission),
            'packs'       => $this->photos->eligiblePacks($club),
            'categories'  => ChallengeCategory::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'sports'      => Sport::orderBy('sort_order')->get(),
            'history'     => ClubAuditEvent::query()
                ->where('club_id', $club->id)
                ->where('subject_type', $submission->getMorphClass())
                ->where('subject_id', $submission->id)
                ->with('actor:id,username')
                ->orderByDesc('id')->limit(20)->get(),
            // Packs the converted challenge is in (links after conversion).
            'challengePacks' => $submission->challenge ? $submission->challenge->packs()->orderBy('name')->get(['challenge_packs.id', 'name']) : collect(),
        ]);
    }

    /** GET /admin/club-photos/{submission}/original — download for offline ball removal. */
    public function download(ClubPhotoSubmission $submission)
    {
        $club = $submission->club()->firstOrFail();
        $this->authorize('admin.manage', $club);

        $disk = Storage::disk($this->media->disk());
        abort_unless(ClubMediaService::isSafePath((string) $submission->path) && $disk->exists($submission->path), 404);

        return $disk->download($submission->path, 'club-' . $club->id . '-photo-' . $submission->id . '.jpg', [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function assign(Request $request, ClubPhotoSubmission $submission)
    {
        $this->authorize('admin.manage', $submission->club);
        $this->photos->assign($submission, $request->user());
        $this->logAdmin($request, 'photo_assigned', ['club_id' => $submission->club_id, 'submission_id' => $submission->id]);

        return back()->with('success', 'Photo #' . $submission->id . ' is assigned to you.');
    }

    public function markInReview(Request $request, ClubPhotoSubmission $submission)
    {
        $this->authorize('admin.manage', $submission->club);
        $this->photos->markInReview($submission, $request->user());
        $this->logAdmin($request, 'photo_in_review', ['club_id' => $submission->club_id, 'submission_id' => $submission->id]);

        return back()->with('success', 'Photo #' . $submission->id . ' is in review.');
    }

    public function startPreparation(Request $request, ClubPhotoSubmission $submission)
    {
        $this->authorize('admin.manage', $submission->club);
        $this->photos->startPreparation($submission, $request->user());
        $this->logAdmin($request, 'photo_preparation_started', ['club_id' => $submission->club_id, 'submission_id' => $submission->id]);

        return redirect()->route('admin.club-photos.prepare', $submission)
            ->with('success', 'Photo #' . $submission->id . ' is in preparation. Download the original, remove the ball, then upload the result below.');
    }

    public function reject(Request $request, ClubPhotoSubmission $submission)
    {
        $this->authorize('admin.manage', $submission->club);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $this->photos->reject($submission, $request->user(), $data['reason']);
        $this->logAdmin($request, 'photo_rejected', ['club_id' => $submission->club_id, 'submission_id' => $submission->id]);

        return back()->with('success', 'Photo #' . $submission->id . ' rejected. The reason is visible to the club\'s admins.');
    }

    /** POST /admin/club-photos/{submission}/convert — create the club challenge (+ optional pack). */
    public function convert(Request $request, ClubPhotoSubmission $submission)
    {
        $club = $submission->club()->firstOrFail();
        $this->authorize('admin.manage', $club);

        $request->validate([
            'hidden_image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:' . (int) config('ballspot.clubs.media.max_kb', 15360)],
            'pack_id'      => ['nullable', 'integer'],
        ], ['hidden_image.required' => 'Upload the ball-removed image.']);

        $hidden = $request->file('hidden_image');
        $result = $this->photos->convert(
            $submission,
            $request->user(),
            $request->only(['title', 'difficulty', 'status', 'ball_x_ratio', 'ball_y_ratio', 'challenge_category_id', 'sport_id', 'tags', 'subcategories', 'admin_note']),
            $hidden,
            $request->filled('pack_id') ? (int) $request->input('pack_id') : null,
        );

        $this->logAdmin($request, 'photo_converted', [
            'club_id' => $club->id, 'submission_id' => $submission->id,
            'challenge_id' => $result['challenge']->id, 'pack_id' => $result['pack']?->id,
        ]);

        $redirect = redirect()->route('admin.club-photos.prepare', $submission)
            ->with('success', 'Challenge "' . $result['challenge']->title . '" created for ' . $club->name
                . ($result['pack'] ? ' and added to the pack "' . $result['pack']->name . '"' : '') . '.');

        // The ball ratios are measured on the ball-removed image; a different
        // frame than the original would misplace the reveal marker.
        if ($warning = $this->frameWarning($submission, $hidden)) {
            $redirect->with('warning', $warning);
        }

        return $redirect;
    }

    private function frameWarning(ClubPhotoSubmission $submission, $hidden): ?string
    {
        $info = @getimagesize($hidden->getRealPath());
        if ($info === false || !$submission->width || !$submission->height || !$info[1]) {
            return null;
        }
        $original = $submission->width / $submission->height;
        $edited   = $info[0] / $info[1];

        return abs($edited - $original) / $original > 0.02
            ? 'The ball-removed image has a different aspect ratio than the original photo. Check the reveal on the challenge preview.'
            : null;
    }

    /** File size for the photo views: KB below 1 MB (a 300 KB photo never reads "0.3 MB" / "0.0 MB"). */
    public static function humanSize(?int $bytes): string
    {
        $bytes = (int) $bytes;

        return $bytes < 1048576 ? max(1, (int) round($bytes / 1024)) . ' KB' : number_format($bytes / 1048576, 1) . ' MB';
    }

    /** Shared labels/classes for the photo views. */
    public static function statusLabels(): array
    {
        return [
            PhotoSubmissionStatus::Pending->value       => 'Pending',
            PhotoSubmissionStatus::InReview->value      => 'In review',
            PhotoSubmissionStatus::InPreparation->value => 'In preparation',
            PhotoSubmissionStatus::Converted->value     => 'Converted',
            PhotoSubmissionStatus::Rejected->value      => 'Rejected',
        ];
    }

    public static function statusClasses(): array
    {
        return [
            PhotoSubmissionStatus::Pending->value       => 'badge-soft-warn',
            PhotoSubmissionStatus::InReview->value      => 'badge-scheduled',
            PhotoSubmissionStatus::InPreparation->value => 'badge-active',
            PhotoSubmissionStatus::Converted->value     => 'badge-ok',
            PhotoSubmissionStatus::Rejected->value      => 'badge-archived',
        ];
    }
}
