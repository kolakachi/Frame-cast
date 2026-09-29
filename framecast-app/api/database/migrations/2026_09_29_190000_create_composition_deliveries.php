<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('composition_revisions',function(Blueprint $t){$t->string('share_token',64)->nullable()->unique();$t->boolean('share_enabled')->default(false);});
  Schema::create('composition_deliveries',function(Blueprint $t){$t->id();$t->uuid('revision_id');$t->string('idempotency_key',128);$t->string('request_hash',64);$t->json('response_json');$t->timestamps();$t->unique(['revision_id','idempotency_key']);});
 }
 public function down(): void {Schema::dropIfExists('composition_deliveries');Schema::table('composition_revisions',fn(Blueprint $t)=>$t->dropColumn(['share_token','share_enabled']));}
};
