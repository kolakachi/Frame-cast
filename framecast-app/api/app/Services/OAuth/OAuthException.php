<?php

namespace App\Services\OAuth;

use RuntimeException;

/** An OAuth error in RFC 6749 §5.2 shape: `error` and `error_description`. */
class OAuthException extends RuntimeException
{
    public function __construct(public readonly string $error, string $description, public readonly int $status = 400)
    {
        parent::__construct($description, $status);
    }
}
