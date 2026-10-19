<?php

namespace App\Http\Requests\Clubs\Api;

use App\Exceptions\ClubDomainException;
use App\Models\Club;
use App\Models\ClubContentReport;
use App\Services\Clubs\ClubAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/clubs/{club}/reports — `{ subject_type, subject_id?, reason, details? }`.
 * Any ACTIVE member (any role) may report, also on a suspended club: reporting
 * is a safety channel, not a club write. Membership is checked before
 * validation (non-members 404). Whether the subject belongs to the club is
 * ClubContentReportService (422 on `subject_id`).
 */
class StoreClubReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $club = $this->route('club');
        if (!$club instanceof Club) {
            throw ClubDomainException::notFound();
        }
        ClubAccess::requireMember($club, $this->user());

        return true;
    }

    protected function prepareForValidation(): void
    {
        $details = $this->input('details');
        $subject = $this->input('subject_id');
        $this->merge([
            'details'    => is_string($details) && trim($details) !== '' ? trim($details) : null,
            'subject_id' => is_int($subject) ? (string) $subject : $subject,
        ]);
    }

    public function rules(): array
    {
        return [
            'subject_type' => ['required', 'string', Rule::in(ClubContentReport::SUBJECT_TYPES)],
            'subject_id'   => ['nullable', 'string', 'max:64', Rule::requiredIf(fn () => in_array($this->input('subject_type'), [ClubContentReport::SUBJECT_COMPETITION, ClubContentReport::SUBJECT_MEMBER], true))],
            'reason'       => ['required', 'string', Rule::in(ClubContentReport::REASONS)],
            'details'      => ['nullable', 'string', 'max:500'],
        ];
    }
}
