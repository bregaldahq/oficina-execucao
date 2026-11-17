<?php

declare(strict_types=1);

namespace App\Domain\WorkTask;

/** Uma linha da linha do tempo da tarefa: o que aconteceu, quando e com qual detalhe. */
final class TimelineEntry
{
    public function __construct(
        public readonly string $type,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly ?string $detail = null,
    ) {
    }

    /** @return array{type: string, occurred_at: string, detail: ?string} */
    public function toArray(): array
    {
        return [
            'type'        => $this->type,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.v\Z'),
            'detail'      => $this->detail,
        ];
    }
}
