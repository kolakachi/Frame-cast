<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Admin\AdminMailController;
use App\Models\User;
use Tests\TestCase;

class ComposedMailLinksTest extends TestCase
{
    private function render(string $body, string $name = 'Ada Lovelace'): string
    {
        $method = new \ReflectionMethod(AdminMailController::class, 'renderBody');
        $method->setAccessible(true);

        return $method->invoke(new AdminMailController, $body, new User(['name' => $name]));
    }

    public function test_a_bare_url_becomes_a_link(): void
    {
        $html = $this->render('Have a look at https://app.wyvstudio.com/plans today.');

        $this->assertStringContainsString(
            '<a href="https://app.wyvstudio.com/plans">https://app.wyvstudio.com/plans</a>', $html,
        );
    }

    public function test_the_full_stop_after_a_url_is_not_part_of_the_link(): void
    {
        $html = $this->render('See https://wyvstudio.com/refund.');

        $this->assertStringContainsString('>https://wyvstudio.com/refund</a>.', $html);
        $this->assertStringNotContainsString('refund.</a>', $html);
    }

    public function test_a_balanced_bracket_inside_a_url_survives(): void
    {
        $html = $this->render('https://en.wikipedia.org/wiki/Ada_(name) is the one.');

        $this->assertStringContainsString('wiki/Ada_(name)</a>', $html);
    }

    public function test_a_trailing_unbalanced_bracket_is_left_out(): void
    {
        $html = $this->render('(see https://wyvstudio.com/plans)');

        $this->assertStringContainsString('>https://wyvstudio.com/plans</a>)', $html);
    }

    public function test_only_http_and_https_are_linked(): void
    {
        // A link in a message that came from us is the one a recipient trusts.
        $html = $this->render("javascript:alert(1) and data:text/html,<b>x</b> and ftp://old.test/file");

        $this->assertStringNotContainsString('<a href="javascript:', $html);
        $this->assertStringNotContainsString('<a href="data:', $html);
        $this->assertStringNotContainsString('<a href="ftp:', $html);
    }

    public function test_html_in_the_body_is_still_escaped(): void
    {
        $html = $this->render('<script>alert(1)</script> and <b>bold</b>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_a_url_containing_a_quote_cannot_break_out_of_the_attribute(): void
    {
        // The escaper runs first, so the quote is an entity by the time the
        // anchor is built and cannot close the href.
        $html = $this->render('https://evil.test/a"onmouseover="alert(1)');

        $this->assertStringNotContainsString('onmouseover="alert', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    public function test_the_name_placeholder_still_works_alongside_links(): void
    {
        $html = $this->render('Hi {name}, see https://wyvstudio.com/plans');

        $this->assertStringContainsString('Hi Ada,', $html);
        $this->assertStringContainsString('<a href="https://wyvstudio.com/plans">', $html);
    }

    public function test_paragraphs_and_line_breaks_are_preserved(): void
    {
        $html = $this->render("First line\nsame paragraph\n\nSecond paragraph");

        $this->assertStringContainsString('<br', $html);
        $this->assertStringContainsString('</p><p>', $html);
    }

    public function test_several_urls_in_one_message_are_all_linked(): void
    {
        $html = $this->render('https://a.test and https://b.test and https://c.test');

        $this->assertSame(3, substr_count($html, '<a href='));
    }
}
