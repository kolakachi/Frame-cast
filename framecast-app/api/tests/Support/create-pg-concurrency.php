<?php
// Disposable DB only. Run in an isolated Docker network; never point at app DBs.
if (getenv('CREATE_PG_PROOF') !== '1' || getenv('DB_DATABASE') !== 'create_e2_proof' || getenv('DB_HOST') !== 'create-e2-pg') exit(2);
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB, Schema, Bus, Http, Redis};
use Illuminate\Database\Schema\Blueprint;
use App\Services\Create\{ConversationService, RunService, AttemptService};
use App\Models\{Workspace, User};
config(['database.default'=>'pgsql','cache.default'=>'array','services.posthog.key'=>'','create.enabled'=>true,'create.workspaces'=>[1],'create.mode'=>'fixture','developer.operation_accounting'=>true]);
Bus::fake(); Http::preventStrayRequests(); Redis::shouldReceive('get')->andReturn(null);
$action=$argv[1]??'parent';
if ($action !== 'parent') {
    $state=json_decode(file_get_contents('/tmp/create-race.json'),true);
    while(microtime(true)<$state['start']) usleep(1000);
    try {
        $value=match($action) {
            'approve'=>app(ConversationService::class)->approve(User::findOrFail(1),$state['conversation'],$state['quote'],$state['key'])->id,
            'claim'=>app(RunService::class)->claim(),
            'attempt'=>app(AttemptService::class)->begin($state['run'],$state['lease'],'agent-1','agent',str_repeat('a',64)),
            default=>throw new RuntimeException('Unknown proof action'),
        };
        echo json_encode(['ok'=>true,'value'=>$value]);
    } catch(Throwable $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit(1); }
    exit;
}
function check(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);}
function race(string $action,array $state):array {
    file_put_contents('/tmp/create-race.json',json_encode($state+['start'=>microtime(true)+0.7]));
    $children=[];
    for($i=0;$i<3;$i++){
        $p=proc_open([PHP_BINARY,__FILE__,$action],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);$children[]=[$p,$pipes];
    }
    $results=[];
    foreach($children as [$p,$pipes]){
        $text=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
        check($exit===0,'Concurrent worker failed: '.$text.$errors);
        $results[]=json_decode($text,true,512,JSON_THROW_ON_ERROR)['value'];
    }
    return $results;
}
check(!Schema::hasTable('workspaces'),'Proof database must be empty');
Schema::create('workspaces',function(Blueprint $t){$t->id();foreach(['name','plan_tier','plan_status','plan_source','funding_mode','status'] as $c)$t->string($c)->nullable();$t->bigInteger('parent_workspace_id')->nullable();$t->integer('credits_monthly')->default(0);$t->integer('credits_topup')->default(0);$t->timestamps();});
Schema::create('users',function(Blueprint $t){$t->id();$t->bigInteger('workspace_id')->nullable();foreach(['name','email','role','status'] as $c)$t->string($c)->nullable();$t->timestamps();});
Schema::create('assets',fn(Blueprint $t)=>$t->id());
Schema::create('projects',function(Blueprint $t){$t->id();$t->bigInteger('api_key_id')->nullable();});
Schema::create('credit_ledger',function(Blueprint $t){$t->id();$t->bigInteger('project_id')->nullable();});
Schema::create('api_quotes',function(Blueprint $t){$t->string('id',32)->primary();$t->bigInteger('workspace_id');$t->bigInteger('api_key_id')->nullable();$t->bigInteger('created_by_user_id')->nullable();$t->bigInteger('project_id')->nullable();$t->json('payload_json');$t->integer('credits_min');$t->integer('credits_max');$t->timestamp('expires_at');$t->timestamp('consumed_at')->nullable();$t->string('idempotency_key')->nullable();$t->timestamps();});
foreach(['2026_09_25_200000_create_api_operations.php','2026_09_28_120000_create_composition_conversations.php','2026_09_29_000000_create_composition_attempts.php'] as $migration)(require database_path('migrations/'.$migration))->up();
Workspace::create(['name'=>'Race fixture','status'=>'active','plan_tier'=>'creator','plan_status'=>'active','credits_monthly'=>100]);
$user=User::create(['name'=>'Race','email'=>'race@example.test','role'=>'owner','status'=>'active']);$user->forceFill(['workspace_id'=>1])->save();
$service=app(ConversationService::class);$c=$service->create($user,[]);
$service->message($user,$c->id,['content'=>'Offline race test','expected_version'=>0,'idempotency_key'=>'brief']);
$q=$service->quote($user,$c->id,1);
$approved=race('approve',['conversation'=>$c->id,'quote'=>$q->id,'key'=>'same-approval']);
check(count(array_unique($approved))===1 && DB::table('composition_runs')->count()===1 && DB::table('api_operations')->count()===1,'Duplicate admission');
$claims=race('claim',[]);$claimed=array_values(array_filter($claims));check(count($claimed)===1,'Multiple worker leases issued');
$attempts=race('attempt',['run'=>$claimed[0]['id'],'lease'=>$claimed[0]['lease_token']]);
check(count(array_filter($attempts,fn($a)=>$a['may_execute']))===1 && DB::table('composition_attempts')->count()===1,'Duplicate execution admitted');
app(RunService::class)->cancel(1,$c->id,$claimed[0]['id']);
check(DB::table('composition_runs')->value('status')==='cancel_requested','Cancellation lost');
DB::table('composition_runs')->update(['lease_expires_at'=>now()->subMinute()]);
check(app(RunService::class)->claim()===null && DB::table('composition_runs')->value('status')==='needs_attention','Expiry requeued unknown work');
check(DB::table('api_operations')->value('status')==='needs_attention' && DB::table('composition_attempts')->value('status')==='started','Unknown capacity released');
echo json_encode(['database'=>'PostgreSQL','admissionWorkers'=>3,'claimWorkers'=>3,'attemptWorkers'=>3,'operations'=>1,'attempts'=>1,'cancellationExpiry'=>'held','paidCalls'=>0]).PHP_EOL;
