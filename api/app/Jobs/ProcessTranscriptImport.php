<?php

namespace App\Jobs;

use App\Models\TranscriptImport;
use App\Services\Transcripts\TranscriptService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Route, file and apply one meeting transcript (spec §24).
 *
 * Queued because a webhook is waiting on the other end: a note-taker's
 * automation holds the request open for a few seconds at most, and routing and
 * reading a meeting are two model calls. The sender gets an import id back
 * immediately and the log fills in when this finishes.
 *
 * On the agents queue, not media, because it is model work — and because
 * filing the transcript dispatches its extraction onto media, which must not be
 * stuck behind the job waiting on it.
 *
 * `tries = 1`. The service records its own failures on the import row and
 * checkpoints each stage, so a person retries from the log and it resumes; an
 * automatic retry would spend a second model call on a failure that is usually
 * not transient, and Laravel's retry knows nothing about the checkpoints.
 */
class ProcessTranscriptImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public readonly string $importId)
    {
        $this->onQueue('agents');
    }

    public function handle(TranscriptService $transcripts): void
    {
        $import = TranscriptImport::find($this->importId);

        if ($import === null) {
            return;
        }

        $transcripts->process($import);
    }
}
