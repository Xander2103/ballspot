<?php

namespace App\Http\Requests\Clubs\Admin;

use App\Enums\ClubRole;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Add an existing user by username or email with a role. */
class AddClubMemberRequest extends AdminClubRequest
{
    private ?User $target = null;

    protected $errorBag = 'addMember';

    protected function prepareForValidation(): void
    {
        $this->merge(['identifier' => $this->clean('identifier')]);
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:190'],
            'role'       => ['required', Rule::in(ClubRole::values())],
        ];
    }

    public function messages(): array
    {
        return ['identifier.required' => 'Enter the username or email of an existing player.'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('identifier')) {
                return;
            }
            $this->target = self::findUser((string) $this->input('identifier'));
            if ($this->target === null) {
                $validator->errors()->add('identifier', 'No active user with this username or email.');
            }
        }];
    }

    public function target(): User
    {
        return $this->target;
    }

    public function role(): ClubRole
    {
        return ClubRole::from((string) $this->input('role'));
    }
}
