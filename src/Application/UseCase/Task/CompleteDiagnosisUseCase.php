<?php

declare(strict_types=1);

namespace App\Application\UseCase\Task;

use App\Domain\WorkTask\TaskItem;
use App\Domain\WorkTask\WorkTask;

/** O diagnóstico levantou os serviços e as peças: eles seguem para o orçamento. */
final class CompleteDiagnosisUseCase extends TaskAction
{
    /** @param list<TaskItem> $items */
    public function execute(string $osId, array $items): WorkTask
    {
        return $this->apply($osId, fn (WorkTask $task) => $task->completeDiagnosis($items, $this->clock->now()));
    }
}
