<?php

namespace App\Services;

/** A share claim that cannot be honoured (e.g. daily result without a guess). Carries a stable code. */
class ShareRewardException extends \RuntimeException
{
    public function __construct(public readonly string $code_)
    {
        parent::__construct($code_);
    }
}
