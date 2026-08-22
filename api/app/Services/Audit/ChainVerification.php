<?php

namespace App\Services\Audit;

final class ChainVerification
{
    private function __construct(
        public readonly bool $valid,
        public readonly int $eventsChecked,
        public readonly ?string $brokenAtEventId = null,
        public readonly ?string $reason = null,
    ) {}

    public static function valid(int $eventsChecked): self
    {
        return new self(true, $eventsChecked);
    }

    public static function broken(int $eventsChecked, string $eventId, string $reason): self
    {
        return new self(false, $eventsChecked, $eventId, $reason);
    }

    public function toArray(): array
    {
        return [
            'valid'             => $this->valid,
            'events_checked'    => $this->eventsChecked,
            'broken_at_event_id' => $this->brokenAtEventId,
            'reason'            => $this->reason,
        ];
    }
}
