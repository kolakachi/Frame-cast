<?php
namespace Tests\Feature;

use App\Services\Create\RigSvg;
use Tests\TestCase;

class RigSvgTest extends TestCase
{
    private function maya(string $extra = '', string $mouthOpen = '<ellipse cx="50" cy="70" rx="8" ry="6"/>'): string
    {
        // Layer names as Figma and Illustrator export them.
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="100" height="120" onload="alert(1)">'
            .'<script>alert(1)</script><foreignObject><div>x</div></foreignObject>'
            .'<g id="Body"><rect width="100" height="40" y="80"/></g>'
            .'<g id="head"><circle cx="50" cy="45" r="30"/>'
            .'<g id="Left Eye"><circle cx="40" cy="40" r="5"/><g id="left_pupil"><circle cx="40" cy="40" r="2"/></g></g>'
            .'<g id="right_x2D_eye"><circle cx="60" cy="40" r="5"/><g id="right-pupil"><circle cx="60" cy="40" r="2"/></g></g>'
            .'<g id="mouth-rest"><path d="M40 70h20"/></g><g id="mouth-smile"><path d="M40 68q10 8 20 0"/></g><g id="mouth-open">'.$mouthOpen.'</g><g id="mouth-round"><circle cx="50" cy="70" r="4"/></g>'
            .'<image xlink:href="https://tracker.example/x.png" width="1" height="1"/><animate attributeName="r" values="1;2" dur="1s"/>'
            .'</g>'.$extra.'</svg>';
    }

    public function test_a_layered_character_is_cleaned_and_mapped_onto_the_rig(): void
    {
        $r = RigSvg::prepare($this->maya());
        $this->assertTrue($r['rig']['ready'], json_encode($r['rig']['problems']));
        $this->assertSame(['rest', 'smile', 'open', 'round'], $r['rig']['mouths']);
        $svg = $r['svg'];
        foreach (['head', 'left-eye', 'right-eye', 'left-pupil', 'right-pupil'] as $part) $this->assertStringContainsString('data-rig-part="'.$part.'"', $svg);
        $this->assertStringContainsString('data-rig-mouth="smile"', $svg);
        foreach (['<script', 'onload', 'foreignObject', 'tracker.example', '<animate'] as $gone) $this->assertStringNotContainsString($gone, $svg, $gone.' is removed');
        $this->assertStringContainsString('viewBox="0 0 100 120"', $svg);
        $this->assertTrue(RigSvg::looksLike($this->maya()));
        $this->assertFalse(RigSvg::looksLike('<html><body>x</body></html>'));
    }

    public function test_missing_or_misplaced_layers_are_named(): void
    {
        $bad = str_replace(['<g id="left_pupil"><circle cx="40" cy="40" r="2"/></g>', '<g id="mouth-rest"><path d="M40 70h20"/></g>'], ['', ''], $this->maya('<g id="mouth-rest"><path d="M1 1h2"/></g>', ''));
        $r = RigSvg::prepare($bad);
        $this->assertFalse($r['rig']['ready']);
        $this->assertContains('No "left-pupil" layer.', $r['rig']['problems']);
        $this->assertContains('The "mouth-open" layer is empty.', $r['rig']['problems']);
        $this->assertContains('The "mouth-rest" layer must be inside "head".', $r['rig']['problems']);
    }

    public function test_a_document_type_is_refused(): void
    {
        try { RigSvg::prepare('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg>&x;</svg>'); $this->fail('refused'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
    }
}
