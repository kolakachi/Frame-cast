<?php
namespace App\Services\Create;

use Illuminate\Support\Facades\Cache;

/**
 * What planning did, step by step, as it happens: "Watched reference-ugc.mp4" with "9 shots over 15 s" under it.
 * The steps are readable live while the plan is being made (the conversation shows the current one) and are saved
 * with the plan, where they read as the turn's activity ("Worked for 1m 12s", then the steps folded into one line).
 */
class PlanActivity
{
    private array $steps = [];
    private float $start;

    public function __construct(private string $conversationId)
    {
        $this->start = microtime(true);
        $this->flush();
    }

    /** The recorder of the plan being made in this request, if any. */
    public static function current(): ?self
    {
        return app()->bound(self::class) ? app(self::class) : null;
    }

    public static function begin(string $conversationId): self
    {
        $activity = new self($conversationId);
        app()->instance(self::class, $activity);
        return $activity;
    }

    /** The live state for the conversation: {started_at, steps: [{label, items}], running}. */
    public static function live(string $conversationId): ?array
    {
        return Cache::get(self::key($conversationId));
    }

    public function step(string $label): void
    {
        $this->close();
        $this->steps[] = ['label' => mb_substr(trim($label), 0, 120), 'items' => [], 'started' => microtime(true)];
        $this->flush();
    }

    public function item(string $text): void
    {
        $text = mb_substr(trim($text), 0, 160);
        if ($text === '' || ! $this->steps) return;
        $last = count($this->steps) - 1;
        if (in_array($text, $this->steps[$last]['items'], true) || count($this->steps[$last]['items']) >= 8) return;
        $this->steps[$last]['items'][] = $text;
        $this->flush();
    }

    /** Renames the step in progress, once its outcome is known ("Planning" becomes "Planned 5 shots"). */
    public function relabel(string $label): void
    {
        if ($this->steps) { $this->steps[count($this->steps) - 1]['label'] = mb_substr(trim($label), 0, 120); $this->flush(); }
    }

    /** Stops recording; returns what is saved with the plan. */
    public function finish(): array
    {
        $this->close();
        Cache::put(self::key($this->conversationId), [...$this->state(), 'running' => false], 120);
        if (app()->bound(self::class) && app(self::class) === $this) app()->forgetInstance(self::class);
        return ['worked_ms' => (int) round((microtime(true) - $this->start) * 1000),
            'steps' => array_map(fn ($s) => ['label' => $s['label'], 'items' => $s['items'], 'ms' => $s['ms'] ?? 0], $this->steps)];
    }

    /** Planning failed: the live state ends so the conversation stops showing it. */
    public function abandon(): void
    {
        Cache::forget(self::key($this->conversationId));
        if (app()->bound(self::class) && app(self::class) === $this) app()->forgetInstance(self::class);
    }

    private function close(): void
    {
        if (! $this->steps) return;
        $last = count($this->steps) - 1;
        $this->steps[$last]['ms'] ??= (int) round((microtime(true) - $this->steps[$last]['started']) * 1000);
    }

    private function state(): array
    {
        return ['started_at' => (int) round($this->start * 1000), 'steps' => array_map(fn ($s) => ['label' => $s['label'], 'items' => $s['items']], $this->steps)];
    }

    private function flush(): void
    {
        try { Cache::put(self::key($this->conversationId), [...$this->state(), 'running' => true], 900); } catch (\Throwable) { /* the plan never waits on this */ }
    }

    private static function key(string $conversationId): string
    {
        return 'create:plan-activity:'.$conversationId;
    }
}
