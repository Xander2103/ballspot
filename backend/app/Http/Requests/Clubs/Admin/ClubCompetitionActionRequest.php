<?php

namespace App\Http\Requests\Clubs\Admin;

/**
 * Publish / cancel / finalize / rebuild / void actions on a competition.
 * `reason` is required for the void actions (competition and attempt),
 * optional otherwise; `version` guards publish/cancel/void against stale pages.
 */
class ClubCompetitionActionRequest extends AdminClubRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => $this->clean('reason')]);
    }

    public function rules(): array
    {
        $routeName = (string) $this->route()?->getName();
        $voiding   = str_ends_with($routeName, '.void');

        return [
            'reason'  => [$voiding ? 'required' : 'nullable', 'string', 'max:200'],
            'version' => ['nullable', 'integer'],
            'dry_run' => ['nullable', 'boolean'],
        ];
    }

    public function expectedVersion(): ?int
    {
        $v = $this->validated('version');

        return $v === null ? null : (int) $v;
    }
}
