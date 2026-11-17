<?php

declare(strict_types=1);

namespace App\Application\UseCase\Task;

use App\Domain\WorkTask\WorkTask;

/** O mecânico tirou a tarefa da fila e começou o diagnóstico. */
final class StartDiagnosisUseCase extends TaskAction
{
    public function execute(string $osId): WorkTask
    {
        return $this->apply($osId, fn (WorkTask $task) => $task->startDiagnosis($this->clock->now()));
    }
}
