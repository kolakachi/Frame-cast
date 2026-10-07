<?php

namespace App\Services\Create;

/**
 * From scratch (FS1, FS2): what a video without a reference follows instead of a study. A playbook is a format's
 * proven shape for short vertical video: its beats (each with a job, a share of the runtime, an energy and the least
 * time key information holds), its must-haves and common failures, the systems that suit it, and a variation space of
 * structures, openings and endings so two videos of one format do not come out alike. A motion voice turns the brand's
 * energy and tone into one set of timings for the whole video. Written by us (ideas from OpenMontage, lemo-opuscar and
 * video-shotcraft; nothing copied). From scratch can be anything: a playbook is a starting shape, never a template, and
 * a brief that fits none plans its own ("open").
 */
class FormatPlaybooks
{
    public const PLAYBOOKS = [
        'launch_promo' => [
            'name' => 'Launch or motion promo', 'for' => 'A product, feature or brand announced with kinetic type, UI and motion graphics.',
            'beats' => [
                ['job' => 'Hook: the one promise, big', 'share' => 0.15, 'energy' => 'high', 'hold' => 1.0],
                ['job' => 'The hero: product or idea shown doing its thing', 'share' => 0.3, 'energy' => 'mid', 'hold' => 1.5],
                ['job' => 'Climb: two or three features, alternating UI and phrase', 'share' => 0.35, 'energy' => 'high', 'hold' => 1.0],
                ['job' => 'Peak and lockup: name, line, where to get it', 'share' => 0.2, 'energy' => 'mid', 'hold' => 1.5],
            ],
            'must' => ['One promise in the first 2 s', 'The real product or its real screens', 'One signature move the video is remembered for', 'Name and call to action held at the end'],
            'avoid' => ['A text card per beat on a flat field', 'The same entrance on every element', 'Features listed without showing them'],
            'fits' => ['kinetic type', 'device and browser stages', 'registry CTA lockups and logo stings', 'art library icons and 3D objects', 'counters'],
            'structures' => ['Problem flash, then the product answers it', 'One object transforms through every feature', 'Countdown to the reveal'],
            'openings' => ['Cold open: three quick cuts, then a beat of black', 'A giant word that becomes the product', 'The product mid-action, no setup'],
            'endings' => ['Everything collapses into the logo lockup', 'Smash cut to silence on the name', 'The opening word returns, now answered'],
        ],
        'product_demo' => [
            'name' => 'Product demo', 'for' => 'Showing how a product works: real screens or the product in hand.',
            'beats' => [
                ['job' => 'The outcome first: what you end up with', 'share' => 0.15, 'energy' => 'high', 'hold' => 1.0],
                ['job' => 'Step one on the real screen', 'share' => 0.25, 'energy' => 'mid', 'hold' => 1.5],
                ['job' => 'Step two, the moment it gets easy', 'share' => 0.25, 'energy' => 'mid', 'hold' => 1.5],
                ['job' => 'Result, larger than life', 'share' => 0.2, 'energy' => 'high', 'hold' => 1.0],
                ['job' => 'Call to action', 'share' => 0.15, 'energy' => 'low', 'hold' => 1.5],
            ],
            'must' => ['Real UI or the real product, never grey placeholders', 'A cursor or hand that leads the eye', 'One idea per step'],
            'avoid' => ['Tiny full-screen UI nobody can read', 'Every step at the same zoom', 'Explaining what the screen already shows'],
            'fits' => ['device and browser stages', 'cursor paths', 'zoom-ins on the real capture', 'chat and notification mock-ups'],
            'structures' => ['Outcome, then how', 'Before and after the tool', 'One task, start to finish, in real time feel'],
            'openings' => ['The finished result first', 'The pain point in one line', 'A cursor clicks and the world reacts'],
            'endings' => ['Zoom out to the whole product', 'The result shared or used', 'Back to the opening screen, now done'],
        ],
        'explainer' => [
            'name' => 'Explainer', 'for' => 'Making an idea, mechanism or concept clear.',
            'beats' => [
                ['job' => 'Hook: a surprising question', 'share' => 0.15, 'energy' => 'mid', 'hold' => 1.5],
                ['job' => 'Mechanism: show what happens inside', 'share' => 0.35, 'energy' => 'mid', 'hold' => 2.0],
                ['job' => 'Discovery: name the principle after showing it', 'share' => 0.3, 'energy' => 'low', 'hold' => 2.0],
                ['job' => 'Recap: answer the question in a new context', 'share' => 0.2, 'energy' => 'mid', 'hold' => 1.5],
            ],
            'must' => ['One new idea per beat', 'Show before naming', 'On-screen text adds to the voice, never repeats it'],
            'avoid' => ['Bullet lists', 'Jargon before the picture', 'Reading time too short for the words'],
            'fits' => ['diagrams drawn on', 'iso infographics', 'art library icons', 'counters and charts', 'whiteboard style'],
            'structures' => ['Question, mechanism, answer', 'Myth, then what really happens', 'Zoom from everyday to inside'],
            'openings' => ['A question over an everyday picture', 'A wrong answer, crossed out', 'Zoom into the thing itself'],
            'endings' => ['The first picture, now understood', 'One line the viewer can repeat', 'Zoom back out to everyday'],
        ],
        'ugc_ad' => [
            'name' => 'UGC ad', 'for' => 'A person talking to camera about the product, cut for social.',
            'beats' => [
                ['job' => 'Hook spoken to camera in under 2 s', 'share' => 0.15, 'energy' => 'high', 'hold' => 1.0],
                ['job' => 'The problem they had', 'share' => 0.2, 'energy' => 'mid', 'hold' => 1.5],
                ['job' => 'The product in use, B-roll over the voice', 'share' => 0.35, 'energy' => 'mid', 'hold' => 1.5],
                ['job' => 'Result and proof (real only)', 'share' => 0.15, 'energy' => 'high', 'hold' => 1.5],
                ['job' => 'Call to action from the person', 'share' => 0.15, 'energy' => 'mid', 'hold' => 1.5],
            ],
            'must' => ['A face in the first frame', 'Native-feeling captions', 'Cuts on line breaks, not mid-word'],
            'avoid' => ['Polished brand graphics that break the native feel', 'Fake reviews or numbers', 'A presenter repeated as fake proof'],
            'fits' => ['ugc_take', 'word captions', 'picture-in-picture product shots', 'sticker-style callouts'],
            'structures' => ['Problem, discovery, result', 'Three reasons, counted on fingers', 'Reaction: trying it for the first time'],
            'openings' => ['A bold claim to camera', 'Mid-sentence, already talking', 'Showing the product before saying anything'],
            'endings' => ['Direct ask to camera', 'Product held up to the lens', 'A quick laugh, then the offer'],
        ],
        'testimonial' => [
            'name' => 'Testimonial', 'for' => 'A real customer or founder telling what changed.',
            'beats' => [
                ['job' => 'The result, in their words', 'share' => 0.2, 'energy' => 'mid', 'hold' => 2.0],
                ['job' => 'Before: what it was like', 'share' => 0.25, 'energy' => 'low', 'hold' => 2.0],
                ['job' => 'The turn: when the product came in', 'share' => 0.3, 'energy' => 'mid', 'hold' => 2.0],
                ['job' => 'Who they are and the call to action', 'share' => 0.25, 'energy' => 'low', 'hold' => 2.0],
            ],
            'must' => ['Only real words and real results', 'Name and role on screen', 'Room to breathe between lines'],
            'avoid' => ['Invented quotes or numbers', 'Fast cuts that fight the speaker', 'Stock smiles'],
            'fits' => ['supplied footage', 'quote cards with the real words', 'lower thirds'],
            'structures' => ['Result first, then the story', 'Before and after in their words', 'Three short answers to one question'],
            'openings' => ['Their best line, cold', 'A silent beat on their face', 'The number they achieved (if real)'],
            'endings' => ['Their name over a still', 'A smile and the logo', 'The question the viewer should ask themselves'],
        ],
        'listicle' => [
            'name' => 'Listicle', 'for' => 'N tips, reasons, mistakes or picks.',
            'beats' => [
                ['job' => 'The promise: N things', 'share' => 0.12, 'energy' => 'high', 'hold' => 1.0],
                ['job' => 'Items, each a different visual', 'share' => 0.73, 'energy' => 'high', 'hold' => 1.2],
                ['job' => 'Bonus or call to action', 'share' => 0.15, 'energy' => 'mid', 'hold' => 1.5],
            ],
            'must' => ['A number on screen for every item', 'A different visual per item', 'The best item last or first, on purpose'],
            'avoid' => ['The same card layout for every item', 'Items too long to read'],
            'fits' => ['counters', 'art library icons and 3D objects', 'kinetic type', 'split layouts'],
            'structures' => ['Countdown to the best', 'Mistake, then the fix, per item', 'Speed run: one second each, then the best one slowly'],
            'openings' => ['The number fills the screen', 'The worst mistake first', 'All items flash, then slow down'],
            'endings' => ['Recap grid of every item', 'The bonus item', 'Call to save or follow'],
        ],
        'before_after' => [
            'name' => 'Before and after', 'for' => 'A transformation the product makes.',
            'beats' => [
                ['job' => 'Before, felt not just shown', 'share' => 0.3, 'energy' => 'low', 'hold' => 1.5],
                ['job' => 'The switch: one move flips it', 'share' => 0.15, 'energy' => 'high', 'hold' => 0.8],
                ['job' => 'After, the same frame transformed', 'share' => 0.35, 'energy' => 'mid', 'hold' => 1.5],
                ['job' => 'What did it and the call to action', 'share' => 0.2, 'energy' => 'mid', 'hold' => 1.5],
            ],
            'must' => ['Same framing before and after', 'One clear switch moment', 'Honest results only'],
            'avoid' => ['Different angles that hide the change', 'Exaggerated results'],
            'fits' => ['wipe and split reveals', 'sliders', 'generated worlds for mood', 'the real product'],
            'structures' => ['Before, switch, after', 'Split screen the whole time', 'After first, then rewind to before'],
            'openings' => ['The before at its worst', 'A slider in the middle', 'The after, then a rewind sound'],
            'endings' => ['Both side by side', 'The product in the after frame', 'A second switch, even better'],
        ],
        'tutorial' => [
            'name' => 'Tutorial', 'for' => 'Teaching a task step by step.',
            'beats' => [
                ['job' => 'What you will make or do', 'share' => 0.15, 'energy' => 'mid', 'hold' => 1.5],
                ['job' => 'Steps, numbered, one action each', 'share' => 0.65, 'energy' => 'mid', 'hold' => 2.0],
                ['job' => 'The result and one tip', 'share' => 0.2, 'energy' => 'mid', 'hold' => 1.5],
            ],
            'must' => ['Step numbers on screen', 'Each step shows the action, not just names it', 'Reading time for every instruction'],
            'avoid' => ['More than one instruction per beat', 'Steps that look identical'],
            'fits' => ['device stages', 'cursor paths', 'zooms on the real capture', 'step cards that transform'],
            'structures' => ['Result, steps, result', 'Common mistake, then the right way', 'Speed version, then slow version'],
            'openings' => ['The finished result', 'The question people ask', 'Step one, already started'],
            'endings' => ['The result in use', 'A pro tip', 'Save this for later'],
        ],
        'brand_story' => [
            'name' => 'Brand story', 'for' => 'A short story with a character, a world and a feeling.',
            'beats' => [
                ['job' => 'A world and a character in it', 'share' => 0.25, 'energy' => 'low', 'hold' => 2.0],
                ['job' => 'Something goes wrong or is wanted', 'share' => 0.25, 'energy' => 'mid', 'hold' => 2.0],
                ['job' => 'The product changes the moment', 'share' => 0.3, 'energy' => 'high', 'hold' => 1.5],
                ['job' => 'The feeling, then the brand', 'share' => 0.2, 'energy' => 'low', 'hold' => 2.0],
            ],
            'must' => ['A character with a want', 'Shots of 3 to 6 s so actions land', 'The brand earned at the end, not stamped on'],
            'avoid' => ['A product UI promo in disguise', 'Montage with no story'],
            'fits' => ['generated shots with a reference sheet', 'the 3D mascot', 'music-led timing'],
            'structures' => ['Want, obstacle, help, joy', 'A day in the life, one surprise', 'Two worlds, one bridge'],
            'openings' => ['A detail before the whole', 'Silence, then a sound', 'The character looks at camera'],
            'endings' => ['The character and the logo share the frame', 'A quiet last shot', 'The first shot, changed'],
        ],
        'offer_ad' => [
            'name' => 'Offer ad', 'for' => 'A DTC or shop ad around one offer: a discount, a drop, a bundle.',
            'beats' => [
                ['job' => 'The product, beautifully, in the first frame', 'share' => 0.2, 'energy' => 'mid', 'hold' => 1.0],
                ['job' => 'Why it is wanted: one or two benefits shown', 'share' => 0.35, 'energy' => 'mid', 'hold' => 1.5],
                ['job' => 'The offer, impossible to miss', 'share' => 0.25, 'energy' => 'high', 'hold' => 1.5],
                ['job' => 'Where to get it, and urgency only if real', 'share' => 0.2, 'energy' => 'mid', 'hold' => 1.5],
            ],
            'must' => ['The real product photos', 'The exact offer from the brief, nothing invented', 'Shop or site on the last frame'],
            'avoid' => ['Invented prices, timers or stock counts', 'Benefits as plain text cards'],
            'fits' => ['product cutouts', 'props3d spins', 'price and offer stamps', 'warm generated settings'],
            'structures' => ['Product, benefit, offer', 'Unboxing feel', 'The offer first, then why it is worth it'],
            'openings' => ['The product lands in frame', 'A close detail, pull back', 'The offer stamp, then the product'],
            'endings' => ['Offer and product locked together', 'Shop name with a gentle push', 'The product in a real-life moment'],
        ],
    ];

    public const MOTION_VOICES = [
        'calm_serious' => ['for' => 'finance, health, enterprise, trust', 'enter_s' => 0.7, 'ease' => 'power2.out', 'overshoot' => 1.0, 'stagger_s' => 0.08, 'hold_s' => 1.5],
        'calm_playful' => ['for' => 'lifestyle, wellness, kids, soft brands', 'enter_s' => 0.6, 'ease' => 'sine.out', 'overshoot' => 1.05, 'stagger_s' => 0.07, 'hold_s' => 1.2],
        'steady_serious' => ['for' => 'B2B SaaS, productivity, education', 'enter_s' => 0.5, 'ease' => 'power3.out', 'overshoot' => 1.0, 'stagger_s' => 0.06, 'hold_s' => 1.0],
        'steady_playful' => ['for' => 'consumer apps, food, everyday DTC', 'enter_s' => 0.45, 'ease' => 'back.out(1.4)', 'overshoot' => 1.08, 'stagger_s' => 0.05, 'hold_s' => 1.0],
        'bold_serious' => ['for' => 'launches, tech, sport, premium', 'enter_s' => 0.35, 'ease' => 'expo.out', 'overshoot' => 1.0, 'stagger_s' => 0.04, 'hold_s' => 0.8],
        'bold_playful' => ['for' => 'startups, games, creator tools, fashion drops', 'enter_s' => 0.3, 'ease' => 'back.out(1.8)', 'overshoot' => 1.12, 'stagger_s' => 0.035, 'hold_s' => 0.7],
    ];

    /** What the planner reads: each playbook without its long lists, and the motion voices. */
    public static function catalogue(): array
    {
        return [
            'playbooks' => array_map(fn ($id, $p) => ['id' => $id, 'name' => $p['name'], 'for' => $p['for'], 'beats' => array_column($p['beats'], 'job'),
                'structures' => $p['structures'], 'openings' => $p['openings'], 'endings' => $p['endings']], array_keys(self::PLAYBOOKS), self::PLAYBOOKS),
            'motion_voices' => array_map(fn ($id, $v) => ['id' => $id, 'for' => $v['for']], array_keys(self::MOTION_VOICES), self::MOTION_VOICES),
        ];
    }

    /** The planner's from-scratch choices, checked; the chosen playbook and voice attached in full for the build. */
    public static function normalize(array $raw, bool $fromScratch): array
    {
        $str = fn ($v, $n) => is_string($v) ? mb_substr(trim($v), 0, $n) : '';
        $id = $str($raw['playbook'] ?? '', 40);
        $voice = $str($raw['motion_voice']['id'] ?? ($raw['motion_voice'] ?? ''), 40);
        $out = [];
        if (isset(self::PLAYBOOKS[$id])) $out['playbook'] = ['id' => $id] + self::PLAYBOOKS[$id];
        elseif ($id === 'open') $out['playbook'] = ['id' => 'open', 'name' => 'Own structure', 'for' => 'A brief no playbook fits'];
        if (isset(self::MOTION_VOICES[$voice])) $out['motion_voice'] = ['id' => $voice, 'why' => $str($raw['motion_voice']['why'] ?? '', 140)] + self::MOTION_VOICES[$voice];
        $c = is_array($raw['concept'] ?? null) ? $raw['concept'] : [];
        $one = fn ($d) => is_array($d) ? array_filter(['name' => $str($d['name'] ?? '', 60), 'idea' => $str($d['idea'] ?? '', 160), 'hook' => $str($d['hook'] ?? '', 120),
            'look' => $str($d['look'] ?? '', 120), 'structure' => $str($d['structure'] ?? '', 120), 'opening' => $str($d['opening'] ?? '', 120), 'ending' => $str($d['ending'] ?? '', 120)]) : [];
        if ($fromScratch && ($chosen = $one($c)) && isset($chosen['idea'])) {
            $out['concept'] = $chosen + ['why' => $str($c['why'] ?? '', 160),
                'alternatives' => array_values(array_filter(array_map($one, array_slice((array) ($c['alternatives'] ?? []), 0, 2)), fn ($a) => isset($a['idea'])))];
        }
        return $out;
    }

    /** The build's pinned guide for the chosen playbook and motion voice. */
    public static function guide(array $plan): string
    {
        $out = '';
        if (($p = $plan['playbook'] ?? null) && isset($p['beats'])) {
            $out .= "\n\n# Format playbook: {$p['name']} (from the approved plan; a starting shape, not a template)\nBeats, as shares of the runtime, with energy and the least time key information holds:\n";
            foreach ($p['beats'] as $b) $out .= '- '.$b['job'].' · '.round($b['share'] * 100).'% · energy '.$b['energy'].' · hold at least '.$b['hold']." s\n";
            $out .= 'Must: '.implode('; ', $p['must']).".\nAvoid: ".implode('; ', $p['avoid']).".\nSuits: ".implode(', ', $p['fits']).'.';
        }
        if ($c = $plan['concept'] ?? null) $out .= "\n\n# Concept (approved)\n".$c['idea'].(isset($c['structure']) ? "\nStructure: ".$c['structure'] : '').(isset($c['opening']) ? "\nOpening: ".$c['opening'] : '').(isset($c['ending']) ? "\nEnding: ".$c['ending'] : '');
        if ($v = $plan['motion_voice'] ?? null) $out .= "\n\n# Motion voice: {$v['id']} (one voice for the whole video)\nEntrances about {$v['enter_s']} s with {$v['ease']}; overshoot up to {$v['overshoot']}; stagger {$v['stagger_s']} s; key information holds at least {$v['hold_s']} s. Vary the moves, keep the timing voice. Nothing moves at constant linear speed: accelerate in, settle, hold.";
        return $out;
    }
}
