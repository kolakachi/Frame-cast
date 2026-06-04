<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Lowercase every existing email so authentication is case-insensitive
        // going forward. The User model now applies strtolower() on write via
        // a setEmailAttribute mutator; this migration brings legacy rows in
        // line so a user who originally signed up as Kola@Gmail.com but now
        // types kola@gmail.com still resolves to their account.
        //
        // We log (but don't merge) any duplicates produced by the case-fold —
        // pre-launch volume is small enough that real duplicates can be
        // resolved manually once the application surfaces them.
        $rows = DB::table('users')->select('id', 'email')->whereRaw('email <> LOWER(email)')->get();
        foreach ($rows as $r) {
            try {
                DB::table('users')->where('id', $r->id)->update(['email' => strtolower($r->email)]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Another user already owns the lowercased form. Tag this row
                // so the conflict is visible in admin; don't delete or merge
                // automatically.
                DB::table('users')->where('id', $r->id)->update([
                    'email' => strtolower($r->email) . '+dup-' . $r->id . '@case-collision.invalid',
                ]);
            }
        }
    }

    public function down(): void
    {
        // No down — we don't preserve the original casing.
    }
};
