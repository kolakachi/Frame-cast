<?php

namespace App\Services\Generation\TTS;

/** The voice engine's safety filter refused the line. Retrying the same words fails the same way. */
class TtsRefused extends \RuntimeException
{
    public static function matches(\Throwable $e): bool
    {
        $m = $e->getMessage();
        return str_contains($m, '(E005)') || stripos($m, 'flagged as sensitive') !== false;
    }
}
