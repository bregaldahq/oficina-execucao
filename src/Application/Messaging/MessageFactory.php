<?php

declare(strict_types=1);

namespace App\Application\Messaging;

use App\Application\Port\ClockInterface;
use App\Domain\Messaging\Message;
use App\Domain\UuidGeneratorInterface;
use App\Domain\WorkTask\WorkTask;

/** Transforma os eventos que a tarefa registrou nos envelopes que vão para a outbox. */
final class MessageFactory
{
    public const PRODUCER = 'execucao';

    public function __construct(
        private readonly UuidGeneratorInterface $uuid,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return list<Message> */
    public function fromTask(WorkTask $task): array
    {
        $messages = [];
        foreach ($task->releaseEvents() as $event) {
            $messages[] = new Message(
                $this->uuid->generate(),
                $event['type'],
                self::PRODUCER,
                $task->getSagaId(),
                $task->getOsId(),
                $this->clock->now()->format('Y-m-d\TH:i:s.v\Z'),
                $task->getCorrelationId(),
                $event['payload'],
            );
        }

        return $messages;
    }
}
