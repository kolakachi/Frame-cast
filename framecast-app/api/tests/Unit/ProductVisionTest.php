<?php

namespace Tests\Unit;

use App\Services\Ugc\{ProductImageFinder, ProductVision, UgcOneShotCompiler};
use PHPUnit\Framework\TestCase;

/** VISION (2026-10-11): the product is described from its photos, and a product page names its own photos. */
class ProductVisionTest extends TestCase
{
    public function test_a_sheet_is_read_from_the_answer_and_said_in_one_phrase(): void
    {
        $sheet = ProductVision::parse('Here: {"kind":"product","what":"a face sunscreen","form":"squeeze tube","size":"about the length of a hand","look":"white with an orange flip cap","label":"Sunly SPF 50"}');
        $this->assertSame('product', $sheet['kind']);
        $this->assertSame('a face sunscreen (squeeze tube); white with an orange flip cap; about the length of a hand; the label reads "Sunly SPF 50"', ProductVision::line($sheet));
        $this->assertSame('other', ProductVision::parse('{"kind":"poster","what":"a banner"}')['kind'], 'an unknown kind is never taken as a product shot');
        $this->assertNull(ProductVision::parse('{"kind":"product"}'), 'nothing said about the object is no sheet');
        $this->assertNull(ProductVision::parse('no json'));
    }

    public function test_a_product_page_names_its_photos_best_first(): void
    {
        $html = '<html><head><meta property="og:image" content="//cdn.shop.com/og.jpg?v=1"><meta name="twitter:image" content="/tw.png">'
            .'<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","logo":"https://x.com/logo.png"},'
            .'{"@type":"Product","name":"Sunly","image":["https://cdn.shop.com/front.jpg",{"@type":"ImageObject","url":"https://cdn.shop.com/back.jpg"}]}]}</script>'
            .'<meta property="og:image" content="http://insecure.com/a.jpg"></head></html>';
        $this->assertSame(['https://cdn.shop.com/front.jpg', 'https://cdn.shop.com/back.jpg', 'https://cdn.shop.com/og.jpg?v=1', 'https://shop.com/tw.png'],
            ProductImageFinder::candidates($html, 'https://shop.com/products/sunly'));
    }

    public function test_one_address_per_photo_whatever_its_listed_sizes(): void
    {
        $this->assertSame(['https://cdn.shop.com/a/side_1024x1024.jpg?v=3', 'https://cdn.shop.com/a/front.jpg', 'https://cdn.shop.com/a/back.png'],
            ProductImageFinder::distinct(['https://cdn.shop.com/a/side_450x450.jpg', 'https://cdn.shop.com/a/side_1024x1024.jpg?v=3', 'https://cdn.shop.com/a/front_450x450.jpg', 'https://cdn.shop.com/a/front.jpg', 'https://cdn.shop.com/a/back.png']));
        $this->assertSame(['https://shop.com/cdn/shop/files/front.jpg'], ProductImageFinder::distinct(['https://shop.com/cdn/shop/files/front.jpg', 'https://cdn.shopify.com/s/files/1/front_1024x1024.jpg']), 'the same photo on two hosts');
        $this->assertSame('', ProductVision::parse('{"kind":"product","form":"tube","label":"not clearly readable"}')['label']);
        $this->assertTrue(ProductVision::parse('{"kind":"product","form":"tube","match":true}')['match']);
        $this->assertArrayNotHasKey('match', ProductVision::parse('{"kind":"product","form":"tube"}'));
    }

    public function test_every_clip_prompt_keeps_the_product_as_photographed(): void
    {
        $beats = [['script_text' => 'I love it.', 'seconds' => 4, 'visual_brief' => 'Holds the tube up']];
        $style = ['product' => 'Sunly SPF 50', 'product_look' => 'a face sunscreen (squeeze tube); white with an orange flip cap'];
        $this->assertStringContainsString('The product is exactly the one in the product reference photos: a face sunscreen (squeeze tube); white with an orange flip cap. Keep its exact form',
            UgcOneShotCompiler::compileSingle($beats, $style)['prompt']);
        $this->assertStringContainsString('never turn it into a different kind of container', UgcOneShotCompiler::compile($beats, $style)[0]['prompt']);
        $this->assertStringNotContainsString('product reference photos', UgcOneShotCompiler::compile($beats, ['product' => 'Sunly'])[0]['prompt'], 'no photo read, no claim about one');
    }
}
