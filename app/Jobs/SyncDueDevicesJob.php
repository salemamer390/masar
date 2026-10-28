<?php

namespace App\Jobs;

use App\Services\FingerprintAttendanceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncDueDevicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ?int $initiatedBy = null,
        public array $options = []
    ) {}

    /**
     * Execute the job.
     */
    public function handle(FingerprintAttendanceSyncService $syncService): void
    {
        $syncService->syncDueDevices($this->initiatedBy, $this->options);
    }
}
