<?php

namespace App\Console\Commands;

use App\Services\Create\TemplateAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Records a template's render audit (ops/template_audit.sh runs it; template-audit.mjs writes result.json). Only a
 * passed audit of the template's own code makes it free for a free account's first ad.
 */
class CreateTemplateAudit extends Command
{
    protected $signature = 'create:template-audit {template : a template id} {result : the audit\'s result.json} {--clear : Take the template off the free list}';

    protected $description = 'Record a template\'s render audit (free first ad eligibility)';

    public function handle(): int
    {
        $t = DB::table('create_templates')->where('id', $this->argument('template'))->first();
        if (! $t || $t->source !== 'version' || ! $t->revision_id) { $this->error('No template with its own code by that id.'); return self::FAILURE; }
        if ($this->option('clear')) {
            DB::table('create_templates')->where('id', $t->id)->update(['free_audit_json' => null, 'updated_at' => now()]);
            $this->info("{$t->id}: off the free list.");
            return self::SUCCESS;
        }
        $result = json_decode((string) @file_get_contents($this->argument('result')), true);
        if (! is_array($result) || ! array_key_exists('passed', $result)) { $this->error('That is not an audit result.'); return self::FAILURE; }
        $bundle = json_decode((string) DB::table('composition_revisions')->where('id', $t->revision_id)->value('bundle_json'), true) ?: [];
        // The audit must be of this template's code (asset ids aside), or it says nothing about it.
        if (! hash_equals(TemplateAudit::bundleHash($bundle), (string) ($result['bundle_hash'] ?? ''))) { $this->error('The audit is of different code than this template.'); return self::FAILURE; }
        $kept = array_intersect_key($result, array_flip(['passed', 'fields', 'leftovers', 'unseen', 'overflow', 'bundle_hash', 'at']));
        DB::table('create_templates')->where('id', $t->id)->update(['free_audit_json' => json_encode($kept), 'updated_at' => now()]);
        $this->line(($result['passed'] ? 'PASSED' : 'FAILED')." {$t->id} ({$t->title})");
        foreach (['leftovers' => 'Written into the code', 'unseen' => 'Fields never on screen', 'overflow' => 'Long text runs out of its box'] as $key => $label)
            if (! empty($result[$key])) $this->line("  $label: ".implode(' | ', array_map(fn ($x) => is_array($x) ? ($x['text'] ?? json_encode($x)).(isset($x['at']) ? ' @'.$x['at'].'s' : '') : $x, array_slice($result[$key], 0, 12))));
        return self::SUCCESS;
    }
}
