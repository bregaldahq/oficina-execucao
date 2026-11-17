<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Exception\ConcurrencyException;
use App\Domain\Exception\DuplicateMessageException;
use App\Domain\Messaging\Message;
use App\Domain\WorkTask\TaskStatus;
use App\Domain\WorkTask\TimelineEntry;
use App\Domain\WorkTask\WorkTask;

interface WorkTaskRepositoryInterface
{
    public function find(string $osId): ?WorkTask;

    /**
     * Grava, numa operação atômica (ADR-017): a tarefa, as linhas novas da linha do tempo, as
     * mensagens de saída na outbox e, quando a mudança veio de uma mensagem, o id dela na inbox.
     *
     * @param list<Message> $outbox
     *
     * @throws DuplicateMessageException a mensagem de entrada já tinha sido processada
     * @throws ConcurrencyException      a tarefa mudou desde a leitura
     */
    public function save(WorkTask $task, array $outbox = [], ?string $inboxMessageId = null): void;

    /** @return list<WorkTask> a fila: as mais antigas primeiro */
    public function listByStatus(TaskStatus $status, int $limit = 50): array;

    /** @return list<TimelineEntry> */
    public function timeline(string $osId): array;
}
