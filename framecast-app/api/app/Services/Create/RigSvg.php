<?php
namespace App\Services\Create;

/**
 * An uploaded character SVG, made safe to place inside a composition and checked against the prepared-rig
 * contract (kit/mascot.md): one head, two eyes each holding its pupil, and four different mouth drawings, all
 * inside the head. Designers export layer names as ids ("head", "left-eye", "mouth-smile"); those are mapped
 * onto the data-rig-part / data-rig-mouth attributes the rig reads. Scripts, embedded documents, animation
 * elements, event handlers and links outside the file are removed.
 */
class RigSvg
{
    public const MAX_BYTES = 3_000_000;
    private const PARTS = ['head', 'left-eye', 'right-eye', 'left-pupil', 'right-pupil'];
    private const MOUTHS = ['rest', 'smile', 'open', 'round'];
    private const DROP = ['script', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video', 'animate', 'animatetransform', 'animatemotion', 'set', 'handler', 'listener'];

    /** Whether these bytes are an SVG document (by content: file type sniffing often reports XML or text). */
    public static function looksLike(string $bytes): bool
    {
        $head = ltrim(substr($bytes, 0, 2048));
        return (bool) preg_match('/^(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<svg[\s>]/is', $head);
    }

    /** @return array{svg: string, rig: array{ready: bool, problems: string[], mouths: string[]}} */
    public static function prepare(string $bytes): array
    {
        abort_if(strlen($bytes) > self::MAX_BYTES, 422, 'Character SVGs can be up to 3 MB.');
        abort_if(preg_match('/<!DOCTYPE|<!ENTITY/i', $bytes), 422, 'This SVG declares a document type; export it again without one.');
        $doc = new \DOMDocument();
        $old = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($bytes, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors(); libxml_use_internal_errors($old);
        abort_unless($ok && $doc->documentElement && strtolower($doc->documentElement->localName) === 'svg', 422, 'That file is not a readable SVG.');
        $root = $doc->documentElement;

        // Remove anything that runs, loads or animates by itself.
        $all = iterator_to_array($doc->getElementsByTagName('*'));
        foreach ($all as $el) {
            if (in_array(strtolower($el->localName), self::DROP, true)) { $el->parentNode?->removeChild($el); continue; }
            if (strtolower($el->localName) === 'style' && preg_match('/@import|url\(\s*["\']?(?!#)/i', $el->textContent)) { $el->parentNode?->removeChild($el); continue; }
            foreach (iterator_to_array($el->attributes ?? []) as $attr) {
                $name = strtolower($attr->nodeName);
                if (str_starts_with($name, 'on')) $el->removeAttributeNode($attr);
                elseif (in_array($name, ['href', 'xlink:href'], true) && ! preg_match('#^(\#|data:image/(png|jpeg|webp);base64,)#i', trim($attr->value))) $el->removeAttributeNode($attr);
                elseif ($name === 'style' && preg_match('/url\(\s*["\']?(?!#)/i', $attr->value)) $el->removeAttributeNode($attr);
            }
        }
        if (! $root->hasAttribute('viewBox') && is_numeric($root->getAttribute('width')) && is_numeric($root->getAttribute('height'))) {
            $root->setAttribute('viewBox', '0 0 '.(float) $root->getAttribute('width').' '.(float) $root->getAttribute('height'));
        }

        // Layer names to rig parts: "Left Eye", "left_eye" and Illustrator's "left_x2D_eye" all mean left-eye.
        $norm = fn ($id) => trim(preg_replace('/[\s_]+/', '-', str_replace('_x2d_', '-', strtolower($id))), '-');
        $parts = []; $mouths = [];
        foreach ($doc->getElementsByTagName('*') as $el) {
            $id = $el->getAttribute('id');
            $part = $el->getAttribute('data-rig-part') ?: (in_array($norm($id), self::PARTS, true) ? $norm($id) : '');
            $mouth = $el->getAttribute('data-rig-mouth') ?: (preg_match('/^mouth-(rest|smile|open|round)$/', $norm($id), $m) ? $m[1] : '');
            if ($part) { $el->setAttribute('data-rig-part', $part); $parts[$part][] = $el; }
            if ($mouth) { $el->setAttribute('data-rig-mouth', $mouth); $mouths[$mouth][] = $el; }
        }
        $problems = [];
        foreach (self::PARTS as $p) {
            $n = count($parts[$p] ?? []);
            if ($n !== 1) $problems[] = $n ? "More than one \"{$p}\" layer." : "No \"{$p}\" layer.";
        }
        foreach (self::MOUTHS as $m) {
            $n = count($mouths[$m] ?? []);
            if ($n !== 1) $problems[] = $n ? "More than one \"mouth-{$m}\" layer." : "No \"mouth-{$m}\" layer.";
            elseif (! self::draws($mouths[$m][0])) $problems[] = "The \"mouth-{$m}\" layer is empty.";
        }
        $inside = fn ($el, $parent) => $el && $parent && self::within($el, $parent);
        $head = $parts['head'][0] ?? null;
        foreach (['left-eye', 'right-eye'] as $p) if (isset($parts[$p][0]) && $head && ! $inside($parts[$p][0], $head)) $problems[] = "The \"{$p}\" layer must be inside \"head\".";
        foreach (['left', 'right'] as $side) if (isset($parts["{$side}-pupil"][0], $parts["{$side}-eye"][0]) && ! $inside($parts["{$side}-pupil"][0], $parts["{$side}-eye"][0])) $problems[] = "The \"{$side}-pupil\" layer must be inside \"{$side}-eye\".";
        foreach (self::MOUTHS as $m) if (isset($mouths[$m][0]) && $head && ! $inside($mouths[$m][0], $head)) $problems[] = "The \"mouth-{$m}\" layer must be inside \"head\".";

        return ['svg' => $doc->saveXML($root), 'rig' => ['ready' => ! $problems, 'problems' => array_slice($problems, 0, 12), 'mouths' => array_keys($mouths)]];
    }

    private static function within(\DOMNode $el, \DOMNode $parent): bool
    {
        for ($n = $el->parentNode; $n; $n = $n->parentNode) if ($n->isSameNode($parent)) return true;
        return false;
    }

    /** A layer draws something: it holds at least one shape, path, image or text. */
    private static function draws(\DOMElement $el): bool
    {
        foreach ($el->getElementsByTagName('*') as $c) if (in_array(strtolower($c->localName), ['path', 'circle', 'ellipse', 'rect', 'polygon', 'polyline', 'line', 'image', 'text', 'use'], true)) return true;
        return in_array(strtolower($el->localName), ['path', 'circle', 'ellipse', 'rect', 'polygon', 'polyline', 'line', 'image', 'use'], true);
    }
}
