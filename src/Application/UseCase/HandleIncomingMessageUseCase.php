<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Application\Messaging\MessageFactory;
use App\Application\Port\ClockInterface;
use App\Application\Port\WorkTaskRepositoryInterface;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\DuplicateMessageException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Messaging\Message;
use App\Domain\WorkTask\TaskItem;
use App\Domain\WorkTask\TaskStatus;
use App\Domain\WorkTask\WorkTask;

/**
 * Os comandos que o orquestrador da saga manda para este serviço
 * (docs/fase-4/CONTRATOS.md §5.2, no oficina-api).
 *
 * Todo comando é idempotente de duas formas (ADR-017): o id da mensagem fica na inbox, e um
 * comando repetido com outro id para a mesma OS não repete o efeito.
 */
final class HandleIncomingMessageUseCase
{
    public const OUTCOME_OK        = 'ok';
    public const OUTCOME_DUPLICATE = 'duplicate';
    public const OUTCOME_IGNORED   = 'ignored';

    public function __construct(
        private readonly WorkTaskRepositoryInterface $tasks,
        private readonly MessageFactory $messages,
        private readonly ClockInterface $clock,
    ) {
    }

    public function execute(Message $message): string
    {
        try {
            return match ($message->type) {
                'execution.request-diagnosis' => $this->requestDiagnosis($message),
                'execution.start-repair'      => $this->startRepair($message),
                'execution.cancel'            => $this->cancel($message),
                default                       => self::OUTCOME_IGNORED,
            };
        } catch (DuplicateMessageException) {
            return self::OUTCOME_DUPLICATE;
        }
    }

    private function requestDiagnosis(Message $message): string
    {
        // Já existe tarefa para esta OS: o comando foi repetido. Uma OS tem uma tarefa só.
        if ($this->tasks->find($message->osId) !== null) {
            return self::OUTCOME_DUPLICATE;
        }

        $this->tasks->save($this->open($message), [], $message->id);

        return self::OUTCOME_OK;
    }

    private function startRepair(Message $message): string
    {
        $task = $this->tasks->find($message->osId);
        if ($task === null) {
            // Sem tarefa não há o que reparar. A exceção devolve a mensagem à fila; se o
            // diagnóstico nunca chegou, ela acaba na DLQ, que tem alerta.
            throw new NotFoundException("Não há tarefa para a OS {$message->osId}.");
        }

        $status = $task->getStatus();

        // O reparo já foi enfileirado (ou está adiante), ou a tarefa terminou: comando repetido
        // ou atrasado, sem efeito.
        if (in_array($status, [TaskStatus::QueuedRepair, TaskStatus::Repairing], true) || $status->isFinal()) {
            return self::OUTCOME_DUPLICATE;
        }

        // Ainda em diagnóstico: a ordem de reparar não pode ser atendida nem descartada. A
        // exceção devolve a mensagem à fila; se persistir, ela vai para a DLQ, que tem alerta.
        if ($status !== TaskStatus::Diagnosed) {
            throw new DomainException("A tarefa da OS {$message->osId} ainda está em {$status->value}.");
        }

        $task->queueRepair($this->items($message), $this->clock->now());
        $this->tasks->save($task, [], $message->id);

        return self::OUTCOME_OK;
    }

    private function cancel(Message $message): string
    {
        // Compensação de algo que não existe é confirmada sem efeito (§6.2): a tarefa nasce já
        // cancelada, para a confirmação ter de onde sair e um diagnóstico atrasado não a reabrir.
        $task = $this->tasks->find($message->osId) ?? $this->open($message);

        $task->cancel((string)($message->payload['reason'] ?? 'cancelada pela saga'), $this->clock->now());
        $this->tasks->save($task, $this->messages->fromTask($task), $message->id);

        return self::OUTCOME_OK;
    }

    private function open(Message $message): WorkTask
    {
        $vehicle = $message->payload['vehicle'] ?? [];

        return WorkTask::open(
            $message->osId,
            $message->sagaId,
            $message->correlationId,
            (string)($message->payload['customer_name'] ?? ''),
            is_array($vehicle) ? $vehicle : [],
            (string)($message->payload['description'] ?? ''),
            $this->clock->now(),
        );
    }

    /** @return list<TaskItem> */
    private function items(Message $message): array
    {
        $items = $message->payload['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $item): TaskItem => TaskItem::fromArray(is_array($item) ? $item : []),
            $items,
        ));
    }
}
