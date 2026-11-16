<?php

declare(strict_types=1);

namespace App\Application\UseCase\Task;

use App\Domain\WorkTask\WorkTask;

/** O diagnóstico não pôde ser concluído: a saga cancela a OS. */
final class FailDiagnosisUseCase extends TaskAction
{
    public function execute(string $osId, string $reason): WorkTask
    {
        return $this->apply($osId, fn (WorkTask $task) => $task->failDiagnosis($reason, $this->clock->now()));
    }
}
