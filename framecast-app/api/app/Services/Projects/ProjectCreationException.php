<?php

namespace App\Services\Projects;

use RuntimeException;

/**
 * A project could not be created. Carries the error code, HTTP status and
 * optional context that the API envelope needs, so the dashboard and the
 * developer API report the same failure the same way.
 */
class ProjectCreationException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $context = [],
        public readonly bool $isLimit = false,
    ) {
        parent::__construct($message, $status);
    }

    /** A plan/usage limit: the envelope carries limit_context, as Controller::limitError does. */
    public static function limit(string $code, string $message, array $context, int $status = 422): self
    {
        return new self($code, $message, $status, $context, true);
    }
}
