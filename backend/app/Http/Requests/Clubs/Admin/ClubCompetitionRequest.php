<?php

namespace App\Http\Requests\Clubs\Admin;

use App\Enums\NormalPlayPolicy;
use App\Enums\ReplayMode;
use App\Enums\ScoreRule;
use App\Models\Club;
use App\Models\ClubCompetition;
use Carbon\Carbon;
use Illuminate\Validation\Rule;

/**
 * Competition editor (plan §8.4). Window fields are `datetime-local` values
 * in the CLUB's timezone and are converted to UTC here. Locked fields are
 * rendered disabled, so they are absent from the request: only the present
 * keys are passed on, and ClubCompetitionRulesGuard rejects a locked field
 * that changes anyway (competition_rules_locked).
 */
class ClubCompetitionRequest extends AdminClubRequest
{
    public const DATETIME = 'Y-m-d\TH:i';

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['name', 'description'] as $key) {
            if ($this->has($key)) {
                $merge[$key] = $this->clean($key);
            }
        }
        if ($this->boolean('pack_ids_present') && !$this->has('pack_ids')) {
            $merge['pack_ids'] = [];
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        $club    = $this->route('club');
        $cap     = max(2, (int) config('ballspot.clubs.competition.max_attempts_cap', 10));
        $create  = $this->route('competition') === null;
        $req     = $create ? 'required' : 'sometimes';

        return [
            'name'               => [$req, 'string', 'max:120'],
            'description'        => ['nullable', 'string', 'max:2000'],
            'starts_at'          => [$req, 'date_format:' . self::DATETIME],
            'ends_at'            => [$req, 'date_format:' . self::DATETIME],
            'replay_mode'        => [$req, Rule::in(ReplayMode::values())],
            'max_attempts'       => ['nullable', 'integer', 'min:2', 'max:' . $cap, 'required_if:replay_mode,' . ReplayMode::MultipleAttempts->value],
            'score_rule'         => ['nullable', Rule::in(ScoreRule::values())],
            'normal_play_policy' => [$req, Rule::in(NormalPlayPolicy::values())],
            'pack_ids'           => ['sometimes', 'array', 'max:' . (int) config('ballspot.clubs.limits.max_packs', 100)],
            'pack_ids.*'         => ['integer', 'distinct', Rule::exists('challenge_packs', 'id')->where('club_id', $club instanceof Club ? $club->id : 0)],
            'version'            => [$create ? 'nullable' : 'required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'pack_ids.*.exists'      => 'Only packs of this club can be linked to its competitions.',
            'max_attempts.required_if' => 'Choose the maximum number of attempts.',
        ];
    }

    /**
     * Service input: only the submitted keys, window converted to UTC; a
     * window value equal (to the minute) to the stored one is not a change.
     */
    public function competitionData(Club $club, ?ClubCompetition $current = null): array
    {
        $tz   = $current?->timezone ?? $club->timezone;
        $data = [];
        foreach (['name', 'description', 'replay_mode', 'score_rule', 'normal_play_policy'] as $key) {
            if ($this->has($key)) {
                $data[$key] = $this->validated($key);
            }
        }
        if ($this->has('max_attempts') && $this->validated('max_attempts') !== null) {
            $data['max_attempts'] = (int) $this->validated('max_attempts');
        }
        foreach (['starts_at', 'ends_at'] as $key) {
            if (!$this->has($key)) {
                continue;
            }
            $value = (string) $this->validated($key);
            if ($current !== null && $current->{$key}?->copy()->setTimezone($tz)->format(self::DATETIME) === $value) {
                continue;
            }
            $data[$key] = Carbon::createFromFormat(self::DATETIME, $value, $tz)->utc()->startOfMinute();
        }
        if ($this->has('pack_ids')) {
            $data['pack_ids'] = array_map('intval', (array) $this->validated('pack_ids'));
        }

        return $data;
    }
}
