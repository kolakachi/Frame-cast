<?php

namespace App\Console\Commands;

use App\Services\Create\AdmissionControl;
use Illuminate\Console\Command;

class DrainCreate extends Command
{
    protected $signature = 'create:drain {action=status : status, pause, resume, wait or quiet} {--reason= : Operator maintenance note, no secrets} {--timeout=300 : Maximum wait in seconds (0-1800)}';
    protected $description = 'Pause new Create admissions while preserving active worker callbacks and settlement';

    public function handle(AdmissionControl $control): int
    {
        $action = $this->argument('action');
        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1800]]);
        if (! in_array($action, ['status', 'pause', 'resume', 'wait', 'quiet'], true) || mb_strlen((string) $this->option('reason')) > 240 || $timeout === false) {
            $this->error('Use status, pause, resume, wait or quiet; reason at most 240 characters, timeout 0-1800 seconds.');
            return self::FAILURE;
        }
        // A deploy waits only for work a restart would cut: running builds (their lease runs through the API) and running
        // plans. Work already needing attention or uncertain is not touched by a restart, so it never holds a deploy.
        if ($action === 'quiet') {
            $deadline = hrtime(true) + $timeout * 1_000_000_000;
            do {
                $status = $control->status();
                $busy = (int) ($status['builds']['running'] ?? 0) + (int) ($status['builds']['cancel_requested'] ?? 0) + (int) ($status['planning']['running'] ?? 0);
                if ($busy === 0 || hrtime(true) >= $deadline) {
                    $this->line(json_encode(['quiet' => $busy === 0, 'builds' => $status['builds'] ?? null, 'planning' => $status['planning'] ?? null, 'paused' => $status['draining'] ?? null], JSON_UNESCAPED_SLASHES));
                    return $busy === 0 ? self::SUCCESS : self::FAILURE;
                }
                sleep(5);
            } while (true);
        }
        if ($action === 'wait') {
            $deadline = hrtime(true) + $timeout * 1_000_000_000;
            do {
                $status = $control->status();
                $blockers = AdmissionControl::drainBlockers($status);
                if (! $blockers || hrtime(true) >= $deadline || ! $status['controls_enabled'] || ! $status['draining'] || ! $status['durable_planning']) {
                    $this->line(json_encode($status + ['journal_drained' => ! $blockers, 'blockers' => $blockers], JSON_UNESCAPED_SLASHES));
                    return $blockers ? self::FAILURE : self::SUCCESS;
                }
                usleep((int) min(1_000_000, max(0, ($deadline - hrtime(true)) / 1000)));
            } while (true);
        }
        if (in_array($action, ['pause', 'resume'], true)) $control->setPaused($action === 'pause', $this->option('reason') ?: null);
        $this->line(json_encode($control->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
