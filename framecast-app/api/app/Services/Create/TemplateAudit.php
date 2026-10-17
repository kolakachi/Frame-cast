<?php
namespace App\Services\Create;

/**
 * The render audit of a template (template-audit.mjs): whether a copy can be made for another product by changing its
 * fields only. A result belongs to one version's code, matched by a hash that ignores asset ids (a version moved
 * between installs gets new ones), so an audit made locally can be recorded on prod.
 */
class TemplateAudit
{
    /** Same as bundleHash in template-audit.mjs. */
    public static function bundleHash(array $bundle): string
    {
        $names = array_keys(array_filter($bundle, 'is_string'));
        sort($names, SORT_STRING);
        $parts = array_map(fn ($n) => $n."\n".preg_replace('/asset-\d+-([0-9a-f]{64})/', 'asset-$1', $bundle[$n]), $names);
        return hash('sha256', implode("\n\0\n", $parts));
    }
}
