<?php

declare(strict_types=1);

namespace App\Application\UseCase\Task;

use App\Application\Messaging\MessageFactory;
use App\Application\Messaging\OutboxRelay;
use App\Application\Port\ClockInterface;
use App\Application\Port\WorkTaskRepositoryInterface;
use App\Domain\Exception\NotFoundException;
use App\Domain\WorkTask\WorkTask;

/**
 * O que toda ação do mecânico sobre uma tarefa tem em comum: carregar, mudar, e gravar a tarefa
 * junto com os eventos na outbox. Cada ação é um use case próprio, que só diz qual é a mudança.
 */
abstract class TaskAction
{
    public function __construct(
        private readonly WorkTaskRepositoryInterface $tasks,
        private readonly MessageFactory $messages,
        protected readonly ClockInterface $clock,
        private readonly ?OutboxRelay $relay = null,
    ) {
    }

    /** @param callable(WorkTask): void $change */
    protected function apply(string $osId, callable $change): WorkTask
    {
        $task = $this->tasks->find($osId);
        if ($task === null) {
            throw new NotFoundException('Tarefa não encontrada.');
        }

        $change($task);
        $this->tasks->save($task, $this->messages->fromTask($task));

        // O evento já está garantido na outbox. Publicar agora só evita que a saga espere a
        // próxima volta do worker; se falhar, o worker publica.
        $this->relay?->relay();

        return $task;
    }
}
