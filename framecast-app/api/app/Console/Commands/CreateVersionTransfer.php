<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Create\VersionTransfer;
use Illuminate\Console\Command;

/**
 * Move a finished Create version between installs, or make a template start from a version (TPL-LOCAL, 2026-10-16).
 *   create:version export {revision} --dir=/tmp/x        write the version's code, files, plan and video
 *   create:version import {dir} --user=1 [--template=id]  recreate it in that staff user's workspace
 *   create:version link {revision} --user=1 --template=id a version already here becomes the template's source
 */
class CreateVersionTransfer extends Command
{
    protected $signature = 'create:version {action : export, import or link} {target : a revision id, or an export folder} {--dir=} {--user=1} {--template=}';
    protected $description = 'Export, import or link a finished Create version (templates from another install)';

    public function handle(VersionTransfer $transfer): int
    {
        $user = User::findOrFail((int) $this->option('user'));
        $result = match ($this->argument('action')) {
            'export' => $transfer->export($this->argument('target'), (string) ($this->option('dir') ?: sys_get_temp_dir().'/version-'.substr($this->argument('target'), 0, 8))),
            'import' => $transfer->import($this->argument('target'), $user, $this->option('template') ?: null),
            'link' => (function () use ($transfer, $user) {
                $r = \Illuminate\Support\Facades\DB::table('composition_revisions')->where('id', $this->argument('target'))->firstOrFail();
                return $transfer->link($user, (string) $this->option('template'), $r->conversation_id, $r->id);
            })(),
            default => null,
        };
        if ($result === null) { $this->error('Use export, import or link.'); return self::FAILURE; }
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
