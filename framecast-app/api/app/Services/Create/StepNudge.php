<?php

namespace App\Services\Create;

use Illuminate\Support\Facades\DB;

/**
 * "Continue" typed while a character or storyboard waits for the user's OK (workspace 82, 2026-10-15: "continue fazendo
 * o video" planned the video again for 71 credits and stopped at the same character). The step has its own priced
 * Approve button; a message that only says to go on is answered by pointing at it, in the user's language, and no
 * planning turn follows.
 */
final class StepNudge
{
    /** A short message that only says to carry on, in English, Portuguese or Spanish. */
    public static function isGoOn(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '' || mb_strlen($t) > 60) return false;
        return (bool) preg_match('/^(ok(ay)?|sim|s[ií]|yes|yep|sure|go( on| ahead)?|next|carry on|keep going|continue|continua|continu[ae]r|contin[uú]a|siga|segue|seguir|prossiga|prosseguir|avan[cç]a|avan[cç]ar|aprov(o|ar|ado)|approved?|pode( seguir| continuar| fazer)?|manda ver|vai|bora|dale|adelante|make (it|the video)|fa[cç]a o v[ií]deo|continue (fazendo|making|with|the)\b.*)[\s.!]*$/u', $t);
    }

    /** The waiting step ('character' or 'storyboard') of the latest proposed, unbuilt plan; null when none waits. */
    public static function waiting(object $c): ?string
    {
        $plan = DB::table('create_plans')->where('conversation_id', $c->id)->orderByDesc('created_at')->first();
        if (! $plan || $plan->status !== 'proposed' || PlanService::built($plan->id)) return null;
        $present = app(PlanService::class)->present($plan, $c);
        foreach (['character' => 'character_preview', 'storyboard' => 'storyboard_preview'] as $step => $key) {
            $look = $present[$key] ?? null;
            if ($look && ! empty($look['images']) && empty($look['approved'])) return $step;
        }
        return null;
    }

    public static function reply(string $step, string $userText): string
    {
        // Words only Portuguese uses: "continue" alone is English.
        $pt = (bool) preg_match('/\b(fazendo|sim|pode|segue|seguir|prossiga|aprovo|aprovar|aprovado|v[ií]deo\b.*\bo\b|vai|bora|avan[cç]a|avan[cç]ar|continua|continuar|vamos|n[ãa]o)\b/u', mb_strtolower($userText));
        if ($pt) {
            return $step === 'character'
                ? 'O personagem está esperando a sua aprovação. Toque em **Approve** logo abaixo dele para continuar: o preço aparece antes de qualquer cobrança. Se quiser mudar algo no personagem, me diga o que mudar.'
                : 'O storyboard está esperando a sua aprovação. Toque em **Approve · make video** logo abaixo dele para fazer o vídeo: o preço aparece antes de qualquer cobrança. Se quiser mudar uma cena, me diga qual.';
        }
        return $step === 'character'
            ? 'The character is waiting for your OK. Press **Approve** under it to carry on: you see the price before anything is charged. To change the character, tell me what to change.'
            : 'The storyboard is waiting for your OK. Press **Approve · make video** under it to make the video: you see the price before anything is charged. To change a frame, tell me which.';
    }
}
