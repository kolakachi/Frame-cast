<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\MakeCharacterSketch;
use App\Models\Character;
use App\Services\Ugc\CharacterSketch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Our stock presenters' pencil sheets (owner, 2026-10-19): admin sees each presenter's photo beside the sheet Seedance
 * gets, and can upload one, draw one again, or draw every missing one. Customers' own characters are not listed: theirs
 * are drawn on first use.
 */
class AdminPresenterController extends Controller
{
    public function __construct(private readonly CharacterSketch $sketch)
    {
    }

    public function index(): JsonResponse
    {
        $presenters = Character::query()->where('is_stock', true)->where('status', 'active')->orderBy('name')->get();
        $sheets = DB::table('character_sketches')->whereIn('character_id', $presenters->pluck('id'))->get()->groupBy('character_id');
        $rows = $presenters->map(function (Character $c) use ($sheets) {
            $sheet = collect($sheets->get($c->id, []))->firstWhere('from_asset_id', (int) $c->reference_asset_id);
            return [
                'id' => $c->id, 'name' => $c->name, 'description' => mb_substr((string) $c->description, 0, 160), 'gender' => $c->gender,
                'photo_url' => $c->reference_asset_id ? URL::temporarySignedRoute('media.assets.content', now()->addHours(2), ['assetId' => $c->reference_asset_id]) : null,
                'sheet' => $sheet ? ['url' => URL::temporarySignedRoute('media.character-sketch', now()->addHours(2), ['characterId' => $c->id, 'v' => strtotime((string) $sheet->updated_at)]),
                    'source' => $sheet->source ?? 'drawn', 'made_at' => $sheet->updated_at] : null,
            ];
        })->values();

        return response()->json(['data' => ['presenters' => $rows, 'missing' => $rows->whereNull('sheet')->count(), 'cost_each_usd' => 0.30], 'meta' => []]);
    }

    /** Draw the sheet again from the photo, in the background (about a minute). */
    public function remake(Request $request, int $characterId): JsonResponse
    {
        $character = $this->stock($characterId);
        MakeCharacterSketch::dispatch($character->id, true, (int) $request->user()->id);

        return response()->json(['data' => ['queued' => 1], 'meta' => []], 202);
    }

    /** Draw a sheet for every stock presenter who has none for their current photo. */
    public function makeMissing(): JsonResponse
    {
        $have = DB::table('character_sketches')->get(['character_id', 'from_asset_id'])->map(fn ($r) => $r->character_id.':'.$r->from_asset_id)->all();
        $queued = 0;
        foreach (Character::query()->where('is_stock', true)->where('status', 'active')->whereNotNull('reference_asset_id')->get() as $c) {
            if (in_array($c->id.':'.$c->reference_asset_id, $have, true)) continue;
            MakeCharacterSketch::dispatch($c->id);
            $queued++;
        }

        return response()->json(['data' => ['queued' => $queued], 'meta' => []], 202);
    }

    /** A sheet admin made elsewhere replaces the drawn one. */
    public function upload(Request $request, int $characterId): JsonResponse
    {
        $character = $this->stock($characterId);
        $request->validate(['sheet' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:10240']]);
        $file = $request->file('sheet');
        $extension = match (strtolower((string) $file->getClientOriginalExtension())) { 'jpg', 'jpeg' => 'jpg', 'webp' => 'webp', default => 'png' };
        $this->sketch->upload($character, (string) file_get_contents($file->getRealPath()), $extension, (int) $request->user()->id);

        return $this->index();
    }

    /** The current sheet's image, behind a signed link (an <img> cannot send the admin's token). */
    public function content(int $characterId)
    {
        $character = Character::query()->findOrFail($characterId);
        [$bytes] = $this->sketch->current($character) ?? abort(404);

        return response($bytes, 200, ['Content-Type' => CharacterSketch::mime($bytes), 'Cache-Control' => 'private, max-age=86400']);
    }

    private function stock(int $characterId): Character
    {
        $character = Character::query()->whereKey($characterId)->where('is_stock', true)->first();
        abort_unless($character && $character->reference_asset_id, 404, 'Only our stock presenters with a photo have sheets here.');

        return $character;
    }
}
