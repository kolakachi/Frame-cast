<?php

namespace App\Events;

use App\Models\Project;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class GenerationProgressed implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    private const REDIS_TTL = 86400; // 24 hours

    public function __construct(
        public readonly int $projectId,
        public readonly string $stage,
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly array $meta = [],
    ) {
        $this->recordProgress();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('project.'.$this->projectId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'generation.progress';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'project_id' => $this->projectId,
            'stage'      => $this->stage,
            'status'     => $this->status,
            'message'    => $this->message,
            ...$this->meta,
        ];
    }

    /**
     * Read the latest progress for a project.
     * Redis is the source of truth while generating; DB is the cold fallback.
     *
     * @return array<string, mixed>
     */
    public static function getProgress(int $projectId): array
    {
        $key = self::redisKey($projectId);

        rescue(function () use ($key, &$fromRedis): void {
            $raw = Redis::get($key);
            if ($raw) {
                $fromRedis = json_decode($raw, true) ?? null;
            }
        });

        if (! empty($fromRedis)) {
            return $fromRedis;
        }

        // Cold fallback: load from DB (page refresh before any event fires)
        $project = Project::query()->select('generation_status_json')->find($projectId);

        return is_array($project?->generation_status_json) ? $project->generation_status_json : [];
    }

    private function recordProgress(): void
    {
        rescue(function (): void {
            $key     = self::redisKey($this->projectId);
            $current = [];

            rescue(function () use ($key, &$current): void {
                $raw = Redis::get($key);
                if ($raw) {
                    $current = json_decode($raw, true) ?? [];
                }
            });

            $stages          = is_array($current['stages'] ?? null) ? $current['stages'] : [];
            $stageEntry      = $stages[$this->stage] ?? [];

            $stageEntry['status']     = $this->status;
            $stageEntry['updated_at'] = now()->toIso8601String();

            if ($this->message !== null) {
                $stageEntry['message'] = $this->message;
            }

            // Persist done/total counts from meta so page-refresh can restore them.
            if (isset($this->meta['done']))  $stageEntry['done']  = $this->meta['done'];
            if (isset($this->meta['total'])) $stageEntry['total'] = $this->meta['total'];

            $stages[$this->stage] = $stageEntry;

            $payload = [
                ...$current,
                'current_stage'  => $this->stage,
                'current_status' => $this->status,
                'last_message'   => $this->message,
                'stages'         => $stages,
            ];

            // Always write to Redis (fast, no lock contention).
            rescue(fn () => Redis::setex($key, self::REDIS_TTL, json_encode($payload)));

            // Write to DB only on stage completion/failure — not on every per-scene ping.
            if (in_array($this->status, ['completed', 'failed'], true)) {
                $project = Project::query()->find($this->projectId);
                $project?->forceFill(['generation_status_json' => $payload])->save();
            }
        });
    }

    private static function redisKey(int $projectId): string
    {
        return "gen:progress:{$projectId}";
    }
}
