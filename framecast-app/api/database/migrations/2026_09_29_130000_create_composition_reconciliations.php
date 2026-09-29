<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('composition_reconciliations',function(Blueprint $t){
            $t->id();$t->uuid('attempt_id')->unique();$t->string('receipt_hash',64);$t->string('previous_status',24);
            $t->string('previous_result_hash',64)->nullable();$t->text('evidence');$t->timestamp('created_at');
        });
    }
    public function down(): void { Schema::dropIfExists('composition_reconciliations'); }
};
