<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Character;
use App\Services\CreditService;
use App\Services\Generation\Image\ImageAdapterFactory;
use App\Services\Media\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Build the shared stock-actor library.
 *
 * These belong to no workspace: workspace_id is null and is_stock is true, so
 * every customer can pick them and none of them counts against a plan's
 * character limit.
 *
 * Every actor is synthesised from a text prompt and depicts no real person.
 * That is the point, not an implementation detail — a customer attests to
 * holding the rights to a likeness before a face is made to speak, and for
 * stock actors WyvStudio is the one making that claim. Stock photographs are
 * deliberately not used as input: Pexels and Pixabay are generous licences but
 * both restrict images of identifiable people being used to imply endorsement,
 * which is exactly what a UGC ad does. Reference photography informs the
 * written concept and never reaches the model.
 *
 * Generation is billed to the workspace given by --workspace (default 1): the
 * company pays for its own library, and the spend appears in the ledger like
 * any other.
 */
class SeedStockActorsCommand extends Command
{
    protected $signature = 'ugc:seed-stock-actors
        {--workspace=1 : Workspace billed for the generations}
        {--set=all : Which casting set: everyday, polished, or all}
        {--limit=0 : Only create this many (0 = all missing)}
        {--dry : Show what would be created without generating}';

    protected $description = 'Create the shared, model-generated stock actor library for UGC ads';

    /**
     * Weighted to where direct-response ad creative actually lives, rather than
     * spread evenly across a filter grid nobody queries.
     *
     * @var list<array{name:string,gender:string,age_group:string,situations:list<string>,look:string,setting:string}>
     */
    private const EVERYDAY = [
        ['name' => 'Nadia',  'gender' => 'female', 'age_group' => 'young_adult', 'situations' => ['kitchen', 'coffee shop'],
         'look' => 'woman in her late twenties, warm brown skin, dark curly hair loosely tied back, minimal makeup, cream ribbed jumper',
         'setting' => 'a small sunlit kitchen, mug on the counter, soft morning light through a window'],
        ['name' => 'Erin',   'gender' => 'female', 'age_group' => 'young_adult', 'situations' => ['gym', 'outdoors'],
         'look' => 'athletic woman in her mid twenties, freckled fair skin, blonde hair in a high ponytail, grey sports top',
         'setting' => 'the corner of a quiet gym, weights rack blurred behind, cool daylight'],
        ['name' => 'Priya',  'gender' => 'female', 'age_group' => 'adult',       'situations' => ['office', 'coffee shop'],
         'look' => 'woman in her mid thirties, South Asian, straight black shoulder-length hair, navy blazer over a plain tee',
         'setting' => 'a home office, bookshelf softly out of focus, warm lamp light'],
        ['name' => 'Sofia',  'gender' => 'female', 'age_group' => 'adult',       'situations' => ['living room', 'bathroom'],
         'look' => 'woman in her late thirties, olive skin, dark hair in a relaxed bun, oversized linen shirt',
         'setting' => 'a bright living room, plants and a sofa arm just in frame'],
        ['name' => 'Grace',  'gender' => 'female', 'age_group' => 'senior',      'situations' => ['kitchen', 'living room'],
         'look' => 'woman in her sixties, silver bob, fair skin with natural lines, soft knitted cardigan',
         'setting' => 'a homely kitchen, kettle and tiled splashback behind, warm even light'],
        ['name' => 'Amara',  'gender' => 'female', 'age_group' => 'young_adult', 'situations' => ['car', 'outdoors'],
         'look' => 'woman in her late twenties, deep brown skin, short natural hair, gold hoop earrings, denim jacket',
         'setting' => 'the drivers seat of a parked car, seatbelt on, daylight through the windscreen'],

        ['name' => 'Marcus', 'gender' => 'male',   'age_group' => 'adult',       'situations' => ['office', 'living room'],
         'look' => 'man in his late thirties, Mediterranean, short dark hair, neatly trimmed beard, heather-grey tee under a navy overshirt',
         'setting' => 'a home office, warm lamp and a shelf of books behind, evening light'],
        ['name' => 'Tobi',   'gender' => 'male',   'age_group' => 'young_adult', 'situations' => ['gym', 'outdoors'],
         'look' => 'man in his mid twenties, dark brown skin, close-cropped hair, black training top',
         'setting' => 'outside a gym entrance, morning light, blurred street behind'],
        ['name' => 'Callum', 'gender' => 'male',   'age_group' => 'young_adult', 'situations' => ['kitchen', 'coffee shop'],
         'look' => 'man in his mid twenties, pale skin, messy light brown hair, plain white tee',
         'setting' => 'a small flat kitchen, coffee pot on the hob, daylight from the left'],
        ['name' => 'Deven',  'gender' => 'male',   'age_group' => 'adult',       'situations' => ['car', 'office'],
         'look' => 'man in his early forties, South Asian, greying at the temples, glasses, dark polo shirt',
         'setting' => 'the drivers seat of a parked car, city street soft behind the glass'],
        ['name' => 'Walter', 'gender' => 'male',   'age_group' => 'senior',      'situations' => ['living room', 'outdoors'],
         'look' => 'man in his late sixties, white beard, weathered fair skin, checked flannel shirt',
         'setting' => 'a comfortable living room armchair, window light from one side'],
        ['name' => 'Ben',    'gender' => 'male',   'age_group' => 'adult',       'situations' => ['bathroom', 'living room'],
         'look' => 'man in his mid thirties, tanned skin, stubble, dark curly hair, plain navy tee',
         'setting' => 'a clean bathroom, mirror and tiled wall behind, soft even light'],
    ];


    /**
     * A second casting set: polished, aspirational presenters for beauty,
     * fashion, fitness and luxury, where the everyday set reads as wrong for
     * the product.
     *
     * Styled and attractive, deliberately not sexualised — Meta and TikTok both
     * reject ads on that basis, so a presenter that gets a customer's campaign
     * refused is worse than no presenter. Fully clothed, confident rather than
     * suggestive.
     *
     * @var list<array{name:string,gender:string,age_group:string,situations:list<string>,look:string,setting:string}>
     */
    private const POLISHED = [
        ['name' => 'Yara',   'gender' => 'female', 'age_group' => 'young_adult', 'situations' => ['beauty', 'bathroom'],
         'look' => 'strikingly attractive woman in her mid twenties, Middle Eastern, glossy dark waves, flawless dewy makeup, silk camisole and delicate gold necklace',
         'setting' => 'a vanity mirror ringed with warm bulbs, tidy makeup brushes in frame'],
        ['name' => 'Sienna', 'gender' => 'female', 'age_group' => 'young_adult', 'situations' => ['fashion', 'luxury'],
         'look' => 'model-featured woman in her late twenties, fair skin, blonde balayage, sculpted brows, camel wool coat over a white tee',
         'setting' => 'a marble hotel lobby, soft light, brass and glass behind her'],
        ['name' => 'Nia',    'gender' => 'female', 'age_group' => 'adult',       'situations' => ['fashion', 'outdoors'],
         'look' => 'glamorous woman in her early thirties, deep brown skin, sleek jaw-length bob, bold matte lip, gold hoops and a tailored blazer',
         'setting' => 'a city rooftop at golden hour, skyline soft behind her'],
        ['name' => 'Camila', 'gender' => 'female', 'age_group' => 'adult',       'situations' => ['fitness', 'gym'],
         'look' => 'toned athletic woman in her early thirties, Latina, long dark ponytail, sculpted shoulders, fitted sage athleisure set',
         'setting' => 'a bright modern fitness studio, mirrored wall and daylight'],
        ['name' => 'Mei',    'gender' => 'female', 'age_group' => 'young_adult', 'situations' => ['beauty', 'bathroom'],
         'look' => 'radiantly pretty woman in her mid twenties, East Asian, glass-skin complexion, minimal makeup, silk robe over a white top',
         'setting' => 'a clean white bathroom vanity, skincare bottles neatly arranged'],
        ['name' => 'Aria',   'gender' => 'female', 'age_group' => 'adult',       'situations' => ['restaurant', 'luxury'],
         'look' => 'head-turning woman in her mid thirties, mixed heritage, voluminous dark curls, statement earrings, emerald satin blouse',
         'setting' => 'a candlelit restaurant table, warm bokeh behind'],
        ['name' => 'Isla',   'gender' => 'female', 'age_group' => 'adult',       'situations' => ['luxury', 'car'],
         'look' => 'elegant woman in her early forties, silver-blonde sleek hair, refined features, cream tailored blazer and pearl studs',
         'setting' => 'the drivers seat of a premium car, leather interior, evening light'],

        ['name' => 'Zayn',   'gender' => 'male',   'age_group' => 'young_adult', 'situations' => ['fitness', 'gym'],
         'look' => 'very handsome man in his mid twenties, South Asian, sharp jawline, styled dark hair, fitted black training tee over an athletic build',
         'setting' => 'a modern gym floor, equipment blurred behind, cool daylight'],
        ['name' => 'Theo',   'gender' => 'male',   'age_group' => 'adult',       'situations' => ['restaurant', 'luxury'],
         'look' => 'leading-man handsome in his mid thirties, fair skin, groomed stubble, swept brown hair, crisp white shirt with the collar open',
         'setting' => 'a rooftop bar at dusk, warm string lights behind'],
        ['name' => 'Andre',  'gender' => 'male',   'age_group' => 'adult',       'situations' => ['grooming', 'salon'],
         'look' => 'striking man in his early thirties, dark brown skin, precise fade and lined beard, slim gold chain, charcoal crew neck',
         'setting' => 'a stylish barbershop chair, mirrors and warm bulbs behind'],
        ['name' => 'Rafa',   'gender' => 'male',   'age_group' => 'young_adult', 'situations' => ['outdoors', 'travel'],
         'look' => 'good-looking man in his late twenties, Latino, tousled dark hair, sun-warmed skin, open linen shirt over a plain tee',
         'setting' => 'a sunlit poolside terrace, palms and bright sky behind'],
        ['name' => 'Lucas',  'gender' => 'male',   'age_group' => 'adult',       'situations' => ['luxury', 'office'],
         'look' => 'distinguished man in his mid forties, Mediterranean, silver at the temples, strong features, navy suit without a tie',
         'setting' => 'a hotel suite window, city view soft behind him'],
    ];

    public function handle(ImageAdapterFactory $factory, StorageService $storage, CreditService $credits): int
    {
        $workspaceId = (int) $this->option('workspace');
        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry');

        $cost = $factory->referenceGenerationCost(null);
        $set = (string) $this->option('set');
        $catalogue = match ($set) {
            'everyday' => self::EVERYDAY,
            'polished' => self::POLISHED,
            'all'      => array_merge(self::EVERYDAY, self::POLISHED),
            default    => null,
        };
        if ($catalogue === null) {
            $this->error("Unknown set '{$set}'. Use everyday, polished or all.");

            return self::FAILURE;
        }

        // map() passes the key as the second argument, which mb_strtolower
        // reads as an encoding — harmless while the library was empty and
        // fatal the moment it was not.
        $existing = Character::query()->where('is_stock', true)->pluck('name')
            ->map(fn ($n) => mb_strtolower((string) $n))->all();
        $todo = array_values(array_filter(
            $catalogue,
            fn (array $a): bool => ! in_array(mb_strtolower($a['name']), $existing, true),
        ));
        if ($limit > 0) {
            $todo = array_slice($todo, 0, $limit);
        }

        if ($todo === []) {
            $this->info('Stock library already complete — nothing to create.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d actor(s) to create, %d credits each (%d total), billed to workspace %d.',
            count($todo), $cost, $cost * count($todo), $workspaceId));

        if ($dry) {
            foreach ($todo as $a) {
                $this->line(sprintf('  %-8s %-7s %-12s %s', $a['name'], $a['gender'], $a['age_group'], implode(', ', $a['situations'])));
            }

            return self::SUCCESS;
        }

        $balance = $credits->balance($workspaceId);
        if ($balance < $cost * count($todo)) {
            $this->error("Workspace {$workspaceId} has {$balance} credits; this run needs ".($cost * count($todo)).'.');

            return self::FAILURE;
        }

        $made = 0;
        foreach ($todo as $spec) {
            $this->line("· {$spec['name']}…");

            // No reference image: the person is synthesised from words alone,
            // which is what keeps the library free of any real likeness.
            // Framing is stated as a proportion, not as "leave space": asked
            // loosely, the model reads "medium close-up" as dominant and crops
            // the hair at the top edge. Headlines render at top_ratio 0.10
            // (UgcHeadline), so anything tighter puts text across a forehead.
            //
            // Expression is directed too. Left unsaid, these come back with the
            // flat stare of a passport photo, which is the wrong first frame
            // for an ad — the person should look like they are about to tell
            // you something they are pleased about.
            $prompt = sprintf(
                'Authentic phone-recorded UGC selfie. %s. Filmed in %s. '
                .'FRAMING: waist-up, head occupying the middle third of a vertical frame, '
                .'with the top fifth of the image clear empty space above the head — do not crop the hair. '
                .'Eye-level, looking into the lens, natural light, casual and lived-in. '
                .'EXPRESSION: relaxed and warm, a slight genuine smile, mid-sentence as if talking to a friend. '
                .'Ordinary believable person, not a model. '
                .'No beauty filter, no studio advertising look, no text, no logos, no watermarks.',
                $spec['look'],
                $spec['setting'],
            );

            try {
                $result = $factory->resolve(null)->generate($prompt, 'photorealistic', '9:16', []);
                $bytes = ! empty($result['image_b64'])
                    ? base64_decode($result['image_b64'], true)
                    : (! empty($result['image_url']) ? Http::timeout(60)->get($result['image_url'])->body() : null);

                if (! $bytes) {
                    $this->error("  no image returned for {$spec['name']}");
                    continue;
                }

                $character = DB::transaction(function () use ($spec, $bytes, $storage, $workspaceId) {
                    $path = 'stock-actors/'.mb_strtolower($spec['name']).'-'.bin2hex(random_bytes(4)).'.png';
                    $storage->put($path, $bytes, ['ContentType' => 'image/png']);

                    $asset = Asset::query()->create([
                        // The asset belongs to the company workspace; the
                        // character it backs belongs to no one.
                        'workspace_id' => $workspaceId,
                        'asset_type'   => 'image',
                        'title'        => "Stock actor — {$spec['name']}",
                        'storage_url'  => 'minio://'.$path,
                        'mime_type'    => 'image/png',
                        'tags'         => ['stock_actor', 'ai_generated'],
                    ]);

                    return Character::query()->create([
                        'workspace_id'       => null,
                        'name'               => $spec['name'],
                        'description'        => $spec['look'],
                        'gender'             => $spec['gender'],
                        'age_group'          => $spec['age_group'],
                        'situations'         => $spec['situations'],
                        'is_stock'           => true,
                        'is_auto'            => false,
                        'provenance'         => 'ai_generated',
                        'status'             => 'active',
                        'consistency_method' => 'description',
                        'identity_strength'  => 'balanced',
                        'reference_asset_id' => $asset->getKey(),
                        'reference_asset_ids' => [$asset->getKey()],
                    ]);
                });

                $credits->deduct($workspaceId, $cost, 'stock_actor:image', [
                    'character_id' => $character->getKey(),
                ]);

                $made++;
                $this->info("  created #{$character->getKey()}");
            } catch (\Throwable $e) {
                $this->error('  '.mb_substr($e->getMessage(), 0, 120));
            }
        }

        $this->info("Done: {$made} stock actor(s) created.");

        return self::SUCCESS;
    }
}
