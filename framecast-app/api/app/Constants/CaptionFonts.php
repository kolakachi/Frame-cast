<?php

namespace App\Constants;

class CaptionFonts
{
    /**
     * All bundled caption fonts available to users.
     * Tier 1 = Google Fonts; Tier 2 = Windows Core Fonts.
     * Proxima Nova is intentionally excluded (commercial license).
     */
    public const ALL = [
        // Tier 1 — Google Fonts
        'Bebas Neue',
        'Montserrat',
        'Raleway',
        'Nunito',
        'Lato',
        'Roboto Mono',
        'Roboto Slab',
        'Libre Baskerville',
        'Playfair Display',
        'Dancing Script',
        'Fredoka One',
        'Sacramento',
        'Luckiest Guy',
        'Orbitron',
        'Satisfy',
        'Permanent Marker',
        'Noto Sans',
        'Amatic SC',
        'Days One',
        'Rock Salt',
        'New Rocker',
        'Passion One',
        'Indie Flower',
        'Quicksand',
        'Shadows Into Light',
        'Source Code Pro',
        'Aladin',
        'Calligraffitti',
        // Tier 2 — Liberation / URW fonts (open metric-compatible alternatives)
        'Liberation Sans',
        'Liberation Serif',
        'Liberation Mono',
        'Nimbus Roman',
        'Nimbus Sans',
        'Nimbus Mono PS',
        'Century Schoolbook L',
    ];

    public const CATEGORIES = [
        'Bold Display' => ['Bebas Neue', 'Fredoka One', 'Luckiest Guy', 'Days One', 'Passion One', 'New Rocker', 'Aladin'],
        'Sans-serif' => ['Montserrat', 'Raleway', 'Nunito', 'Lato', 'Quicksand', 'Noto Sans', 'Liberation Sans', 'Nimbus Sans'],
        'Serif' => ['Roboto Slab', 'Libre Baskerville', 'Playfair Display', 'Liberation Serif', 'Nimbus Roman', 'Century Schoolbook L'],
        'Script' => ['Dancing Script', 'Sacramento', 'Satisfy', 'Shadows Into Light', 'Calligraffitti'],
        'Mono' => ['Roboto Mono', 'Source Code Pro', 'Orbitron', 'Liberation Mono', 'Nimbus Mono PS'],
        'Handwritten' => ['Permanent Marker', 'Amatic SC', 'Indie Flower', 'Rock Salt', 'Calligraffitti'],
    ];
}
