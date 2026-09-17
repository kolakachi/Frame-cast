<?php

namespace Tests\Unit;

use App\Http\Controllers\Concerns\StreamsMedia;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The signed media routes have to answer Range requests with 206. iOS Safari
 * probes an <audio> source with "Range: bytes=0-1" and leaves the element at
 * readyState 0 if the reply is a 200, which is how phone preview playback came
 * to be silent while the desktop was fine.
 */
class MediaRangeTest extends TestCase
{
    private const BODY = 'abcdefghijklmnopqrstuvwxyz';

    private function harness(): object
    {
        return new class
        {
            use StreamsMedia;

            public function run(?string $range, string $body): array
            {
                $stream = fopen('php://memory', 'r+');
                fwrite($stream, $body);
                rewind($stream);

                $request = Request::create('/media/assets/1', 'GET');
                if ($range !== null) {
                    $request->headers->set('Range', $range);
                }

                $response = $this->streamMedia($request, $stream, strlen($body), [
                    'Content-Type' => 'audio/mpeg',
                ]);

                ob_start();
                $response->sendContent();
                $out = ob_get_clean();

                return [$response->getStatusCode(), $response->headers->all(), $out];
            }
        };
    }

    public function test_plain_request_returns_the_whole_body_with_a_length(): void
    {
        [$status, $headers, $body] = $this->harness()->run(null, self::BODY);

        $this->assertSame(200, $status);
        $this->assertSame(self::BODY, $body);
        $this->assertSame('26', $headers['content-length'][0]);
        $this->assertSame('bytes', $headers['accept-ranges'][0]);
        $this->assertArrayNotHasKey('content-range', $headers);
    }

    public function test_safaris_opening_probe_gets_a_206(): void
    {
        [$status, $headers, $body] = $this->harness()->run('bytes=0-1', self::BODY);

        $this->assertSame(206, $status);
        $this->assertSame('ab', $body);
        $this->assertSame('bytes 0-1/26', $headers['content-range'][0]);
        $this->assertSame('2', $headers['content-length'][0]);
    }

    public function test_a_mid_file_range_returns_exactly_those_bytes(): void
    {
        [$status, $headers, $body] = $this->harness()->run('bytes=10-14', self::BODY);

        $this->assertSame(206, $status);
        $this->assertSame('klmno', $body);
        $this->assertSame('bytes 10-14/26', $headers['content-range'][0]);
    }

    public function test_an_open_ended_range_runs_to_the_last_byte(): void
    {
        [$status, $headers, $body] = $this->harness()->run('bytes=20-', self::BODY);

        $this->assertSame(206, $status);
        $this->assertSame('uvwxyz', $body);
        $this->assertSame('bytes 20-25/26', $headers['content-range'][0]);
    }

    public function test_a_suffix_range_returns_the_tail(): void
    {
        [$status, $headers, $body] = $this->harness()->run('bytes=-4', self::BODY);

        $this->assertSame(206, $status);
        $this->assertSame('wxyz', $body);
        $this->assertSame('bytes 22-25/26', $headers['content-range'][0]);
    }

    public function test_an_end_past_the_file_is_clamped(): void
    {
        [$status, $headers, $body] = $this->harness()->run('bytes=24-999', self::BODY);

        $this->assertSame(206, $status);
        $this->assertSame('yz', $body);
        $this->assertSame('bytes 24-25/26', $headers['content-range'][0]);
    }

    public function test_an_unusable_range_falls_back_to_the_whole_body(): void
    {
        foreach (['bytes=abc', 'bytes=-', 'items=0-1', 'bytes=99-120', ''] as $range) {
            [$status, $headers, $body] = $this->harness()->run($range, self::BODY);

            $this->assertSame(200, $status, "range: {$range}");
            $this->assertSame(self::BODY, $body, "range: {$range}");
            $this->assertArrayNotHasKey('content-range', $headers, "range: {$range}");
        }
    }

    public function test_without_a_known_size_it_stops_advertising_range_support(): void
    {
        $h = new class
        {
            use StreamsMedia;

            public function run(): array
            {
                $stream = fopen('php://memory', 'r+');
                fwrite($stream, MediaRangeTest::body());
                rewind($stream);

                $response = $this->streamMedia(Request::create('/m', 'GET'), $stream, null, [
                    'Content-Type' => 'audio/mpeg',
                    'Accept-Ranges' => 'bytes',
                ]);

                ob_start();
                $response->sendContent();

                return [$response->getStatusCode(), $response->headers->all(), ob_get_clean()];
            }
        };

        [$status, $headers, $body] = $h->run();

        $this->assertSame(200, $status);
        $this->assertSame(self::BODY, $body);
        $this->assertArrayNotHasKey('accept-ranges', $headers);
        $this->assertArrayNotHasKey('content-length', $headers);
    }

    public static function body(): string
    {
        return self::BODY;
    }
}
