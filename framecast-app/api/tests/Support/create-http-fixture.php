<?php
// Explicit opt-in, disposable HTTP harness. Never an application/public route.
// Start with PHP's built-in server and CREATE_HTTP_FIXTURE=1, DB_DATABASE=/tmp/create-fixture.sqlite.
if (getenv('CREATE_HTTP_FIXTURE') !== '1' || getenv('DB_DATABASE') !== '/tmp/create-fixture.sqlite') { http_response_code(404); exit; }
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => '/tmp/create-fixture.sqlite',
    'database.connections.sqlite.foreign_key_constraints' => false, 'database.connections.sqlite.busy_timeout' => 10000, 'database.connections.sqlite.journal_mode' => 'wal', 'cors.allowed_origins' => ['http://127.0.0.1:5188'], 'cache.default' => 'array', 'session.driver' => 'array',
    'services.posthog.key' => '', 'create.enabled' => true, 'create.workspaces' => [1], 'create.mode' => 'fixture', 'developer.operation_accounting' => true,
    'filesystems.disks.minio' => ['driver' => 'local', 'root' => '/tmp/create-input-fixtures', 'throw' => true]]);
if(in_array(getenv('CREATE_LIVE_PILOT'),['e3-2026-09-29','e3-opus-2026-09-29'],true)) {
    // Explicit opt-in for the user-approved additional $5. Persistent DB plus a
    // separate host budget prevent allowance reset by test-container recreation.
    config(['create.mode'=>'agent','create.paid_execution_enabled'=>true,'create.pilot_budget_id'=>getenv('CREATE_LIVE_PILOT'),'create.pilot_budget_microusd'=>5000000]);
} else \Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Redis::shouldReceive('get')->andReturn(null);
class CreateHttpFixtureSchema { use \Tests\Support\BuildsDeveloperSchema; public function build(): void { $this->buildDeveloperSchema(); } }
if (! file_exists('/tmp/create-fixture.sqlite')) touch('/tmp/create-fixture.sqlite');
if (! \Illuminate\Support\Facades\Schema::hasTable('create_conversations')) {
    (new CreateHttpFixtureSchema)->build();
    (require database_path('migrations/2026_09_25_200000_create_api_operations.php'))->up();
    (require database_path('migrations/2026_09_28_120000_create_composition_conversations.php'))->up();
    (require database_path('migrations/2026_09_29_000000_create_composition_attempts.php'))->up();
        (require database_path('migrations/2026_09_29_120000_link_composition_outputs.php'))->up();
        (require database_path('migrations/2026_09_29_130000_create_composition_reconciliations.php'))->up();
        (require database_path('migrations/2026_09_29_180000_add_create_output_metadata.php'))->up();
        (require database_path('migrations/2026_09_29_190000_create_composition_deliveries.php'))->up();
    \App\Models\Workspace::create(['name' => 'Create fixture', 'status' => 'active', 'plan_tier' => 'creator', 'plan_status' => 'active', 'credits_monthly' => 10000]);
    $user = \App\Models\User::create(['email' => 'create-fixture@example.test', 'name' => 'Local tester', 'role' => 'owner', 'status' => 'active']);
    $user->forceFill(['workspace_id' => 1])->save();
    \Illuminate\Support\Facades\Storage::disk('minio')->put('reference.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC1kAAAAASUVORK5CYII='));
    \App\Models\Asset::create(['workspace_id' => 1, 'asset_type' => 'image', 'title' => 'Synthetic input fixture', 'status' => 'ready', 'storage_url' => 'minio://reference.png']);
}
// Added after the first harness databases existed; additive and idempotent.
if (! \Illuminate\Support\Facades\Schema::hasTable('create_plans')) (require database_path('migrations/2026_09_30_120000_create_create_plans.php'))->up();
if (! \Illuminate\Support\Facades\Schema::hasTable('create_plan_media')) (require database_path('migrations/2026_10_01_120000_create_create_plan_media.php'))->up();
if (! \Illuminate\Support\Facades\Schema::hasColumn('create_conversations', 'provider_consent_at')) (require database_path('migrations/2026_09_30_130000_add_create_provider_consent.php'))->up();
$app->instance(\App\Http\Middleware\AuthenticateWithJwt::class, new class extends \App\Http\Middleware\AuthenticateWithJwt {
    public function __construct() {}
    public function handle(\Illuminate\Http\Request $request, \Closure $next): \Symfony\Component\HttpFoundation\Response {
        abort_unless($request->bearerToken() === 'local-create-fixture', 401);
        $request->setUserResolver(fn () => \App\Models\User::first());
        return $next($request);
    }
});
$request = \Illuminate\Http\Request::capture();
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request); $response->send(); $kernel->terminate($request, $response);
