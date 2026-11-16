<?php

declare(strict_types=1);

namespace App\Application\UseCase\Task;

use App\Domain\WorkTask\WorkTask;

/** O mecânico começou o reparo de uma tarefa já paga. */
final class StartRepairUseCase extends TaskAction
{
    public function execute(string $osId): WorkTask
    {
        return $this->apply($osId, fn (WorkTask $task) => $task->startRepair($this->clock->now()));
    }
}
