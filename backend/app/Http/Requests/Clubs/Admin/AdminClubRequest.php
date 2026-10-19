<?php

namespace App\Http\Requests\Clubs\Admin;

use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for central-admin club forms: authorized through the `admin.manage`
 * ability (contracts §3.5, D21), with the club from the route when present.
 * Field errors go back to the form with old input (standard web behaviour).
 */
abstract class AdminClubRequest extends FormRequest
{
    public function authorize(): bool
    {
        $club = $this->route('club');

        return (bool) $this->user()?->can('admin.manage', $club instanceof Club ? $club : null);
    }

    /** Trimmed string input or null when empty. */
    protected function clean(string $key): ?string
    {
        $value = $this->input($key);
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * An active (not anonymised) user by exact username or email, or null.
     * Used to resolve "add member" / "first owner" identifiers.
     */
    public static function findUser(?string $identifier): ?User
    {
        $identifier = trim((string) $identifier);
        if ($identifier === '') {
            return null;
        }
        $values = array_values(array_unique([$identifier, mb_strtolower($identifier)]));

        return User::query()
            ->whereNull('anonymized_at')
            ->where(fn ($q) => $q->whereIn('username', $values)->orWhereIn('email', $values))
            ->orderBy('id')
            ->first();
    }
}
