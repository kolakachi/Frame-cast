<p>The nightly credit reconciliation found something that does not add up.</p>

<p>Balances are not affected by this check — it only reads. But a discrepancy
means either a credit movement was not recorded, or a record exists for one
that never happened, and the first of those has already cost a customer their
purchase history once.</p>

<pre style="font-family:ui-monospace,Menlo,monospace;font-size:12px;line-height:1.5;white-space:pre-wrap;background:#f6f6f7;padding:12px;border-radius:6px">{{ $report }}</pre>

<p>Run <code>php artisan credits:verify</code> for the live picture, or
<code>php artisan credits:verify --workspace=ID</code> to narrow it.</p>
