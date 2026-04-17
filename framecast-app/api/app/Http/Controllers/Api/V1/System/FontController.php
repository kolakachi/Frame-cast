<?php

namespace App\Http\Controllers\Api\V1\System;

use App\Constants\CaptionFonts;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class FontController extends Controller
{
    public function index(): JsonResponse
    {
        $fonts = [];

        foreach (CaptionFonts::CATEGORIES as $category => $names) {
            foreach ($names as $name) {
                $fonts[] = [
                    'name' => $name,
                    'category' => $category,
                    'tier' => $this->tier($name),
                ];
            }
        }

        return response()->json([
            'data' => [
                'fonts' => $fonts,
                'categories' => array_keys(CaptionFonts::CATEGORIES),
            ],
            'meta' => ['total' => count(CaptionFonts::ALL)],
        ]);
    }

    private function tier(string $name): int
    {
        $tier2 = ['Liberation Sans', 'Liberation Serif', 'Liberation Mono', 'Nimbus Roman', 'Nimbus Sans', 'Nimbus Mono PS', 'Century Schoolbook L'];

        return in_array($name, $tier2, true) ? 2 : 1;
    }
}
