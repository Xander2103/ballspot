<?php

namespace App\Services\Clubs;

use App\Enums\PhotoSubmissionStatus;
use App\Exceptions\ClubDomainException;
use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubPhotoSubmission;
use App\Models\Sport;
use App\Models\User;
use App\Services\ChallengeCreator;
use App\Support\AppLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Club photo workflow (plan §4.7, §8.5, AT-ADM-08) — shared by the central
 * admin (D1) and the club self-service portal (D2).
 *
 *   submit ─► pending ─► in_review ─► in_preparation ─► converted
 *                 │           │              │
 *                 └───────────┴──────────────┴──────► rejected (reason shown to the club)
 *   pending club uploads can be withdrawn by the club (row + file deleted).
 *
 * Every step is a transaction that locks the submission row, re-checks the
 * status (illegal step → `photo_status_invalid`) and writes a club audit row
 * (mirrored to AppLog by ClubAuditLogger). Files are written before and
 * removed after a failed transaction, so a refused step leaves no orphan.
 *
 * Conversion: the ball-removed image is uploaded by BallPicker after offline
 * editing; the original is a COPY of the submission's stored photo (the
 * submission keeps its own file as history). The challenge is created via
 * ChallengeCreator (club_id, usage_pool `club`, private images) and can be
 * appended to a pack of the same club.
 */
class ClubPhotoReviewService
{
    /** Allowed next statuses per status. */
    private const TRANSITIONS = [
        'pending'        => ['in_review', 'in_preparation', 'rejected', 'converted'],
        'in_review'      => ['in_preparation', 'rejected', 'converted'],
        'in_preparation' => ['rejected', 'converted'],
        'converted'      => [],
        'rejected'       => [],
    ];

    /** Queue filter: status values beyond the enum. */
    public const FILTER_OPEN     = 'open';
    public const FILTER_AWAITING = 'awaiting';
    public const FILTER_ALL      = 'all';

    public const DIFFICULTIES = ['easy', 'medium', 'hard'];
    public const CHALLENGE_STATUSES = ['draft', 'active'];

    private const ALLOWED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function __construct(
        private ClubMediaService $media,
        private ClubAuditLogger $audit,
        private ChallengeCreator $creator,
    ) {}

    // ------------------------------------------------------------ submit

    /**
     * Store a photo for a club as a `pending` submission.
     *
     * @param string $source    ClubPhotoSubmission::SOURCE_ADMIN_UPLOAD | SOURCE_CLUB_UPLOAD
     * @param string $actorKind ClubAuditEvent::ACTOR_* (platform_admin for the central admin, club_admin for the portal)
     *
     * @throws ClubDomainException photo_rights_required | photo_pending_limit | photo_invalid | photo_duplicate |
     *                             club_unavailable (club upload to a non-active club) | club_status_invalid (archived club)
     */
    public function submit(
        Club $club,
        UploadedFile $file,
        User $uploader,
        string $source,
        ?string $clubNote,
        bool $rightsConfirmed,
        string $actorKind = ClubAuditEvent::ACTOR_PLATFORM_ADMIN,
    ): ClubPhotoSubmission {
        if (!in_array($source, ClubPhotoSubmission::SOURCES, true)) {
            throw new \InvalidArgumentException("Unknown photo source [{$source}].");
        }
        $clubUpload = $source === ClubPhotoSubmission::SOURCE_CLUB_UPLOAD;

        if ($club->isArchived()) {
            throw new ClubDomainException(ClubDomainException::CLUB_STATUS_INVALID, 422);
        }
        if ($clubUpload && !$club->isActive()) {
            throw ClubDomainException::unavailable();
        }
        // Plan §11.5: every club-supplied photo carries a rights/consent confirmation.
        if ($clubUpload && !$rightsConfirmed) {
            throw new ClubDomainException(ClubDomainException::PHOTO_RIGHTS_REQUIRED, 422);
        }
        if ($clubUpload) {
            $this->assertBelowOpenLimit($club);
        }

        $this->assertAcceptableImage($file);
        $sha256 = (string) hash_file('sha256', $file->getRealPath());
        $this->assertNotDuplicate($club, $sha256, $source);

        // GD re-encode (EXIF/GPS stripped, longest edge capped) on the private disk.
        $stored = $this->media->storeImage($file, $club, ClubMediaService::KIND_PHOTO);

        try {
            return DB::transaction(function () use ($club, $uploader, $source, $clubNote, $rightsConfirmed, $actorKind, $clubUpload, $stored, $sha256) {
                // Serialise uploads per club so the duplicate and limit checks hold under races.
                Club::whereKey($club->id)->lockForUpdate()->first();
                $this->assertNotDuplicate($club, $sha256, $source);
                if ($clubUpload) {
                    $this->assertBelowOpenLimit($club);
                }

                $submission = ClubPhotoSubmission::create([
                    'club_id'             => $club->id,
                    'uploaded_by_user_id' => $uploader->id,
                    'source'              => $source,
                    'disk'                => $this->media->disk(),
                    'path'                => $stored['path'],
                    'mime'                => $stored['mime'],
                    'width'               => $stored['width'],
                    'height'              => $stored['height'],
                    'bytes'               => $stored['bytes'],
                    'sha256'              => $sha256,
                    'status'              => PhotoSubmissionStatus::Pending,
                    'club_note'           => $this->cleanText($clubNote, 500),
                    'rights_confirmed_at' => $rightsConfirmed ? now() : null,
                ]);

                $this->audit->log($club, 'photo.uploaded', $uploader, $submission, [
                    'submission_id' => $submission->id,
                    'source'        => $source,
                ], $actorKind);

                return $submission;
            });
        } catch (\Throwable $e) {
            $this->media->delete($stored['path']);
            throw $e;
        }
    }

    // ------------------------------------------------------------ review steps

    /** Assign the submission to a reviewer (default: the acting admin). Status unchanged. */
    public function assign(ClubPhotoSubmission $submission, User $admin, ?User $assignee = null): ClubPhotoSubmission
    {
        $assignee ??= $admin;

        return $this->step($submission, function (ClubPhotoSubmission $locked) use ($admin, $assignee) {
            if (!$locked->isOpen()) {
                throw new ClubDomainException(ClubDomainException::PHOTO_STATUS_INVALID, 422);
            }
            $from = $locked->assigned_to_user_id;
            $locked->update(['assigned_to_user_id' => $assignee->id]);

            $this->audit->log($locked->club, 'photo.assigned', $admin, $locked, array_filter([
                'submission_id'  => $locked->id,
                'target_user_id' => $assignee->id,
                'user_id'        => $from,
            ], fn ($v) => $v !== null));
        });
    }

    /** pending → in_review (assigns the acting admin when unassigned). */
    public function markInReview(ClubPhotoSubmission $submission, User $admin): ClubPhotoSubmission
    {
        return $this->transition($submission, $admin, PhotoSubmissionStatus::InReview, 'photo.in_review');
    }

    /** pending | in_review → in_preparation (BallPicker is removing the ball offline). */
    public function startPreparation(ClubPhotoSubmission $submission, User $admin): ClubPhotoSubmission
    {
        return $this->transition($submission, $admin, PhotoSubmissionStatus::InPreparation, 'photo.preparation_started');
    }

    /** Any open status → rejected. The reason (3–500 chars) is visible to the club's admins. */
    public function reject(ClubPhotoSubmission $submission, User $admin, string $reason): ClubPhotoSubmission
    {
        $reason = $this->cleanText($reason, 500) ?? '';
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:500']])->validate();

        return $this->transition($submission, $admin, PhotoSubmissionStatus::Rejected, 'photo.rejected', [
            'rejection_reason'    => $reason,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at'         => now(),
        ], ['reason' => $reason]);
    }

    /**
     * Any open status → converted: create the club challenge and optionally
     * append it to a pack of the same club.
     *
     * @param array{title: string, difficulty: string, status?: string, ball_x_ratio: float|string, ball_y_ratio: float|string,
     *              challenge_category_id?: ?int, sport_id?: ?int, tags?: ?string, subcategories?: ?array, admin_note?: ?string} $challengeData
     *
     * @return array{submission: ClubPhotoSubmission, challenge: Challenge, pack: ?ChallengePack}
     *
     * @throws ClubDomainException photo_status_invalid | photo_pack_invalid | competition_rules_locked | photo_invalid
     * @throws \Illuminate\Validation\ValidationException on invalid challenge fields
     */
    public function convert(
        ClubPhotoSubmission $submission,
        User $admin,
        array $challengeData,
        UploadedFile $hiddenImage,
        ?int $packId = null,
    ): array {
        $data = $this->validateChallengeData($challengeData);

        // Fail fast before any file is written; re-checked under the lock.
        $this->assertTransition($submission, PhotoSubmissionStatus::Converted);
        $club = $submission->club()->firstOrFail();
        $pack = $packId !== null ? $this->resolvePack($club, $packId) : null;

        // Sport: chosen → the club's sport → football (the admin form default).
        $data['sport_id'] ??= $club->sport_id ?? Sport::where('slug', 'football')->value('id');
        if ($data['sport_id'] === null) {
            throw ValidationException::withMessages(['sport_id' => 'Choose a sport for the challenge.']);
        }

        $hiddenPath = $this->media->storeImage($hiddenImage, $club, ClubMediaService::KIND_CHALLENGE_HIDDEN)['path'];
        $originalPath = null;

        try {
            $originalPath = $this->media->copyImage($submission->path, $club, ClubMediaService::KIND_CHALLENGE_ORIGINAL);

            return DB::transaction(function () use ($submission, $admin, $data, $club, $packId, $hiddenPath, $originalPath) {
                $locked = $this->lock($submission);
                $this->assertTransition($locked, PhotoSubmissionStatus::Converted);
                $pack = $packId !== null ? $this->resolvePack($club, $packId) : null;

                $challenge = $this->creator->create($data, $club, $hiddenPath, $originalPath, $admin);

                $from = $locked->status->value;
                $locked->update([
                    'status'              => PhotoSubmissionStatus::Converted,
                    'challenge_id'        => $challenge->id,
                    'reviewed_by_user_id' => $admin->id,
                    'reviewed_at'         => now(),
                    'assigned_to_user_id' => $locked->assigned_to_user_id ?? $admin->id,
                    'admin_note'          => $this->cleanText($data['admin_note'] ?? null, 2000) ?? $locked->admin_note,
                ]);

                if ($pack) {
                    $next = (int) DB::table('challenge_pack_challenge')->where('challenge_pack_id', $pack->id)->max('sort_order') + 1;
                    $pack->challenges()->attach($challenge->id, ['sort_order' => $next]);
                    $this->audit->log($club, 'pack.updated', $admin, $pack, [
                        'pack_id'      => $pack->id,
                        'name'         => $pack->name,
                        'challenge_id' => $challenge->id,
                        'fields'       => ['challenges'],
                        'count'        => $pack->challenges()->count(),
                    ]);
                }

                $this->audit->log($club, 'photo.converted', $admin, $locked, array_filter([
                    'submission_id' => $locked->id,
                    'challenge_id'  => $challenge->id,
                    'pack_id'       => $pack?->id,
                    'from_status'   => $from,
                ], fn ($v) => $v !== null));

                return ['submission' => $locked->fresh(), 'challenge' => $challenge, 'pack' => $pack];
            });
        } catch (\Throwable $e) {
            $this->media->delete($hiddenPath);
            if ($originalPath) {
                $this->media->delete($originalPath);
            }
            throw $e;
        }
    }

    /**
     * The club takes back a club upload nobody has picked up yet (portal):
     * the row and its private file are deleted; the audit row remains.
     *
     * @throws ClubDomainException photo_status_invalid when not a pending club upload
     */
    public function withdraw(ClubPhotoSubmission $submission, User $actor, string $actorKind = ClubAuditEvent::ACTOR_CLUB_ADMIN): void
    {
        $path = DB::transaction(function () use ($submission, $actor, $actorKind) {
            $locked = $this->lock($submission);
            if ($locked->status !== PhotoSubmissionStatus::Pending
                || $locked->source !== ClubPhotoSubmission::SOURCE_CLUB_UPLOAD
                || $locked->assigned_to_user_id !== null) {
                throw new ClubDomainException(ClubDomainException::PHOTO_STATUS_INVALID, 422);
            }

            $this->audit->log($locked->club, 'photo.withdrawn', $actor, $locked, [
                'submission_id' => $locked->id,
                'source'        => $locked->source,
            ], $actorKind);
            $path = $locked->path;
            $locked->delete();

            return $path;
        });

        $this->media->delete($path);
    }

    // ------------------------------------------------------------ queries

    /**
     * Global review queue, oldest first.
     *
     * @param array{status?: ?string, club?: int|string|null, assignee?: int|string|null} $filters
     *        status: open (default) | awaiting | all | a PhotoSubmissionStatus value;
     *        assignee: '' (any) | 'me' | 'unassigned' | user id
     */
    public function queue(array $filters, User $viewer, int $perPage = 25): LengthAwarePaginator
    {
        $query = ClubPhotoSubmission::query()
            ->with(['club:id,name,status', 'uploadedBy:id,username', 'assignedTo:id,username', 'challenge:id,title']);

        $this->applyStatus($query, $this->normalizeStatusFilter($filters['status'] ?? null));

        $club = $filters['club'] ?? null;
        if (is_scalar($club) && ctype_digit((string) $club)) {
            $query->where('club_id', (int) $club);
        }

        $assignee = is_scalar($filters['assignee'] ?? null) ? (string) $filters['assignee'] : '';
        match (true) {
            $assignee === 'me'         => $query->where('assigned_to_user_id', $viewer->id),
            $assignee === 'unassigned' => $query->whereNull('assigned_to_user_id'),
            ctype_digit($assignee)     => $query->where('assigned_to_user_id', (int) $assignee),
            default                    => null,
        };

        return $query->orderBy('created_at')->orderBy('id')->paginate($perPage)->withQueryString();
    }

    /** One club's submissions (newest first), e.g. the club Photos tab or the portal. */
    public function forClub(Club $club, ?string $status = null, int $perPage = 25): LengthAwarePaginator
    {
        $query = ClubPhotoSubmission::query()
            ->where('club_id', $club->id)
            ->with(['uploadedBy:id,username', 'assignedTo:id,username', 'challenge:id,title']);
        $this->applyStatus($query, $this->normalizeStatusFilter($status, self::FILTER_ALL));

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    /** @return array<string, int> count per status value (every status present, 0 default). */
    public function statusCounts(?Club $club = null): array
    {
        $counts = ClubPhotoSubmission::query()
            ->when($club, fn ($q) => $q->where('club_id', $club->id))
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        $out = [];
        foreach (PhotoSubmissionStatus::values() as $status) {
            $out[$status] = (int) ($counts[$status] ?? 0);
        }

        return $out;
    }

    /** pending + in_review (the nav badge). */
    public function awaitingReviewCount(?Club $club = null): int
    {
        return ClubPhotoSubmission::query()->awaitingReview()
            ->when($club, fn ($q) => $q->where('club_id', $club->id))->count();
    }

    /** Packs of the club a converted challenge may be appended to (not archived), name order. */
    public function eligiblePacks(Club $club): Collection
    {
        return ChallengePack::query()
            ->where('club_id', $club->id)
            ->where('status', '!=', ChallengePack::STATUS_ARCHIVED)
            ->withCount('challenges')
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'slug', 'club_id']);
    }

    /** Statuses a submission may move to next (for UI buttons). @return list<string> */
    public function allowedNext(ClubPhotoSubmission $submission): array
    {
        return self::TRANSITIONS[$submission->status->value] ?? [];
    }

    public function canTransition(ClubPhotoSubmission $submission, PhotoSubmissionStatus $to): bool
    {
        return in_array($to->value, $this->allowedNext($submission), true);
    }

    // ------------------------------------------------------------ internals

    /** @param array<string, mixed> $extra columns; $meta extra audit metadata */
    private function transition(ClubPhotoSubmission $submission, User $admin, PhotoSubmissionStatus $to, string $action, array $extra = [], array $meta = []): ClubPhotoSubmission
    {
        return $this->step($submission, function (ClubPhotoSubmission $locked) use ($admin, $to, $action, $extra, $meta) {
            $this->assertTransition($locked, $to);
            $from = $locked->status->value;

            $locked->update(['status' => $to] + $extra + [
                'assigned_to_user_id' => $locked->assigned_to_user_id ?? $admin->id,
            ]);

            $this->audit->log($locked->club, $action, $admin, $locked, [
                'submission_id' => $locked->id,
                'from_status'   => $from,
                'to_status'     => $to->value,
            ] + $meta);
        });
    }

    private function step(ClubPhotoSubmission $submission, \Closure $change): ClubPhotoSubmission
    {
        return DB::transaction(function () use ($submission, $change) {
            $locked = $this->lock($submission);
            $change($locked);

            return $locked->fresh();
        });
    }

    private function lock(ClubPhotoSubmission $submission): ClubPhotoSubmission
    {
        $locked = ClubPhotoSubmission::whereKey($submission->id)->lockForUpdate()->first();
        if (!$locked) {
            throw new ClubDomainException(ClubDomainException::PHOTO_STATUS_INVALID, 422);
        }

        return $locked->setRelation('club', $submission->relationLoaded('club') ? $submission->club : $locked->club()->firstOrFail());
    }

    private function assertTransition(ClubPhotoSubmission $submission, PhotoSubmissionStatus $to): void
    {
        if (!$this->canTransition($submission, $to)) {
            AppLog::warn('club.photo_transition_refused', [
                'club_id' => $submission->club_id, 'submission_id' => $submission->id,
                'from_status' => $submission->status->value, 'to_status' => $to->value,
            ]);
            throw new ClubDomainException(ClubDomainException::PHOTO_STATUS_INVALID, 422);
        }
    }

    /** Pack of the same club, not archived, not frozen by a started competition. */
    private function resolvePack(Club $club, int $packId): ChallengePack
    {
        $pack = ChallengePack::find($packId);
        if (!$pack || $pack->club_id !== $club->id || $pack->status === ChallengePack::STATUS_ARCHIVED) {
            throw new ClubDomainException(ClubDomainException::PHOTO_PACK_INVALID, 422);
        }

        // Linked to a published competition whose rules are locked → its
        // content is frozen (plan §5.7, invariant 6).
        if (app(CompetitionContentLock::class)->packIsLocked($pack)) {
            throw new ClubDomainException(ClubDomainException::COMPETITION_RULES_LOCKED, 422);
        }

        return $pack;
    }

    private function assertBelowOpenLimit(Club $club): void
    {
        $limit = (int) config('ballspot.clubs.photos.max_open_per_club', 100);
        if ($limit > 0 && ClubPhotoSubmission::where('club_id', $club->id)->open()->count() >= $limit) {
            AppLog::warn('club.photo_refused', ['club_id' => $club->id, 'reason' => 'pending_limit']);
            throw new ClubDomainException(ClubDomainException::PHOTO_PENDING_LIMIT, 422);
        }
    }

    /** Same original bytes already submitted for this club (any status) → photo_duplicate. */
    private function assertNotDuplicate(Club $club, string $sha256, string $source): void
    {
        if (ClubPhotoSubmission::where('club_id', $club->id)->where('sha256', $sha256)->exists()) {
            AppLog::warn('club.photo_refused', ['club_id' => $club->id, 'reason' => 'duplicate', 'source' => $source]);
            throw new ClubDomainException(ClubDomainException::PHOTO_DUPLICATE, 422);
        }
    }

    /** Size, type (JPEG/PNG/WebP) and minimum dimensions, before anything is stored. */
    private function assertAcceptableImage(UploadedFile $file): void
    {
        $maxKb   = (int) config('ballspot.clubs.media.max_kb', 15360);
        $minEdge = (int) config('ballspot.clubs.photos.min_edge', 320);

        $info = $file->isValid() && $file->getSize() !== false && $file->getSize() <= $maxKb * 1024
            ? @getimagesize($file->getRealPath())
            : false;

        if ($info === false
            || !in_array($info[2] ?? null, self::ALLOWED_TYPES, true)
            || min((int) $info[0], (int) $info[1]) < $minEdge) {
            AppLog::warn('club.photo_refused', ['reason' => 'invalid', 'bytes' => $file->getSize() ?: null]);
            throw new ClubDomainException(ClubDomainException::PHOTO_INVALID, 422);
        }
    }

    /** @return array<string, mixed> */
    private function validateChallengeData(array $input): array
    {
        foreach (['ball_x_ratio', 'ball_y_ratio'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = str_replace(',', '.', $input[$field]);
            }
        }
        $input['status'] ??= 'active';

        return Validator::make($input, [
            'title'                 => ['required', 'string', 'max:255'],
            'difficulty'            => ['required', Rule::in(self::DIFFICULTIES)],
            'status'                => ['required', Rule::in(self::CHALLENGE_STATUSES)],
            'ball_x_ratio'          => ['required', 'numeric', 'between:0,1'],
            'ball_y_ratio'          => ['required', 'numeric', 'between:0,1'],
            'sport_id'              => ['nullable', 'integer', 'exists:sports,id'],
            'challenge_category_id' => ['nullable', 'integer', 'exists:challenge_categories,id'],
            'tags'                  => ['nullable', 'string', 'max:500'],
            'subcategories'         => ['nullable', 'array'],
            'subcategories.*'       => ['integer', 'exists:challenge_subcategories,id'],
            'admin_note'            => ['nullable', 'string', 'max:2000'],
        ])->validate();
    }

    private function normalizeStatusFilter(mixed $status, string $default = self::FILTER_OPEN): string
    {
        $status = is_string($status) ? trim($status) : '';
        $known = array_merge([self::FILTER_OPEN, self::FILTER_AWAITING, self::FILTER_ALL], PhotoSubmissionStatus::values());

        return in_array($status, $known, true) ? $status : $default;
    }

    private function applyStatus(Builder $query, string $status): void
    {
        match ($status) {
            self::FILTER_ALL      => null,
            self::FILTER_OPEN     => $query->open(),
            self::FILTER_AWAITING => $query->awaitingReview(),
            default               => $query->where('status', $status),
        };
    }

    private function cleanText(?string $text, int $max): ?string
    {
        $text = $text === null ? '' : trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '');

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
