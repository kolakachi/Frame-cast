<?php
namespace App\Services\Vendors;

/**
 * One reading of every vendor's errors (Claude, Replicate and the models on it, OpenAI, Google), from the status and
 * the error text the vendor sent:
 *   content_refused  the request was declined under the vendor's content rules (E005/E006, a refusal, a policy block)
 *   busy             the vendor is overloaded or rate-limiting; nothing was made, a wait and a retry should work
 *   vendor_credit    our own account with the vendor is out of credit or its billing is off: ours to fix
 *   vendor_config    our key or account settings are wrong (401, 403, an invalid key): ours to fix
 *   other            anything else
 */
final class VendorError
{
    public const KINDS = ['content_refused', 'busy', 'vendor_credit', 'vendor_config', 'other'];
    /** Kinds that mean nothing was made, so the request is known not to have produced (or billed) anything. */
    public const NOTHING_MADE = ['content_refused', 'busy', 'vendor_credit', 'vendor_config'];

    public static function classify(string $text, ?int $status = null): string
    {
        $t = mb_strtolower($text);
        // Our adapters write the create request's HTTP status into the message, "failed to start (402): …". Only those
        // fixed prefixes are read: a number in a quoted script line or file name ("Call (402) 555-…") is not a status,
        // and a failed poll ("poll failed (503)") says nothing about whether the job ran.
        if ($status === null && preg_match('/(?:failed to start|could not start|submit failed) \((\d{3})\)/i', $text, $m)) $status = (int) $m[1];
        if ($status === 402 || preg_match('/credit balance is too low|insufficient[_ ]?(credit|quota|funds|balance)|exceeded your current quota|billing[_ ]?(disabled|not active|hard limit)|billing details|payment required|out of credits?|spend(ing)? limit|account\/billing|add (a )?payment/', $t)) return 'vendor_credit';
        if (in_array($status, [401, 403], true) || preg_match('/invalid[ _-]?(x-)?api[ _-]?key|authentication[_ ]error|permission[_ ]error|unauthenticated|unauthori[sz]ed|api key not valid|invalid (auth(entication)? )?token|incorrect api key|api_key_invalid/', $t)) return 'vendor_config';
        if (preg_match('/\be00[56]\b|flagged as sensitive|sensitive content|content[_ ]policy|safety (system|filter|policy)|moderation[_ ]blocked|content[_ ]filter|\brefusal\b|declined this (image|segment)|moderation flags|nsfw|violat(es|ion of) (our|the) (usage|content)/', $t)) return 'content_refused';
        if (in_array($status, [429, 503, 529], true) || preg_match('/overloaded|rate[ _-]?limit|too many requests|high demand|currently unavailable|temporarily unavailable|resource[_ ]exhausted|at capacity|server is busy|try again later/', $t)) return 'busy';
        return 'other';
    }
}
