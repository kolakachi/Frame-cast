<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

// Social tokens were stored readable, and database dumps were briefly public (L16, 2026-10-08). Encrypt them at rest;
// the model casts them as encrypted. A value that already decrypts is left alone, so running this twice is safe.
return new class extends Migration
{
    public function up(): void
    {
        $this->each(fn (?string $v) => $v === null || $this->decrypts($v) ? $v : Crypt::encryptString($v));
    }

    public function down(): void
    {
        $this->each(fn (?string $v) => $v !== null && $this->decrypts($v) ? Crypt::decryptString($v) : $v);
    }

    private function each(\Closure $f): void
    {
        foreach (DB::table('social_accounts')->orderBy('id')->get(['id', 'access_token', 'refresh_token']) as $row) {
            DB::table('social_accounts')->where('id', $row->id)->update(['access_token' => $f($row->access_token) ?? '', 'refresh_token' => $f($row->refresh_token)]);
        }
    }

    private function decrypts(string $v): bool
    {
        try { Crypt::decryptString($v); return true; } catch (DecryptException) { return false; }
    }
};
