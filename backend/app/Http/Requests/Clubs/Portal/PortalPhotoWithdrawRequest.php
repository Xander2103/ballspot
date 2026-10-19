<?php

namespace App\Http\Requests\Clubs\Portal;

/** Withdraw a pending club upload (no input). */
class PortalPhotoWithdrawRequest extends PortalRequest
{
    protected string $ability = 'uploadPhotos';

    public function rules(): array
    {
        return [];
    }
}
