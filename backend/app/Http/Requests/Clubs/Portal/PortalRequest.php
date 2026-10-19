<?php

namespace App\Http\Requests\Clubs\Portal;

use App\Models\Club;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for club portal forms. authorize() checks the ClubPolicy ability
 * named by $ability for the route's {club} (403 otherwise); the
 * `club.portal` middleware has already established the owner/admin
 * membership. Field errors go back to the form with old input.
 */
abstract class PortalRequest extends FormRequest
{
    /** ClubPolicy ability required for this form. */
    protected string $ability = 'portal.access';

    public function authorize(): bool
    {
        $club = $this->route('club');

        return $club instanceof Club && (bool) $this->user()?->can($this->ability, $club);
    }

    protected function club(): Club
    {
        return $this->route('club');
    }

    protected function clean(string $key): ?string
    {
        $value = $this->input($key);
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
