<?php

namespace App\Jobs;

use App\Models\DataExport;
use App\Notifications\DataExportReady;
use App\Services\Privacy\PersonalDataExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Zipping somebody's whole account, off the request cycle.
 *
 * A seller with two hundred listings is several hundred photographs read off
 * disk and written into an archive. In a web request that is a timeout; in a
 * PHP-FPM worker it is a worker not serving anybody else for a minute. The user
 * gets „we are preparing it" and a notification when it is done.
 *
 * `SerializesModels` is deliberately not used — the job carries the export's id
 * and re-reads the row. A serialised model is a snapshot, and this job can sit
 * in the queue behind a backlog long enough for the row to have changed.
 */
class BuildDataExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 600;

    public function __construct(private readonly int $exportId) {}

    public function handle(PersonalDataExport $exports): void
    {
        $export = DataExport::find($this->exportId);

        if (! $export || $export->status !== DataExport::PENDING) {
            return;  // Cancelled, already built, or the account went.
        }

        $export = $exports->build($export);

        $export->user?->notify(new DataExportReady($export));
    }

    /**
     * A failed export must SAY it failed.
     *
     * Left pending, it looks like a queue that is still working — and the user
     * waits, then asks support, which is the exact outcome this feature exists
     * to prevent. The message is stored so somebody can see why.
     */
    public function failed(Throwable $e): void
    {
        DataExport::where('id', $this->exportId)
            ->where('status', DataExport::PENDING)
            ->update([
                'status' => DataExport::FAILED,
                'error'  => mb_substr($e->getMessage(), 0, 240),
            ]);
    }
}
