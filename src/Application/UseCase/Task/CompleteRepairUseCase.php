<?php

declare(strict_types=1);

namespace App\Application\UseCase\Task;

use App\Domain\WorkTask\WorkTask;

/** O reparo terminou: o serviço de OS é avisado e encerra a saga. */
final class CompleteRepairUseCase extends TaskAction
{
    public function execute(string $osId): WorkTask
    {
        return $this->apply($osId, fn (WorkTask $task) => $task->completeRepair($this->clock->now()));
    }
}
