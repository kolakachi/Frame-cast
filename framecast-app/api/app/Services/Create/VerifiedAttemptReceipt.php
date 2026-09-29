<?php
namespace App\Services\Create;

/** Only constructed by the trusted reconciliation path, never from an HTTP body. */
final readonly class VerifiedAttemptReceipt
{
    public function __construct(public string $attemptId, public string $status, public ?string $predictionId,
        public int $costMicrousd, public string $evidence) {}
    public function result(): array { return ['status'=>$this->status,'prediction_id'=>$this->predictionId,'cost_microusd'=>$this->costMicrousd]; }
}
