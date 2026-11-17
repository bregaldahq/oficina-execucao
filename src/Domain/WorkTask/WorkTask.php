<?php

declare(strict_types=1);

namespace App\Domain\WorkTask;

use App\Domain\Exception\DomainException;
use App\Domain\Exception\InvalidTaskTransitionException;

/**
 * A tarefa da oficina para uma ordem de serviço: uma por OS, identificada pelo id da OS.
 *
 * Cada transição é um método nomeado, como no agregado ServiceOrder do serviço de OS (ADR-003):
 * não existe setStatus. Toda transição registra uma linha na linha do tempo e, quando o
 * serviço de OS precisa saber, um evento a publicar (docs/fase-4/CONTRATOS.md §5.3).
 */
final class WorkTask
{
    /** @var list<TaskItem> */
    private array $items = [];

    /** @var list<TimelineEntry> linhas novas, ainda não gravadas */
    private array $newTimeline = [];

    /** @var list<array{type: string, payload: array<string, mixed>}> eventos novos, ainda não gravados */
    private array $pendingEvents = [];

    private ?string $failureReason = null;

    private function __construct(
        private readonly string $osId,
        private readonly string $sagaId,
        private readonly string $correlationId,
        private readonly string $customerName,
        /** @var array{license_plate: string, brand: string, model: string} */
        private readonly array $vehicle,
        private readonly string $description,
        private TaskStatus $status,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
        private int $version,
    ) {
    }

    /**
     * @param array{license_plate?: string, brand?: string, model?: string} $vehicle
     */
    public static function open(
        string $osId,
        string $sagaId,
        string $correlationId,
        string $customerName,
        array $vehicle,
        string $description,
        \DateTimeImmutable $now,
    ): self {
        if ($osId === '' || $sagaId === '') {
            throw new DomainException('A tarefa precisa do id da OS e do id da saga.');
        }

        $task = new self(
            $osId,
            $sagaId,
            $correlationId,
            $customerName,
            [
                'license_plate' => (string)($vehicle['license_plate'] ?? ''),
                'brand'         => (string)($vehicle['brand'] ?? ''),
                'model'         => (string)($vehicle['model'] ?? ''),
            ],
            $description,
            TaskStatus::QueuedDiagnosis,
            $now,
            $now,
            0,
        );
        $task->log('task.opened', $now);

        return $task;
    }

    /**
     * Hidratação a partir do banco (ADR-005): não valida transição nem gera evento.
     *
     * @param array{license_plate: string, brand: string, model: string} $vehicle
     * @param list<TaskItem>                                             $items
     */
    public static function reconstitute(
        string $osId,
        string $sagaId,
        string $correlationId,
        string $customerName,
        array $vehicle,
        string $description,
        TaskStatus $status,
        array $items,
        ?string $failureReason,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        int $version,
    ): self {
        $task = new self(
            $osId,
            $sagaId,
            $correlationId,
            $customerName,
            $vehicle,
            $description,
            $status,
            $createdAt,
            $updatedAt,
            $version,
        );
        $task->items         = $items;
        $task->failureReason = $failureReason;

        return $task;
    }

    public function startDiagnosis(\DateTimeImmutable $now): void
    {
        $this->transition(TaskStatus::QueuedDiagnosis, TaskStatus::Diagnosing, 'iniciar o diagnóstico', $now);
        $this->log('diagnosis.started', $now);
        $this->emit('diagnosis.started');
    }

    /** @param list<TaskItem> $items */
    public function completeDiagnosis(array $items, \DateTimeImmutable $now): void
    {
        if ($items === []) {
            throw new DomainException('O diagnóstico precisa de pelo menos um item.');
        }

        $this->transition(TaskStatus::Diagnosing, TaskStatus::Diagnosed, 'concluir o diagnóstico', $now);
        $this->items = $items;
        $this->log('diagnosis.completed', $now, count($items) . ' item(ns)');
        $this->emit('diagnosis.completed', ['items' => $this->itemsToArray()]);
    }

    public function failDiagnosis(string $reason, \DateTimeImmutable $now): void
    {
        $this->requireReason($reason);
        $this->transition(TaskStatus::Diagnosing, TaskStatus::DiagnosisFailed, 'registrar falha no diagnóstico', $now);
        $this->failureReason = $reason;
        $this->log('diagnosis.failed', $now, $reason);
        $this->emit('diagnosis.failed', ['reason' => $reason]);
    }

    /**
     * O orçamento foi aprovado e pago: a tarefa volta para a fila, agora de reparo.
     * Os itens que valem são os do comando (os aprovados), não os do diagnóstico.
     *
     * @param list<TaskItem> $items
     */
    public function queueRepair(array $items, \DateTimeImmutable $now): void
    {
        $this->transition(TaskStatus::Diagnosed, TaskStatus::QueuedRepair, 'enfileirar o reparo', $now);
        if ($items !== []) {
            $this->items = $items;
        }
        $this->log('repair.queued', $now);
    }

    public function startRepair(\DateTimeImmutable $now): void
    {
        $this->transition(TaskStatus::QueuedRepair, TaskStatus::Repairing, 'iniciar o reparo', $now);
        $this->log('repair.started', $now);
        $this->emit('repair.started');
    }

    public function completeRepair(\DateTimeImmutable $now): void
    {
        $this->transition(TaskStatus::Repairing, TaskStatus::Repaired, 'concluir o reparo', $now);
        $this->log('repair.completed', $now);
        $this->emit('repair.completed');
    }

    public function failRepair(string $reason, \DateTimeImmutable $now): void
    {
        $this->requireReason($reason);
        $this->transition(TaskStatus::Repairing, TaskStatus::RepairFailed, 'registrar falha no reparo', $now);
        $this->failureReason = $reason;
        $this->log('repair.failed', $now, $reason);
        $this->emit('repair.failed', ['reason' => $reason]);
    }

    /**
     * Compensação pedida pelo orquestrador. É idempotente: cancelar uma tarefa que já terminou
     * (cancelada, com falha ou reparada) não muda nada, mas a confirmação é emitida de novo,
     * porque o orquestrador espera por ela.
     */
    public function cancel(string $reason, \DateTimeImmutable $now): void
    {
        if (!$this->status->isFinal()) {
            $this->status        = TaskStatus::Cancelled;
            $this->failureReason = $reason;
            $this->updatedAt     = $now;
            $this->log('task.cancelled', $now, $reason);
        }

        $this->emit('execution.cancelled');
    }

    public function getOsId(): string
    {
        return $this->osId;
    }

    public function getSagaId(): string
    {
        return $this->sagaId;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getCustomerName(): string
    {
        return $this->customerName;
    }

    /** @return array{license_plate: string, brand: string, model: string} */
    public function getVehicle(): array
    {
        return $this->vehicle;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getStatus(): TaskStatus
    {
        return $this->status;
    }

    /** @return list<TaskItem> */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Versão lida do banco; a gravação só passa se ela ainda for a atual (trava otimista). */
    public function getVersion(): int
    {
        return $this->version;
    }

    /** @return list<TimelineEntry> */
    public function releaseTimeline(): array
    {
        $entries           = $this->newTimeline;
        $this->newTimeline = [];

        return $entries;
    }

    /** @return list<array{type: string, payload: array<string, mixed>}> */
    public function releaseEvents(): array
    {
        $events              = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    /** @return list<array{kind: string, id: string, quantity: int}> */
    public function itemsToArray(): array
    {
        return array_map(static fn (TaskItem $item): array => $item->toArray(), $this->items);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'             => $this->osId,
            'os_id'          => $this->osId,
            'saga_id'        => $this->sagaId,
            'status'         => $this->status->value,
            'customer_name'  => $this->customerName,
            'vehicle'        => $this->vehicle,
            'description'    => $this->description,
            'items'          => $this->itemsToArray(),
            'failure_reason' => $this->failureReason,
            'created_at'     => $this->createdAt->format('Y-m-d\TH:i:s.v\Z'),
            'updated_at'     => $this->updatedAt->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    private function transition(TaskStatus $from, TaskStatus $to, string $action, \DateTimeImmutable $now): void
    {
        if ($this->status !== $from) {
            throw InvalidTaskTransitionException::from($this->status->value, $action);
        }

        $this->status    = $to;
        $this->updatedAt = $now;
    }

    private function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new DomainException('Informe o motivo da falha.');
        }
    }

    private function log(string $type, \DateTimeImmutable $now, ?string $detail = null): void
    {
        $this->newTimeline[] = new TimelineEntry($type, $now, $detail);
    }

    /** @param array<string, mixed> $payload */
    private function emit(string $type, array $payload = []): void
    {
        $this->pendingEvents[] = ['type' => $type, 'payload' => ['task_id' => $this->osId] + $payload];
    }
}
