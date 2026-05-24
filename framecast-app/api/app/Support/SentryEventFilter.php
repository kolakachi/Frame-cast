<?php

namespace App\Support;

use Psy\Exception\ErrorException as PsyErrorException;
use Psy\Exception\FatalErrorException as PsyFatalErrorException;
use Psy\Exception\ParseErrorException as PsyParseErrorException;
use Sentry\Event;
use Sentry\EventHint;

class SentryEventFilter
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        $exception = $hint?->exception;

        if (
            $exception instanceof PsyParseErrorException
            || $exception instanceof PsyFatalErrorException
            || $exception instanceof PsyErrorException
        ) {
            return null;
        }

        $argv = $_SERVER['argv'] ?? [];
        $isTinkerCommand = \PHP_SAPI === 'cli'
            && is_array($argv)
            && (($argv[1] ?? null) === 'tinker');

        if ($isTinkerCommand) {
            return null;
        }

        return $event;
    }
}
