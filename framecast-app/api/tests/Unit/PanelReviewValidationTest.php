<?php
namespace Tests\Unit;

use App\Services\Create\PlanMediaExecutor;
use Illuminate\Support\Facades\{Http, Process};
use Tests\TestCase;

class PanelReviewValidationTest extends TestCase
{
    public function test_only_complete_unambiguous_boolean_panel_reviews_are_trusted(): void
    {
        config(['services.anthropic.key' => 'offline-test-key']);
        Http::preventStrayRequests();
        $responses = Http::sequence();
        Http::fake(['api.anthropic.com/*' => $responses]);
        Process::fake();
        $path = tempnam(sys_get_temp_dir(), 'panel-review-');
        try {
            foreach ([
                [[['panel' => 'Panel 1']], 'unverified'],
                [[['panel' => 'Panel 1', 'ok' => 'false']], 'unverified'],
                [[['panel' => 'Panel 1', 'ok' => 1]], 'unverified'],
                [[['panel' => 'Panel 1', 'ok' => true], ['panel' => 'Panel 1', 'ok' => false]], 'unverified'],
                [[['panel' => 'Panel 9', 'ok' => true]], 'unverified'],
                [[], 'unverified'],
                [[['panel' => 'Panel 1', 'ok' => false, 'issue' => 'Wrong person']], 'issues'],
                [[['panel' => 'Panel 1', 'ok' => true, 'issue' => '']], 'ok'],
            ] as [$panels, $expected]) {
                file_put_contents($path.'.check.jpg', 'offline image bytes');
                $responses->push(['content' => [['type' => 'text', 'text' => json_encode(['panels' => $panels])]]]);
                $result = app(PlanMediaExecutor::class)->panelChecks([['path' => $path]], [['label' => 'Panel 1']], []);
                $this->assertSame($expected, $result['status'], json_encode($panels));
            }
        } finally { @unlink($path); @unlink($path.'.check.jpg'); }
    }
}
