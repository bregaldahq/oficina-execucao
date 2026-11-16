<?php

declare(strict_types=1);

namespace App\Application\UseCase\Task;

use App\Domain\WorkTask\WorkTask;

/** O reparo falhou depois do pagamento: a saga estorna e cancela a OS. */
final class FailRepairUseCase extends TaskAction
{
    public function execute(string $osId, string $reason): WorkTask
    {
        return $this->apply($osId, fn (WorkTask $task) => $task->failRepair($reason, $this->clock->now()));
    }
}
