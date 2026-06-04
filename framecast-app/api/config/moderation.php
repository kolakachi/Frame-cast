<?php

/**
 * Moderation tuning knobs. Edit this file (not the job) to adjust thresholds
 * and term lists. The pattern-detection job reads from here every run.
 */

return [

    /**
     * Workspace is flagged when it produces this many provider rejections
     * in a 24-hour rolling window. Below this is a normal exploration rate;
     * above this is either deliberate probing or automated abuse.
     */
    'rejection_threshold_per_workspace_24h' => 5,

    /**
     * Prompts containing any of these terms in the trailing 24h trigger a
     * pattern_alert event. The list is intentionally biased toward
     * impersonation-risk terms (politicians, deepfake/nude language).
     * Extend as new vectors emerge.
     *
     * Matching is case-insensitive and substring-based — be conservative
     * to avoid false positives ("Trump card" matches "Trump"). False
     * positives are recoverable; the alert is logged for review, not an
     * automatic ban.
     */
    'high_risk_terms' => [

        // Common impersonation / deepfake language
        'deepfake', 'deep fake', 'face swap', 'face-swap',
        'fake nude', 'fake naked', 'undress', 'undressed',
        'nude photo', 'naked photo', 'pornographic',
        'revenge porn', 'leaked nude', 'leaked photo',
        'impersonate', 'pretending to be',
        'looks like', 'looks exactly like',

        // US politicians (current + recent presidents, VPs, prominent)
        'joe biden', 'donald trump', 'kamala harris', 'barack obama',
        'michelle obama', 'hillary clinton', 'bill clinton',
        'mike pence', 'ron desantis', 'aoc', 'alexandria ocasio',
        'bernie sanders', 'elizabeth warren', 'nancy pelosi',
        'mitch mcconnell', 'tucker carlson',

        // World leaders
        'vladimir putin', 'xi jinping', 'narendra modi',
        'emmanuel macron', 'volodymyr zelensky', 'zelenskyy',
        'benjamin netanyahu', 'recep erdogan', 'justin trudeau',
        'kim jong un', 'mohammed bin salman',

        // Tech / business power figures
        'elon musk', 'jeff bezos', 'mark zuckerberg', 'bill gates',
        'tim cook', 'sundar pichai', 'satya nadella',
        'sam altman', 'jensen huang',

        // Major entertainment figures often targeted by deepfakes
        'taylor swift', 'beyonce', 'beyoncé', 'rihanna', 'drake',
        'kim kardashian', 'kanye west', 'ye ', // 'ye ' with trailing space avoids matching 'yet'
        'leonardo dicaprio', 'tom cruise', 'scarlett johansson',
        'emma watson', 'gal gadot', 'megan fox',

        // Specific synthetic-content red flags
        'csam', 'cp ', 'underage', 'minor sex', 'teen sex', 'child sex',
        'lolicon', 'shota',

        // Fraud / scam vectors
        'pump and dump', 'investment guaranteed return',
        'crypto giveaway', 'send eth to', 'send btc to',
        'social security number', 'irs refund',
    ],

    /**
     * Email address that receives the daily moderation digest. The digest
     * is sent only if there are pending events (no spam on quiet days).
     */
    'digest_email' => env('MODERATION_DIGEST_EMAIL', 'hello@wyvstudio.com'),

];
