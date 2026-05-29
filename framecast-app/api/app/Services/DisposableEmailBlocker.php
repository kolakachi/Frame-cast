<?php

namespace App\Services;

/**
 * Curated list of disposable / throwaway email domains.
 *
 * Covers the ~80 most common providers used in mass-signup abuse. Not exhaustive
 * (the full disposable-email-domains project has ~4k entries) but blocks the
 * lion's share of real-world abuse without a runtime API call.
 *
 * Keep alphabetized when adding entries.
 */
class DisposableEmailBlocker
{
    private const DOMAINS = [
        '0-mail.com',
        '10minutemail.com',
        '10minutemail.net',
        '20minutemail.com',
        'anonymbox.com',
        'binkmail.com',
        'bobmail.info',
        'bouncr.com',
        'bspamfree.org',
        'burnermail.io',
        'byom.de',
        'chacuo.net',
        'cool.fr.nf',
        'courriel.fr.nf',
        'dayrep.com',
        'discard.email',
        'discardmail.com',
        'discardmail.de',
        'dispomail.eu',
        'disposable.com',
        'disposablemail.com',
        'dispostable.com',
        'dodgeit.com',
        'dontreg.com',
        'dropmail.me',
        'duck.com', // duck.com is duckduckgo's email — legit but used for disposable in practice; remove if it blocks real users
        'easytrashmail.com',
        'emailfake.com',
        'emailondeck.com',
        'emailsensei.com',
        'emailtemporal.org',
        'fakeinbox.com',
        'fakemailgenerator.com',
        'fakemail.fr',
        'getairmail.com',
        'getnada.com',
        'grr.la',
        'guerrillamail.biz',
        'guerrillamail.com',
        'guerrillamail.de',
        'guerrillamail.info',
        'guerrillamail.net',
        'guerrillamail.org',
        'guerrillamailblock.com',
        'harakirimail.com',
        'hidemail.de',
        'inboxalias.com',
        'inboxbear.com',
        'jetable.org',
        'mail-temp.com',
        'mail-temporaire.fr',
        'mail7.io',
        'mailbox.in.ua',
        'mailcatch.com',
        'maildrop.cc',
        'mailexpire.com',
        'mailforspam.com',
        'mailinator.com',
        'mailinator.net',
        'mailmoat.com',
        'mailnesia.com',
        'mailnull.com',
        'mailtothis.com',
        'meltmail.com',
        'mintemail.com',
        'mohmal.com',
        'moakt.com',
        'mt2014.com',
        'mvrht.net',
        'mytrashmail.com',
        'no-spam.ws',
        'nowmymail.com',
        'odaymail.com',
        'pjjkp.com',
        'prtnx.com',
        'rcpt.at',
        'sharklasers.com',
        'shitware.nl',
        'sogetthis.com',
        'spam4.me',
        'spambog.com',
        'spambox.us',
        'spamgourmet.com',
        'spamhole.com',
        'tempemail.co',
        'tempmail.com',
        'tempmail.io',
        'tempmail.org',
        'tempmailaddress.com',
        'tempmail.dev',
        'tempr.email',
        'temp-mail.io',
        'temp-mail.org',
        'temp-mail.ru',
        'temporaryforwarding.com',
        'thrma.com',
        'throwawaymail.com',
        'tmpmail.org',
        'trashmail.com',
        'trashmail.de',
        'trashmail.fr',
        'trashmail.io',
        'trashmail.net',
        'trbvm.com',
        'tyldd.com',
        'uplipht.com',
        'wegwerfmail.de',
        'wegwerfmail.info',
        'wegwerfmail.net',
        'wegwerfmail.org',
        'yopmail.com',
        'yopmail.fr',
        'yopmail.net',
    ];

    public static function isDisposable(string $email): bool
    {
        $email  = strtolower(trim($email));
        $atPos  = strrpos($email, '@');
        if ($atPos === false) return false;
        $domain = substr($email, $atPos + 1);
        return in_array($domain, self::DOMAINS, true);
    }
}
