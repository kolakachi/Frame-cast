<?php

namespace Tests\Feature;

use App\Services\Projects\ProjectCreationService;
use Tests\TestCase;

/** FIRST-FIX (2026-10-17): the product form's empty labels are not a brief; "Start from scratch" needs none. */
class ProductFormGuardTest extends TestCase
{
    public function test_an_empty_product_form_is_refused_and_a_filled_one_passes(): void
    {
        $svc = app(ProjectCreationService::class);
        $this->assertSame("Add your product's name and a line about it, or paste its link.", $svc->validateSourceContent('product_description', "Product Name: \nProduct Description: "));
        $this->assertSame("Add your product's name and a line about it, or paste its link.", $svc->validateSourceContent('product_description', "Product Name:\nProduct Description:\nTarget Audience:"));
        $this->assertNull($svc->validateSourceContent('product_description', "Product Name: Sourdough\nProduct Description: Baked daily"));
        $this->assertNull($svc->validateSourceContent('product_description', "Product Name: \nProduct Description: \nProduct URL: https://shop.example/bread"));
        $this->assertNull($svc->validateSourceContent('blank', null), 'start from scratch needs no text');
    }
}
