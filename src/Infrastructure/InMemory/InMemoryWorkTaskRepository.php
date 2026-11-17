<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Application\Port\OutboxInterface;
use App\Application\Port\WorkTaskRepositoryInterface;
use App\Domain\Exception\ConcurrencyException;
use App\Domain\Exception\DuplicateMessageException;
use App\Domain\Messaging\Message;
use App\Domain\WorkTask\TaskItem;
use App\Domain\WorkTask\TaskStatus;
use App\Domain\WorkTask\TimelineEntry;
use App\Domain\WorkTask\WorkTask;

/**
 * Repositório e outbox em memória, com as mesmas garantias do DynamoDB: gravação atômica,
 * inbox e trava otimista. Usado nos testes e no BDD, para exercitar o fluxo sem AWS.
 */
final class InMemoryWorkTaskRepository implements WorkTaskRepositoryInterface, OutboxInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $tasks = [];

    /** @var array<string, list<TimelineEntry>> */
    private array $timelines = [];

    /** @var array<string, Message> */
    private array $pending = [];

    /** @var array<string, true> */
    private array $inbox = [];

    public function find(string $osId): ?WorkTask
    {
        if (!isset($this->tasks[$osId])) {
            return null;
        }

        $row = $this->tasks[$osId];

        return WorkTask::reconstitute(
            $row['os_id'],
            $row['saga_id'],
            $row['correlation_id'],
            $row['customer_name'],
            $row['vehicle'],
            $row['description'],
            $row['status'],
            $row['items'],
            $row['failure_reason'],
            $row['created_at'],
            $row['updated_at'],
            $row['version'],
        );
    }

    public function save(WorkTask $task, array $outbox = [], ?string $inboxMessageId = null): void
    {
        $osId = $task->getOsId();

        if ($inboxMessageId !== null && isset($this->inbox[$inboxMessageId])) {
            throw new DuplicateMessageException("Mensagem {$inboxMessageId} já processada.");
        }
        if (($this->tasks[$osId]['version'] ?? 0) !== $task->getVersion()) {
            throw new ConcurrencyException("A tarefa {$osId} mudou desde a leitura.");
        }

        $this->tasks[$osId] = [
            'os_id'          => $osId,
            'saga_id'        => $task->getSagaId(),
            'correlation_id' => $task->getCorrelationId(),
            'customer_name'  => $task->getCustomerName(),
            'vehicle'        => $task->getVehicle(),
            'description'    => $task->getDescription(),
            'status'         => $task->getStatus(),
            'items'          => array_map(static fn (TaskItem $i): TaskItem => clone $i, $task->getItems()),
            'failure_reason' => $task->getFailureReason(),
            'created_at'     => $task->getCreatedAt(),
            'updated_at'     => $task->getUpdatedAt(),
            'version'        => $task->getVersion() + 1,
        ];

        $this->timelines[$osId] = [...($this->timelines[$osId] ?? []), ...$task->releaseTimeline()];
        foreach ($outbox as $message) {
            $this->pending[$message->id] = $message;
        }
        if ($inboxMessageId !== null) {
            $this->inbox[$inboxMessageId] = true;
        }
    }

    public function listByStatus(TaskStatus $status, int $limit = 50): array
    {
        $rows = array_filter($this->tasks, static fn (array $row): bool => $row['status'] === $status);
        usort($rows, static fn (array $a, array $b): int => $a['created_at'] <=> $b['created_at']);

        $tasks = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $task = $this->find($row['os_id']);
            if ($task !== null) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    public function timeline(string $osId): array
    {
        return $this->timelines[$osId] ?? [];
    }

    public function pending(int $limit = 25): array
    {
        return array_slice(array_values($this->pending), 0, $limit);
    }

    public function markPublished(Message $message): void
    {
        unset($this->pending[$message->id]);
    }
}
