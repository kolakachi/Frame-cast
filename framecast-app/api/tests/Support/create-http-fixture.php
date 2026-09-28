<?php
// Explicit opt-in, disposable HTTP harness. Never an application/public route.
// Start with PHP's built-in server and CREATE_HTTP_FIXTURE=1, DB_DATABASE=/tmp/create-fixture.sqlite.
if (getenv('CREATE_HTTP_FIXTURE') !== '1' || getenv('DB_DATABASE') !== '/tmp/create-fixture.sqlite') { http_response_code(404); exit; }
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => '/tmp/create-fixture.sqlite',
    'database.connections.sqlite.foreign_key_constraints' => false, 'cors.allowed_origins' => ['http://127.0.0.1:5188'], 'cache.default' => 'array', 'session.driver' => 'array',
    'services.posthog.key' => '', 'create.enabled' => true, 'create.workspaces' => [1], 'create.mode' => 'fixture', 'developer.operation_accounting' => true]);
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Redis::shouldReceive('get')->andReturn(null);
class CreateHttpFixtureSchema { use \Tests\Support\BuildsDeveloperSchema; public function build(): void { $this->buildDeveloperSchema(); } }
if (! file_exists('/tmp/create-fixture.sqlite')) touch('/tmp/create-fixture.sqlite');
if (! \Illuminate\Support\Facades\Schema::hasTable('create_conversations')) {
    (new CreateHttpFixtureSchema)->build();
    (require database_path('migrations/2026_09_25_200000_create_api_operations.php'))->up();
    (require database_path('migrations/2026_09_28_120000_create_composition_conversations.php'))->up();
    \App\Models\Workspace::create(['name' => 'Create fixture', 'status' => 'active', 'plan_tier' => 'creator', 'plan_status' => 'active', 'credits_monthly' => 100]);
    $user = \App\Models\User::create(['email' => 'create-fixture@example.test', 'name' => 'Local tester', 'role' => 'owner', 'status' => 'active']);
    $user->forceFill(['workspace_id' => 1])->save();
}
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
