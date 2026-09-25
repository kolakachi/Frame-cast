<?php
// Run only against the disposable PostgreSQL instance on 127.0.0.1:55439.
// No real provider calls. Creates/drops ONLY the phase_a_probe schema.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $e) { fwrite(STDERR, $e->getMessage().'\n'.$e->getTraceAsString().'\n'); exit(1); });
use Illuminate\Support\Facades\{DB, Schema, Context, Queue, Http};
use Illuminate\Database\Schema\Blueprint;
use App\Services\Developer\{OperationAccounting as Ops, OperationFence};
use App\Models\{ApiKey, ApiQuote, Workspace};
config(['database.default' => 'phase_a_probe', 'database.connections.phase_a_probe' => [
    'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55439, 'database' => 'phase_a',
    'username' => 'phase_a', 'password' => 'phase_a_disposable', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'phase_a_probe',
], 'cache.default' => 'array', 'developer.operation_accounting' => true,
'developer.limits.max_active_videos' => 3, 'services.posthog.key' => '',
'queue.default' => 'probe', 'queue.connections.probe' => ['driver' => 'database', 'connection' => 'phase_a_probe', 'table' => 'jobs', 'queue' => 'probe', 'retry_after' => 1, 'after_commit' => false], 'queue.failed.driver' => null]);
if (getenv('PHASE_A_QUEUE') === 'redis') {
    config(['database.redis.client' => 'predis', 'database.redis.options.prefix' => 'wyv-phase-a-probe:',
        'database.redis.default' => ['url' => null, 'host' => '127.0.0.1', 'port' => 56379, 'password' => null, 'database' => 0],
        'database.redis.horizon' => ['url' => null, 'host' => '127.0.0.1', 'port' => 56379, 'password' => null, 'database' => 0],
        'database.redis.cache' => ['url' => null, 'host' => '127.0.0.1', 'port' => 56379, 'password' => null, 'database' => 0],
        'queue.connections.probe' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'probe', 'retry_after' => 1, 'block_for' => null, 'after_commit' => false]]);
}
DB::purge('phase_a_probe');
Http::preventStrayRequests();

class PhaseAQueuedProbe implements Illuminate\Contracts\Queue\ShouldQueue {
    public function __construct(public bool $crash = false) {}
    public function handle(): void {
        if (! app(App\Services\CreditService::class)->deduct(1, 10, 'probe')) throw new RuntimeException('Debit refused');
        DB::table('probe_effects')->insert(['marker' => 'paid']);
        if ($this->crash) { file_put_contents(sys_get_temp_dir().'/wyv_phase_a_crash_started', '1'); sleep(60); }
    }
}
class PhaseAReleasedProbe implements Illuminate\Contracts\Queue\ShouldQueue {
    use Illuminate\Queue\InteractsWithQueue;
    public function handle(): void {
        if ($this->attempts() === 1) { $this->release(0); return; }
        app(App\Services\CreditService::class)->deduct(1, 5, 'released-probe');
    }
}
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS {$label}\n"; }
function spawn(string $mode): array {
    $pipes = [];
    $p = proc_open([PHP_BINARY, __FILE__, $mode], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]); return [$p,$pipes];
}
function finish(array $child): string { [$p,$pipes]=$child; $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($p); if($exit!==0) throw new RuntimeException("Worker failed: {$out} {$err}"); return trim($out); }
function quote(int $max, int $takes=1): ApiQuote { return ApiQuote::create(['id'=>ApiQuote::newId(),'workspace_id'=>1,'payload_json'=>['pricing'=>['takes'=>$takes]],'credits_min'=>$max,'credits_max'=>$max,'expires_at'=>now()->addMinutes(10)]); }
function reserve(int $max, int $takes=1): string { return DB::transaction(function() use($max,$takes) {Workspace::whereKey(1)->lockForUpdate()->firstOrFail(); return Ops::reserve(quote($max,$takes),1);}); }
$mode=$argv[1]??'parent';
if($mode==='admit') { try { $id=reserve(40); echo 'admitted'; OperationFence::release($id); } catch(DomainException $e) {echo $e->getMessage();} exit; }
if(in_array($mode,['worker','worker-replay'],true)) {
    $worker=app('queue.worker');
    $job = Queue::connection('probe')->pop('probe');
    if (! $job) throw new RuntimeException('Expected a queued probe job.');
    try { $worker->process('probe', $job, new Illuminate\Queue\WorkerOptions(sleep:0,maxTries:3,timeout:90)); }
    catch (RuntimeException $e) { if ($mode !== 'worker-replay' || !str_contains($e->getMessage(), 'Operation cannot safely repeat')) throw $e; }
    exit;
}
if($mode==='hold') {
    DB::select('select pg_advisory_lock(198734, 1)');
    file_put_contents(sys_get_temp_dir().'/wyv_phase_a_lock_started','1'); usleep(1200000);
    DB::select('select pg_advisory_unlock(198734, 1)'); exit;
}
if($mode==='cancel') { $id=DB::table('api_operations')->whereIn('status',['running','needs_attention'])->value('id'); echo Ops::cancel($id,1)?'cancelled':'busy'; exit; }

if (getenv('PHASE_A_QUEUE') === 'redis') Illuminate\Support\Facades\Redis::connection()->flushdb();
DB::statement('DROP SCHEMA IF EXISTS phase_a_probe CASCADE');
DB::statement('CREATE SCHEMA phase_a_probe');
Schema::create('workspaces',function(Blueprint $t){$t->id();$t->string('plan_tier');$t->string('status');$t->string('funding_mode')->nullable();$t->unsignedBigInteger('parent_workspace_id')->nullable();$t->integer('credits_monthly')->default(0);$t->integer('credits_topup')->default(0);$t->timestamps();});
Schema::create('api_keys',function(Blueprint $t){$t->id();$t->unsignedBigInteger('workspace_id');$t->integer('spend_cap_credits')->nullable();$t->unsignedBigInteger('rotated_from_id')->nullable();});
Schema::create('api_quotes',function(Blueprint $t){$t->string('id',32)->primary();$t->unsignedBigInteger('workspace_id');$t->json('payload_json');$t->integer('credits_min');$t->integer('credits_max');$t->timestamp('expires_at');$t->timestamps();});
Schema::create('projects',function(Blueprint $t){$t->id();$t->unsignedBigInteger('api_key_id')->nullable();$t->string('title')->nullable();});
Schema::create('scenes',function(Blueprint $t){$t->id();$t->unsignedBigInteger('project_id');$t->string('label')->nullable();});
Schema::create('credit_ledger',function(Blueprint $t){$t->id();foreach(['workspace_id','spent_by_workspace_id','user_id','project_id','scene_id'] as $c)$t->unsignedBigInteger($c)->nullable();$t->string('operation');$t->integer('credits');$t->integer('balance_after');$t->decimal('upstream_cost_usd',12,6)->nullable();$t->json('metadata')->nullable();$t->timestamps();});
Schema::create('jobs',function(Blueprint $t){$t->bigIncrements('id');$t->string('queue')->index();$t->longText('payload');$t->unsignedTinyInteger('attempts');$t->unsignedInteger('reserved_at')->nullable();$t->unsignedInteger('available_at');$t->unsignedInteger('created_at');});
Schema::create('probe_effects',fn(Blueprint $t)=>$t->string('marker'));
DB::table('projects')->insert(['id'=>99,'api_key_id'=>7,'title'=>'historical']);
DB::table('credit_ledger')->insert(['project_id'=>99,'operation'=>'historical','credits'=>1,'balance_after'=>0]);
$migration=require __DIR__.'/../../database/migrations/2026_09_25_200000_create_api_operations.php';$migration->up();
check((int) DB::table('credit_ledger')->where('project_id',99)->value('api_key_id')===7, 'migration preserves historical best-effort key attribution');
// The project write-trigger migration was removed at review (26 Sep 2026); the
// session fence is API-only, so the competing-write rejection probe no longer applies.
DB::table('workspaces')->insert(['id'=>1,'plan_tier'=>'creator','status'=>'active','credits_monthly'=>100]);
DB::table('api_keys')->insert(['id'=>1,'workspace_id'=>1,'spend_cap_credits'=>40]);
DB::table('projects')->insert(['id'=>1,'title'=>'original']);
DB::table('scenes')->insert(['id'=>1,'project_id'=>1,'label'=>'original']);
$a=spawn('admit');$b=spawn('admit');$results=[finish($a),finish($b)];sort($results);
check($results===['admitted','key_spend_cap_reached'],'concurrent admission reserves one key allowance');
DB::table('api_operations')->delete();DB::table('api_quotes')->delete();DB::table('api_keys')->where('id',1)->update(['spend_cap_credits'=>100]);
try{reserve(1,4);throw new RuntimeException('capacity admitted');}catch(DomainException $e){check($e->getMessage()==='too_many_active_videos','UGC takes reserve one capacity slot each');}
@unlink(sys_get_temp_dir().'/wyv_phase_a_lock_started');$child=spawn('hold');while(!is_file(sys_get_temp_dir().'/wyv_phase_a_lock_started'))usleep(10000);
check(!DB::selectOne('select pg_try_advisory_lock(198734,1) as acquired')->acquired,'simultaneous project mutation cannot claim same revision');
try { DB::table('scenes')->where('id',1)->update(['label'=>'changed']); throw new RuntimeException('Unfenced worker write'); }
catch (Illuminate\Database\QueryException $e) { check($e->getCode()==='55P03', 'database trigger rejects concurrent worker write without deadlock'); }
finish($child);
DB::table('scenes')->where('id',1)->update(['label'=>'changed']);
$id=reserve(40);Queue::connection('probe')->push(new PhaseAQueuedProbe);Ops::close($id);OperationFence::release($id);Context::forgetHidden(Ops::CONTEXT);
finish(spawn('worker'));
check(DB::table('api_operations')->where('id',$id)->value('status')==='completed','real async queue settles successful operation');
check((int)DB::table('credit_ledger')->where('api_operation_id',$id)->sum('credits')===10,'async debit retains originating operation');
check(Ops::reserved(1)===0,'async completion releases unused hold');
@unlink(sys_get_temp_dir().'/wyv_phase_a_crash_started');$id=reserve(40);Queue::connection('probe')->push(new PhaseAQueuedProbe(true));Ops::close($id);OperationFence::release($id);Context::forgetHidden(Ops::CONTEXT);
$child=spawn('worker');$deadline=microtime(true)+10;while(!is_file(sys_get_temp_dir().'/wyv_phase_a_crash_started')&&microtime(true)<$deadline)usleep(10000);
check(is_file(sys_get_temp_dir().'/wyv_phase_a_crash_started'),'worker reached charged side effect');
check(finish(spawn('cancel'))==='busy','cancellation refuses while worker executes');
proc_terminate($child[0],9);foreach($child[1] as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($child[0]);sleep(2);
finish(spawn('worker-replay'));
check(DB::table('probe_effects')->count()===2,'killed job redelivery does not duplicate paid side effect');
check(DB::table('api_operations')->where('id',$id)->value('status')==='needs_attention','ambiguous crash pauses operation for recovery');
check(Ops::reserved(1)>0,'ambiguous work keeps its hold');
check(Ops::cancel($id,1),'cancel fences dead worker and queued redelivery');
check(Ops::reserved(1)===0,'explicit cancellation releases remaining hold');
$id=reserve(20);Queue::connection('probe')->push(new PhaseAReleasedProbe);Ops::close($id);OperationFence::release($id);Context::forgetHidden(Ops::CONTEXT);
finish(spawn('worker'));
check(DB::table('api_operation_jobs')->where('operation_id',$id)->value('status')==='released', 'released job retains its reservation');
finish(spawn('worker'));
check((int) DB::table('credit_ledger')->where('api_operation_id',$id)->sum('credits')===5 && Ops::reserved(1)===0, 'released job resumes and settles once');
$id=reserve(20);Queue::connection('probe')->push(new PhaseAQueuedProbe);Ops::close($id);OperationFence::release($id);Context::forgetHidden(Ops::CONTEXT);
check(Ops::cancel($id,1), 'queued operation can be cancelled');
finish(spawn('worker'));
check(DB::table('credit_ledger')->where('api_operation_id',$id)->count()===0, 'cancelled queue delivery never invokes paid handler');
$locks->down();$migration->down();check(!Schema::hasTable('api_operations'),'disposable migration rollback succeeds');
DB::statement('DROP SCHEMA phase_a_probe CASCADE');
echo "Phase A PostgreSQL/async probe passed.\n";
