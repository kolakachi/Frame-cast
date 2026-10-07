<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What each attached file is and what the user wants from it, read once when the brief is sent: its kind (logo,
// product photo, clip...), its intended use in the user's words, the moment of their current video it shows (a
// screenshot of a frame), and a question to ask when the prompt leaves its use genuinely open.
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('create_attachments', 'notes_json')) Schema::table('create_attachments', fn (Blueprint $t) => $t->json('notes_json')->nullable());
    }

    public function down(): void
    {
        if (Schema::hasColumn('create_attachments', 'notes_json')) Schema::table('create_attachments', fn (Blueprint $t) => $t->dropColumn('notes_json'));
    }
};
