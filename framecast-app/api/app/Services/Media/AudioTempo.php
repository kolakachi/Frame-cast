<?php

namespace App\Services\Media;

/**
 * Pitch-preserving tempo adjustment for TTS output.
 *
 * Exists because the voice speed slider was a NO-OP on two of the three
 * engines: neither the Gemini TTS model nor Chatterbox accepts a pace
 * parameter, so the adapters accepted $speed and silently dropped it. A
 * customer cloned their voice, set the slowest speed, and heard no change —
 * their words. ffmpeg's atempo applies the exact requested factor to any
 * engine's output, after the fact, without chipmunking the pitch.
 */
class AudioTempo
{
    /**
     * @param string $bytes  Encoded audio (wav/mp3)
     * @param string $ext    'wav' | 'mp3'
     * @return string        Re-encoded bytes at the new tempo (input returned
     *                       unchanged on any failure — speed must never break
     *                       voice generation)
     */
    public static function apply(string $bytes, string $ext, float $speed): string
    {
        $speed = max(0.25, min(4.0, $speed));
        if (abs($speed - 1.0) < 0.01 || $bytes === '') {
            return $bytes;
        }

        // atempo accepts 0.5–2.0 per instance; chain for factors outside it.
        $filters = [];
        $remaining = $speed;
        while ($remaining < 0.5) { $filters[] = 'atempo=0.5'; $remaining /= 0.5; }
        while ($remaining > 2.0) { $filters[] = 'atempo=2.0'; $remaining /= 2.0; }
        $filters[] = 'atempo='.number_format($remaining, 4, '.', '');

        $in  = tempnam(sys_get_temp_dir(), 'tempo-in-').'.'.$ext;
        $out = tempnam(sys_get_temp_dir(), 'tempo-out-').'.'.$ext;

        try {
            file_put_contents($in, $bytes);
            $cmd = sprintf(
                'ffmpeg -y -v error -i %s -filter:a %s %s',
                escapeshellarg($in),
                escapeshellarg(implode(',', $filters)),
                escapeshellarg($out),
            );
            exec($cmd, $o, $code);
            if ($code === 0 && is_file($out) && filesize($out) > 0) {
                return (string) file_get_contents($out);
            }
            return $bytes;
        } catch (\Throwable) {
            return $bytes;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }
}
