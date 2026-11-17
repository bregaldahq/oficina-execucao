<?php

declare(strict_types=1);

namespace App\Application\UseCase\Task;

use App\Application\Port\WorkTaskRepositoryInterface;
use App\Domain\Exception\NotFoundException;
use App\Domain\WorkTask\TimelineEntry;

final class GetTaskUseCase
{
    public function __construct(private readonly WorkTaskRepositoryInterface $tasks)
    {
    }

    /** @return array<string, mixed> a tarefa com a linha do tempo */
    public function execute(string $osId): array
    {
        $task = $this->tasks->find($osId);
        if ($task === null) {
            throw new NotFoundException('Tarefa não encontrada.');
        }

        return $task->toArray() + [
            'timeline' => array_map(
                static fn (TimelineEntry $entry): array => $entry->toArray(),
                $this->tasks->timeline($osId),
            ),
        ];
    }
}
