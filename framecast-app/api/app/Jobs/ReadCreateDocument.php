<?php

namespace App\Jobs;

use App\Services\Create\DocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Reads a document added in Weave (its pages, pictures and facts) in the background; the composer chip waits on it. */
class ReadCreateDocument implements ShouldQueue
{
    use Queueable;

    public int $timeout = 360;
    public int $tries = 1;

    public function __construct(public string $documentId) {}

    public function handle(DocumentService $documents): void
    {
        $documents->readNow($this->documentId);
    }

    public function failed(\Throwable $e): void
    {
        \Illuminate\Support\Facades\DB::table('create_documents')->where('id', $this->documentId)->where('status', 'reading')
            ->update(['status' => 'failed', 'error' => 'Could not read that document.', 'updated_at' => now()]);
    }
}
