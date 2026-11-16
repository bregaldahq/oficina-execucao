<?php

declare(strict_types=1);

namespace App\Application\UseCase\Task;

use App\Application\Port\WorkTaskRepositoryInterface;
use App\Domain\Exception\DomainException;
use App\Domain\WorkTask\TaskStatus;
use App\Domain\WorkTask\WorkTask;

/** A fila da oficina: as tarefas de um status, as mais antigas primeiro. */
final class ListTasksUseCase
{
    public function __construct(private readonly WorkTaskRepositoryInterface $tasks)
    {
    }

    /** @return list<array<string, mixed>> */
    public function execute(string $status = 'QUEUED_DIAGNOSIS'): array
    {
        $parsed = TaskStatus::tryFrom(strtoupper($status));
        if ($parsed === null) {
            throw new DomainException("Status desconhecido: {$status}.");
        }

        return array_map(static fn (WorkTask $task): array => $task->toArray(), $this->tasks->listByStatus($parsed));
    }
}
