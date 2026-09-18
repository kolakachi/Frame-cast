#!/usr/bin/env php
<?php
/**
 * Fails if a Vue file prints a credit price of its own.
 *
 * A price typed into a template is a copy of the pricing, and copies drift.
 * They had: the music panel advertised "Costs 3 credits" on one line and
 * "Generate music (5 cr)" on the next while the server charged 2, and every
 * one of the five animation tiers over-quoted — seedance lite said 100 and
 * charged 30. Nobody was over-billed, because the drift happened to run in
 * the customer's favour. Nothing about typing the number there made that
 * likely.
 *
 * Prices belong to the server: GET /credit-costs serves them.
 *
 *   php framecast-app/tools/check-prices.php
 *
 * Lives here rather than in tests/ because the api container has no web/src,
 * so as a PHPUnit test it silently skipped in the one place tests are run —
 * a guard that skips is worse than none, it just moves the false confidence.
 */

// Numbers that are not per-operation costs: lifetime tiers and plan inclusions.
// "12,000 credits" is what the deal contains, not what an action costs.
const NOT_OPERATION_COSTS = ['RegisterView.vue', 'PlansView.vue'];

// WEB_SRC lets the script be pointed at a fixture to prove it still bites.
$root = getenv('WEB_SRC') ?: dirname(__DIR__).'/web/src';
if (! is_dir($root)) {
    fwrite(STDERR, "cannot find {$root}\n");
    exit(2);
}

/**
 * Prose about pricing is not pricing — and the comments explaining this very
 * bug name the old wrong numbers. Failing on those would teach people to
 * delete the explanation.
 *
 * Judged per line, deliberately. A block-comment stripper run over a whole
 * .vue file is worse than useless: some `/*` in the script pairs with a `*\/`
 * hundreds of lines later and silently blanks everything between, which is how
 * the first version of this script passed a file that plainly said
 * "Costs 5 credits".
 */
function isCommentLine(string $line): bool
{
    $t = ltrim($line);

    return $t === ''
        || str_starts_with($t, '//')
        || str_starts_with($t, '/*')
        || str_starts_with($t, '*')
        || str_starts_with($t, '<!--');
}

$offenders = [];
foreach (['views', 'components'] as $dir) {
    foreach (glob("{$root}/{$dir}/*.vue") ?: [] as $path) {
        if (in_array(basename($path), NOT_OPERATION_COSTS, true)) {
            continue;
        }
        $lines = explode("\n", (string) file_get_contents($path));
        foreach ($lines as $i => $line) {
            if (isCommentLine($line)) {
                continue;
            }
            if (preg_match('/\b\d[\d,]*\s*(?:cr\b|credits?\b)/i', $line, $m)) {
                $offenders[] = sprintf('  %s:%d  %s', basename($path), $i + 1, trim($m[0]));
            }
        }
    }
}

if ($offenders === []) {
    echo "ok — no credit prices hardcoded in templates\n";
    exit(0);
}

fwrite(STDERR, "Credit prices found in templates. Read them from /credit-costs instead:\n\n"
    ."".implode("\n", $offenders)."\n\n"
    ."If a number genuinely is not an operation cost, add its file to\n"
    ."NOT_OPERATION_COSTS in this script, with a reason.\n");
exit(1);
