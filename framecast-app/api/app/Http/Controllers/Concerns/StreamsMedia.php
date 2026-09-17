<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Range-aware streaming for the signed media routes.
 *
 * These routes used to answer every request with 200 and the whole body, while
 * still advertising "Accept-Ranges: bytes" and sending no Content-Length.
 * Desktop Chrome tolerates that; iOS Safari does not — it opens an <audio>
 * element with a "Range: bytes=0-1" probe, and a 200 in reply leaves the
 * element stuck at readyState 0, so preview playback on an iPhone was silent
 * while the timer and captions ran on normally.
 */
trait StreamsMedia
{
    /**
     * @param  resource  $stream
     * @param  array<string, string>  $headers
     */
    protected function streamMedia(Request $request, mixed $stream, ?int $size, array $headers): StreamedResponse
    {
        $range = $size !== null && $size > 0
            ? $this->parseRange((string) $request->header('Range', ''), $size)
            : null;

        if ($size !== null && $size > 0) {
            $headers['Accept-Ranges'] = 'bytes';
        } else {
            // Don't claim range support we can't honour — a client that trusts
            // the header and gets a 200 back is worse off than one that never
            // tried.
            unset($headers['Accept-Ranges']);
        }

        $status = 200;
        $start  = 0;
        $length = $size;

        if ($range !== null) {
            [$start, $end] = $range;
            $length = $end - $start + 1;
            $status = 206;
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        if ($length !== null) {
            $headers['Content-Length'] = (string) $length;
        }

        return response()->stream(function () use ($stream, $start, $length): void {
            if ($start > 0) {
                $this->skip($stream, $start);
            }

            $remaining = $length;
            while (! feof($stream) && ($remaining === null || $remaining > 0)) {
                $chunk = $remaining === null ? 65536 : min(65536, $remaining);
                $buf = fread($stream, $chunk);
                if ($buf === false || $buf === '') {
                    break;
                }
                echo $buf;
                if ($remaining !== null) {
                    $remaining -= strlen($buf);
                }
                flush();
            }

            fclose($stream);
        }, $status, $headers);
    }

    /**
     * Only the single-range form is handled — it's all a media element sends,
     * and a multipart/byteranges reply would need a different body entirely.
     *
     * @return array{0: int, 1: int}|null  [start, end], inclusive
     */
    private function parseRange(string $header, int $size): ?array
    {
        if (! preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
            return null;
        }

        [$from, $to] = [$m[1], $m[2]];

        if ($from === '' && $to === '') {
            return null;
        }

        if ($from === '') {
            // "bytes=-500" — the last 500 bytes.
            $length = (int) $to;
            if ($length <= 0) {
                return null;
            }
            $start = max(0, $size - $length);
            $end   = $size - 1;
        } else {
            $start = (int) $from;
            $end   = $to === '' ? $size - 1 : (int) $to;
        }

        $end = min($end, $size - 1);

        if ($start > $end || $start >= $size) {
            return null;
        }

        return [$start, $end];
    }

    /**
     * Seek where the stream allows it, read past it where it doesn't — an
     * object-store stream is often not seekable.
     *
     * @param  resource  $stream
     */
    private function skip(mixed $stream, int $bytes): void
    {
        if (@fseek($stream, $bytes) === 0) {
            return;
        }

        $left = $bytes;
        while ($left > 0 && ! feof($stream)) {
            $buf = fread($stream, (int) min(65536, $left));
            if ($buf === false || $buf === '') {
                break;
            }
            $left -= strlen($buf);
        }
    }
}
