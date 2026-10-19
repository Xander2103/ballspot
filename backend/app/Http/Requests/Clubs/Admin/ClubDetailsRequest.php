<?php

namespace App\Http\Requests\Clubs\Admin;

use App\Enums\ClubType;
use Illuminate\Validation\Rule;

/**
 * Shared identity / location / contact rules of the create and edit forms.
 */
abstract class ClubDetailsRequest extends AdminClubRequest
{
    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['name', 'slug', 'description', 'city', 'region', 'external_ref', 'contact_name', 'contact_email'] as $key) {
            if ($this->has($key)) {
                $merge[$key] = $this->clean($key);
            }
        }
        if ($this->has('country_code')) {
            $cc = $this->clean('country_code');
            $merge['country_code'] = $cc === null ? null : strtoupper($cc);
        }
        if ($this->has('contact_email') && $merge['contact_email'] !== null) {
            $merge['contact_email'] = mb_strtolower($merge['contact_email']);
        }
        $this->merge($merge);
    }

    protected function detailRules(): array
    {
        return [
            'name'          => ['required', 'string', 'min:2', 'max:80'],
            'slug'          => ['nullable', 'string', 'max:90', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description'   => ['nullable', 'string', 'max:2000'],
            'sport_id'      => ['nullable', 'integer', 'exists:sports,id'],
            'club_type'     => ['required', Rule::in(ClubType::values())],
            'city'          => ['nullable', 'string', 'max:80'],
            'region'        => ['nullable', 'string', 'max:80'],
            'country_code'  => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'external_ref'  => ['nullable', 'string', 'max:60'],
            'contact_name'  => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'string', 'email', 'max:190'],
            'timezone'      => ['required', 'string', 'timezone:all'],
            'logo'          => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:' . (int) config('ballspot.clubs.media.logo_max_kb', 2048)],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex'         => 'Use lowercase letters, numbers and single dashes only.',
            'country_code.regex' => 'Use a two-letter ISO country code, e.g. BE.',
            'country_code.size'  => 'Use a two-letter ISO country code, e.g. BE.',
        ];
    }

    /** Validated identity fields for ClubService (no logo, no owner). */
    public function clubData(): array
    {
        return collect($this->validated())
            ->only(['name', 'slug', 'description', 'sport_id', 'club_type', 'city', 'region', 'country_code', 'external_ref', 'contact_name', 'contact_email', 'timezone'])
            ->all();
    }
}
