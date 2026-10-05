<?php
namespace App\Services\Create;

use Illuminate\Support\Sleep;

/**
 * A request that never reached the provider (the host did not resolve, the connection was refused or timed out before
 * connecting) is safe to send again. Network drops on this stack have lasted up to a minute, so such a request is
 * tried again over about a minute before it fails. Anything that may have reached the provider is never repeated here.
 */
class NetRetry
{
    public const WAITS = [2, 4, 8, 16, 30];

    public static function notSent(\Throwable $e): bool
    {
        return (bool) preg_match('/Failed to connect|Could not resolve|Connection refused|Couldn.t connect|Resolving timed out|cURL error (6|7):/i', $e->getMessage());
    }

    /** Runs $call; when it never reached the provider it is run again after each wait. Reads (polls, downloads) may pass $reads = true to retry any connection error. */
    public static function run(callable $call, bool $reads = false): mixed
    {
        foreach ([...self::WAITS, null] as $wait) {
            try { return $call(); }
            catch (\Illuminate\Http\Client\ConnectionException $e) {
                if ($wait === null || (! $reads && ! self::notSent($e))) throw $e;
                Sleep::for($wait)->seconds();
            }
        }
        throw new \LogicException('unreachable');
    }
}
