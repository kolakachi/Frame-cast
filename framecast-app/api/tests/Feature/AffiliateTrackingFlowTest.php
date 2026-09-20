<?php
namespace Tests\Feature;

use App\Models\{Affiliate, AffiliateClick, AffiliateConversion, User, Workspace};
use App\Services\Affiliate\AffiliateAttribution;
use App\Services\KelviqService;
use App\Http\Controllers\Api\V1\Billing\BillingController;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Tests\Support\BuildsAffiliateSchema;
use Tests\TestCase;

class AffiliateTrackingFlowTest extends TestCase
{
    use BuildsAffiliateSchema;
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAffiliateSchema('affiliate_flow');
        Affiliate::create(['code'=>'partner','name'=>'Partner','status'=>'active','commission_percent'=>50]);
        Http::preventStrayRequests();
        config(['billing.kelviq.server_api_key'=>'test','billing.kelviq.api_base'=>'https://billing.example']);
        Http::fake(['*/checkout/' => Http::response(['checkoutUrl'=>'https://billing.example/pay']), '*' => Http::response([],404)]);
    }
    public function test_marketing_and_app_handoff_and_retries_count_one_arrival(): void
    {
        $body = ['code'=>'partner','event_id'=>'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa','landing_path'=>'/'];
        $this->postJson('/api/v1/affiliate/click',$body)->assertOk()->assertJsonPath('data.tracked',true)->assertCookie('wyv_aff');
        $body['landing_path']='/register';
        $this->postJson('/api/v1/affiliate/click',$body)->assertOk();
        $this->assertSame(1,AffiliateClick::count());
        $this->assertSame('/',AffiliateClick::first()->landing_path);
        $body['event_id']='bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb';
        $this->postJson('/api/v1/affiliate/click',$body)->assertOk();
        $this->assertSame(2,AffiliateClick::count());
    }
    public function test_checkout_persists_cookie_attribution_and_sends_it_to_provider(): void
    {
        Schema::table('workspaces',function(Blueprint $t) {
            $t->string('pending_checkout_plan')->nullable();
            $t->timestamp('pending_checkout_at')->nullable();
            $t->timestamp('pending_checkout_reminded_at')->nullable();
        });
        config(['billing.kelviq.plan_tiers'=>['plan-test'=>'starter']]);
        $workspace=Workspace::create(['name'=>'Buyer']);
        $user=new User(['workspace_id'=>$workspace->id]);
        $request=Request::create('/checkout','POST',['plan'=>'starter'],['wyv_aff'=>'partner']);
        $request->setUserResolver(fn()=>$user);
        $response=app(BillingController::class)->kelviqCheckout($request,app(KelviqService::class));
        $this->assertSame(200,$response->status());
        $this->assertSame('partner',$workspace->fresh()->affiliate_code);
        Http::assertSent(fn($r)=>$r->url()==='https://billing.example/checkout/' && $r['metadata']['affiliate_code']==='partner');
        Affiliate::create(['code'=>'other','name'=>'Other','status'=>'active']);
        app(AffiliateAttribution::class)->attributeWorkspace($workspace->fresh(),'other');
        $this->assertSame('partner',$workspace->fresh()->affiliate_code);
    }
    public function test_conversion_insert_failure_is_not_silently_acknowledged(): void
    {
        AffiliateConversion::creating(function () { throw new \RuntimeException('simulated database outage'); });
        try {
            app(AffiliateAttribution::class)->recordConversion(
                Affiliate::first(), 'checkout_metadata', null, null, 'order-failed', null, 100,
            );
            $this->fail('Insertion failures must propagate to the webhook');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated database outage', $e->getMessage());
            $this->assertSame(0, AffiliateConversion::count());
        } finally {
            AffiliateConversion::flushEventListeners();
        }
    }

    public function test_failed_commission_can_be_recovered_even_when_entitlements_were_already_processed(): void
    {
        Schema::create('processed_webhook_events', function(Blueprint $t) {
            $t->id();$t->string('provider');$t->string('event_id')->unique();$t->string('type')->nullable();$t->timestamp('processed_at');$t->timestamps();
        });
        DB::table('processed_webhook_events')->insert(['provider'=>'kelviq','event_id'=>'evt-paid','processed_at'=>now()]);
        $event=['id'=>'evt-paid','type'=>'checkout.completed','data'=>['object'=>[
            'id'=>'order-paid','amount'=>100,'metadata'=>['affiliate_code'=>'partner'],
            // An existing receipt must prevent this top-up path from running again.
            'plan'=>['identifier'=>'topup-test'],
        ]]];
        Schema::rename('affiliate_conversions','saved_conversions');
        try {
            app(KelviqService::class)->handleEvent($event);
            $this->fail('A lost commission must cause a retryable failure');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame(1,DB::table('processed_webhook_events')->count());
        }
        Schema::rename('saved_conversions','affiliate_conversions');
        app(KelviqService::class)->handleEvent($event);
        app(KelviqService::class)->handleEvent($event);
        $this->assertSame(1,AffiliateConversion::count());
        $this->assertSame('order-paid',AffiliateConversion::first()->order_id);
    }
}
