<?php

namespace App\Jobs;

use App\Models\Character;
use App\Services\Ugc\CharacterSketch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** A presenter's pencil sheet drawn in the background from admin (Remake, Make all missing), about a minute and $0.30 each. */
class MakeCharacterSketch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 300;

    public $tries = 1;

    public function __construct(public readonly int $characterId, public readonly bool $remake = false, public readonly ?int $userId = null)
    {
    }

    public function handle(CharacterSketch $sketch): void
    {
        $character = Character::query()->find($this->characterId);
        if (! $character) return;
        $this->remake ? $sketch->remake($character, $this->userId) : $sketch->uri($character);
    }
}
