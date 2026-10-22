<?php

namespace App\Services\Launch;

use App\Mail\LaunchpadPurchaseMail;
use App\Models\LaunchPurchase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Purchase access is delivered by the payment webhook, independently of any browser redirect. */
class LaunchpadAccessMail
{
    public static function queueOnce(LaunchPurchase $purchase): void
    {
        if ($purchase->status !== 'active') {
            return;
        }

        // A receipt can contain a main product and a bump. The unique receipt key makes their claim atomic,
        // including concurrent webhook redeliveries. New receipts still notify existing customers.
        $claimed = DB::table('launch_access_emails')->insertOrIgnore([
            'receipt' => $purchase->receipt,
            'queued_at' => now(),
        ]);
        if ($claimed === 0) {
            return;
        }

        try {
            Mail::to($purchase->email)->queue(new LaunchpadPurchaseMail(
                purchaseEmail: $purchase->email,
                productName: LaunchCatalogue::product($purchase->product)['name'] ?? 'WyvStudio',
                receipt: $purchase->receipt,
            ));
        } catch (\Throwable $error) {
            DB::table('launch_access_emails')->where('receipt', $purchase->receipt)->delete();
            // Keep the paid account and credits, but return a failed webhook so Launchpad retries the email.
            throw $error;
        }
    }
}
