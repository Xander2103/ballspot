<?php

namespace App\Http\Requests\Clubs\Admin;

use App\Models\User;
use Illuminate\Validation\Validator;

/** Create a club (D1: platform admins only), optional first owner by username/email. */
class StoreClubRequest extends ClubDetailsRequest
{
    private ?User $owner = null;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->merge(['owner' => $this->clean('owner')]);
    }

    public function rules(): array
    {
        return $this->detailRules() + [
            'owner'        => ['nullable', 'string', 'max:190'],
            'join_enabled' => ['nullable', 'boolean'],
            'max_members'  => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $identifier = $this->input('owner');
            if ($identifier !== null && $identifier !== '') {
                $this->owner = self::findUser($identifier);
                if ($this->owner === null) {
                    $validator->errors()->add('owner', 'No active user with this username or email.');
                }
            }
        }];
    }

    public function owner(): ?User
    {
        return $this->owner;
    }

    public function clubData(): array
    {
        $data = parent::clubData() + [
            'join_enabled' => $this->boolean('join_enabled', true),
            'max_members'  => $this->filled('max_members') ? (int) $this->input('max_members') : null,
        ];
        if ($this->owner) {
            $data['owner_user_id'] = $this->owner->id;
        }

        return $data;
    }
}
