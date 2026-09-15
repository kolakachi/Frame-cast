<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client workspaces under an agency.
 *
 * An agency buys once and works for several clients, each of whom needs their
 * own projects, characters and brand kept apart from the others. Sharing one
 * workspace would mix them; separate accounts would mean separate purchases.
 *
 * A child is a full workspace in every respect except money: it points at the
 * agency that owns it, and every credit question resolves to that parent. One
 * pool, because the agency bought the credits and one balance is the only
 * version an agency can reason about at a glance.
 *
 * Deliberately one level deep. A client of a client is not a thing anyone has
 * asked for, and a tree makes every credit lookup recursive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_workspace_id')->nullable()->after('id');
            // What the agency calls this client. The workspace name is what the
            // client would see; this is for the switcher.
            $table->string('client_label', 120)->nullable()->after('parent_workspace_id');
            $table->index('parent_workspace_id');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropIndex(['parent_workspace_id']);
            $table->dropColumn(['parent_workspace_id', 'client_label']);
        });
    }
};
