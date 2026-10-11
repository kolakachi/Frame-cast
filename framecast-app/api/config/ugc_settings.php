<?php

/*
| UGC settings (owner, 2026-10-11): the kinds of place a UGC ad is filmed in. Each has an empty background photo
| (made once by `php artisan ugc:seed-settings`) that the video model gets as a loose reference: the kind of place,
| never a copy, so it is free to pick its own spot, time of day and details. The director picks one per ad.
|
| place:    how the prompt names it ("a beach boardwalk")
| plate:    what the background photo shows (no people)
| wardrobe: outfits that suit it; empty uses the everyday outfits
| rule:     staging the place needs, added to the prompt
*/

return [
    // Home
    'kitchen' => ['group' => 'home', 'place' => 'a home kitchen, at the counter',
        'plate' => 'a bright lived-in home kitchen counter with a kettle, a fruit bowl and a window behind'],
    'bathroom' => ['group' => 'home', 'place' => 'a bathroom, at the vanity mirror',
        'plate' => 'a clean home bathroom vanity with a mirror, a few skincare bottles and a towel, soft daylight'],
    'bedroom' => ['group' => 'home', 'place' => 'a bedroom, sitting on the edge of the bed',
        'plate' => 'a cosy bedroom with a made bed, linen bedding, a bedside lamp and a plant'],
    'sofa' => ['group' => 'home', 'place' => 'a living room, on the sofa',
        'plate' => 'a relaxed living room sofa with cushions, a throw and a side table, afternoon light'],
    'home_desk' => ['group' => 'home', 'place' => 'a home desk',
        'plate' => 'a tidy home desk with a laptop, a mug and shelves behind, warm lamp light'],
    'hallway_mirror' => ['group' => 'home', 'place' => 'a hallway, in front of a full-length mirror',
        'plate' => 'a home hallway with a full-length mirror, a coat hook and wooden floor',
        'rule' => 'A mirror selfie: the phone shows in the mirror, held in one hand.'],

    // Out and about
    'car' => ['group' => 'out', 'place' => 'a parked car, in the driver\'s seat',
        'plate' => 'the interior of a parked car seen from the passenger side, driver seat and steering wheel, daylight through the windscreen',
        'rule' => 'The car is parked and the engine is off; the seatbelt may be on.'],
    'street' => ['group' => 'out', 'place' => 'a city street, walking',
        'plate' => 'a city pavement with shopfronts and trees, soft overcast daylight',
        'wardrobe' => ['a light trench coat over a white tee', 'a navy bomber jacket over a grey tee', 'an oversized denim jacket over a black top']],
    'park' => ['group' => 'out', 'place' => 'a park path or trail',
        'plate' => 'a green park path with trees and benches, dappled sunlight',
        'wardrobe' => ['a light fleece zip-up', 'a sage green hoodie', 'a navy windbreaker over a white tee']],
    'beach' => ['group' => 'out', 'place' => 'a beach',
        'plate' => 'a sandy beach with gentle waves and an open sky, no people',
        'wardrobe' => ['a loose white linen shirt', 'a light blue linen shirt with the sleeves rolled', 'a sand-coloured linen top'],
        'rule' => 'Fully dressed in light beach clothes, never swimwear.'],
    'hotel_room' => ['group' => 'out', 'place' => 'a hotel room',
        'plate' => 'a modern hotel room with a large window, a made bed and a city view'],

    // Retail and beauty
    'boutique' => ['group' => 'retail', 'place' => 'a clothing boutique, by the rails',
        'plate' => 'a small clothing boutique with garment rails, folded knitwear and warm lighting',
        'wardrobe' => ['a cream knit cardigan over a white tee', 'a fitted black turtleneck', 'a camel wool coat over a plain top']],
    'fitting_room' => ['group' => 'retail', 'place' => 'a store fitting room, in front of the mirror',
        'plate' => 'a clothing store fitting room with a large mirror, a curtain and a hook with hangers',
        'rule' => 'A mirror selfie: the phone shows in the mirror, held in one hand.'],
    'salon' => ['group' => 'retail', 'place' => 'a hair salon or barber chair',
        'plate' => 'a modern salon chair facing a mirror with warm bulbs and styling products'],
    'market' => ['group' => 'retail', 'place' => 'an outdoor market stall',
        'plate' => 'an outdoor food market with colourful produce stalls and string lights'],

    // Fitness
    'gym' => ['group' => 'fitness', 'place' => 'a gym floor',
        'plate' => 'a gym floor with racks of dumbbells and benches, cool daylight',
        'wardrobe' => ['a black training top', 'a grey sports tee', 'a fitted navy athletic zip-up']],
    'yoga_mat' => ['group' => 'fitness', 'place' => 'a yoga mat at home',
        'plate' => 'a calm living room corner with a rolled-out yoga mat, plants and soft daylight',
        'wardrobe' => ['a soft sage athleisure top', 'a black long-sleeve workout top', 'a heather grey athletic tee']],
    'run' => ['group' => 'fitness', 'place' => 'outdoors after a run',
        'plate' => 'a riverside running path in the morning, trees and a railing',
        'wardrobe' => ['a bright running jacket', 'a grey running tee', 'a black running zip-up']],

    // Work and food
    'cafe' => ['group' => 'work', 'place' => 'a café table',
        'plate' => 'a cosy café table by a window, a coffee cup and pastries, warm light'],
    'office' => ['group' => 'work', 'place' => 'an office or co-working space',
        'plate' => 'a bright co-working office with desks, plants and large windows',
        'wardrobe' => ['a navy blazer over a white tee', 'a light blue button-up shirt', 'a charcoal knit polo']],
    'restaurant' => ['group' => 'work', 'place' => 'a restaurant table',
        'plate' => 'a relaxed restaurant table with plates and glasses, warm evening light'],
    'workshop' => ['group' => 'work', 'place' => 'a maker\'s workbench',
        'plate' => 'a small workshop bench with tools on a pegboard and wood shavings',
        'wardrobe' => ['a canvas work apron over a grey tee', 'an olive utility overshirt', 'a navy work jacket']],
    'studio' => ['group' => 'work', 'place' => 'a small creative studio',
        'plate' => 'a small creative studio with a large table, materials, and white walls'],
    'balcony' => ['group' => 'home', 'place' => 'a balcony',
        'plate' => 'a small apartment balcony with potted plants and a city view, golden hour'],
];
