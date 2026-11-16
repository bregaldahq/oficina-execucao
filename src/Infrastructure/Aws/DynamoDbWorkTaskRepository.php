<?php

declare(strict_types=1);

namespace App\Infrastructure\Aws;

use App\Application\Port\OutboxInterface;
use App\Application\Port\WorkTaskRepositoryInterface;
use App\Domain\Exception\ConcurrencyException;
use App\Domain\Exception\DuplicateMessageException;
use App\Domain\Exception\InvalidMessageException;
use App\Domain\Messaging\Message;
use App\Domain\WorkTask\TaskItem;
use App\Domain\WorkTask\TaskStatus;
use App\Domain\WorkTask\TimelineEntry;
use App\Domain\WorkTask\WorkTask;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;

/**
 * Tabela única do serviço (docs/fase-4/CONTRATOS.md §7, no oficina-api):
 *
 *   PK = TASK#<os_id>
 *   SK = META                       a tarefa
 *        EVENT#<quando>#<seq>       linha do tempo
 *        OUTBOX#<id da mensagem>    mensagem de saída
 *        INBOX#<id da mensagem>     mensagem de entrada já processada
 *   GSI1: GSI1PK = STATUS#<status>  (a fila da oficina), GSI1SK = <criada em>
 *         GSI1PK = OUTBOX#PENDING   (o que falta publicar), GSI1SK = <ocorrida em>
 *
 * save() grava tudo numa TransactWriteItems (ADR-017): não existe tarefa alterada sem a
 * mensagem na outbox, nem mensagem de entrada tratada duas vezes.
 */
final class DynamoDbWorkTaskRepository implements WorkTaskRepositoryInterface, OutboxInterface
{
    private const DATE           = 'Y-m-d\TH:i:s.v\Z';
    private const OUTBOX_PENDING = 'OUTBOX#PENDING';
    // TTL (atributo expires_at): a inbox dura mais que a retenção da fila e da DLQ (14 dias),
    // para nenhuma reentrega encontrar a inbox já apagada.
    private const INBOX_TTL_SECONDS  = 30 * 86400;
    private const OUTBOX_TTL_SECONDS = 7  * 86400;

    private readonly Marshaler $marshaler;

    public function __construct(
        private readonly DynamoDbClient $client,
        private readonly string $table,
    ) {
        $this->marshaler = new Marshaler();
    }

    public function find(string $osId): ?WorkTask
    {
        $result = $this->client->getItem([
            'TableName'      => $this->table,
            'Key'            => $this->key($osId, 'META'),
            'ConsistentRead' => true,
        ]);

        $item = $result['Item'] ?? null;

        return is_array($item) ? $this->hydrate($item) : null;
    }

    public function save(WorkTask $task, array $outbox = [], ?string $inboxMessageId = null): void
    {
        $osId    = $task->getOsId();
        $version = $task->getVersion();

        // 1. A tarefa, com trava otimista: só grava se ninguém gravou desde a leitura.
        $meta = [
            'Put' => [
                'TableName' => $this->table,
                'Item'      => $this->marshal($this->metaItem($task, $version + 1)),
            ] + ($version === 0
                ? ['ConditionExpression' => 'attribute_not_exists(PK)']
                : [
                    'ConditionExpression'       => '#v = :expected',
                    'ExpressionAttributeNames'  => ['#v' => 'version'],
                    'ExpressionAttributeValues' => $this->marshal([':expected' => $version]),
                ]),
        ];
        $items = [$meta];

        // 2. Linha do tempo.
        foreach ($task->releaseTimeline() as $i => $entry) {
            $when    = $entry->occurredAt->format(self::DATE);
            $items[] = ['Put' => [
                'TableName' => $this->table,
                'Item'      => $this->marshal([
                    'PK'          => 'TASK#' . $osId,
                    'SK'          => sprintf('EVENT#%s#%05d-%02d', $when, $version + 1, $i),
                    'type'        => $entry->type,
                    'occurred_at' => $when,
                    'detail'      => $entry->detail,
                ]),
            ]];
        }

        // 3. Outbox: o worker publica depois e tira a mensagem do índice de pendentes.
        foreach ($outbox as $message) {
            $items[] = ['Put' => [
                'TableName' => $this->table,
                'Item'      => $this->marshal([
                    'PK'     => 'TASK#' . $osId,
                    'SK'     => 'OUTBOX#' . $message->id,
                    'GSI1PK' => self::OUTBOX_PENDING,
                    'GSI1SK' => $message->occurredAt . '#' . $message->id,
                    'body'   => $message->toJson(),
                ]),
            ]];
        }

        // 4. Inbox: a mesma mensagem de entrada não passa duas vezes.
        $inboxIndex = null;
        if ($inboxMessageId !== null) {
            $inboxIndex = count($items);
            $items[]    = ['Put' => [
                'TableName' => $this->table,
                'Item'      => $this->marshal([
                    'PK'         => 'TASK#' . $osId,
                    'SK'         => 'INBOX#' . $inboxMessageId,
                    'expires_at' => time() + self::INBOX_TTL_SECONDS,
                ]),
                'ConditionExpression' => 'attribute_not_exists(PK)',
            ]];
        }

        try {
            $this->client->transactWriteItems(['TransactItems' => $items]);
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() !== 'TransactionCanceledException') {
                throw $e;
            }

            $reasons = (array)($e->get('CancellationReasons') ?? []);
            $failed  = static fn (?int $index): bool => $index !== null
                && (($reasons[$index]['Code'] ?? '') === 'ConditionalCheckFailed');

            if ($failed($inboxIndex)) {
                throw new DuplicateMessageException("Mensagem {$inboxMessageId} já processada.", 0, $e);
            }

            // Só a condição da tarefa (item 0) significa "mudou desde a leitura". Conflito de
            // transação, throttling ou validação não são concorrência de negócio: sobem como estão.
            if ($failed(0)) {
                throw new ConcurrencyException("A tarefa {$osId} mudou desde a leitura.", 0, $e);
            }

            throw $e;
        }
    }

    public function listByStatus(TaskStatus $status, int $limit = 50): array
    {
        $result = $this->client->query([
            'TableName'                 => $this->table,
            'IndexName'                 => 'GSI1',
            'KeyConditionExpression'    => 'GSI1PK = :pk',
            'ExpressionAttributeValues' => $this->marshal([':pk' => 'STATUS#' . $status->value]),
            'ScanIndexForward'          => true,
            'Limit'                     => $limit,
        ]);

        return array_values(array_map(fn (array $item): WorkTask => $this->hydrate($item), (array)($result['Items'] ?? [])));
    }

    public function timeline(string $osId): array
    {
        $result = $this->client->query([
            'TableName'                 => $this->table,
            'KeyConditionExpression'    => 'PK = :pk AND begins_with(SK, :prefix)',
            'ExpressionAttributeValues' => $this->marshal([':pk' => 'TASK#' . $osId, ':prefix' => 'EVENT#']),
            'ScanIndexForward'          => true,
            'ConsistentRead'            => true,
        ]);

        $entries = [];
        foreach ((array)($result['Items'] ?? []) as $item) {
            $row       = $this->unmarshal($item);
            $entries[] = new TimelineEntry(
                (string)($row['type'] ?? ''),
                $this->date((string)($row['occurred_at'] ?? '')),
                isset($row['detail']) ? (string)$row['detail'] : null,
            );
        }

        return $entries;
    }

    public function pending(int $limit = 25): array
    {
        $result = $this->client->query([
            'TableName'                 => $this->table,
            'IndexName'                 => 'GSI1',
            'KeyConditionExpression'    => 'GSI1PK = :pk',
            'ExpressionAttributeValues' => $this->marshal([':pk' => self::OUTBOX_PENDING]),
            'ScanIndexForward'          => true,
            'Limit'                     => $limit,
        ]);

        $messages = [];
        foreach ((array)($result['Items'] ?? []) as $item) {
            try {
                $messages[] = Message::fromJson((string)($this->unmarshal($item)['body'] ?? ''));
            } catch (InvalidMessageException) {
                // Um item ilegível não pode travar os outros. Ele continua no índice de pendentes,
                // onde o alerta de outbox parada o mostra.
                continue;
            }
        }

        return $messages;
    }

    public function markPublished(Message $message): void
    {
        // Sem GSI1PK/GSI1SK o item sai do índice de pendentes (índice esparso), mas continua na
        // tabela como registro do que foi publicado.
        try {
            $this->client->updateItem([
                'TableName'        => $this->table,
                'Key'              => $this->key($message->osId, 'OUTBOX#' . $message->id),
                'UpdateExpression' => 'REMOVE GSI1PK, GSI1SK SET published_at = :now, expires_at = :ttl',
                // Sem a condição, marcar um item que não existe criaria um item órfão.
                'ConditionExpression'       => 'attribute_exists(PK)',
                'ExpressionAttributeValues' => $this->marshal([
                    ':now' => gmdate(self::DATE),
                    ':ttl' => time() + self::OUTBOX_TTL_SECONDS,
                ]),
            ]);
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() !== 'ConditionalCheckFailedException') {
                throw $e;
            }
        }
    }

    /** @return array<string, mixed> */
    private function metaItem(WorkTask $task, int $version): array
    {
        return [
            'PK'             => 'TASK#' . $task->getOsId(),
            'SK'             => 'META',
            'GSI1PK'         => 'STATUS#' . $task->getStatus()->value,
            'GSI1SK'         => $task->getCreatedAt()->format(self::DATE) . '#' . $task->getOsId(),
            'os_id'          => $task->getOsId(),
            'saga_id'        => $task->getSagaId(),
            'correlation_id' => $task->getCorrelationId(),
            'customer_name'  => $task->getCustomerName(),
            'vehicle'        => $task->getVehicle(),
            'description'    => $task->getDescription(),
            'status'         => $task->getStatus()->value,
            'items'          => $task->itemsToArray(),
            'failure_reason' => $task->getFailureReason(),
            'created_at'     => $task->getCreatedAt()->format(self::DATE),
            'updated_at'     => $task->getUpdatedAt()->format(self::DATE),
            'version'        => $version,
        ];
    }

    /** @param array<string, mixed> $item item no formato do DynamoDB */
    private function hydrate(array $item): WorkTask
    {
        $row     = $this->unmarshal($item);
        $vehicle = is_array($row['vehicle'] ?? null) ? $row['vehicle'] : [];
        $items   = is_array($row['items'] ?? null) ? $row['items'] : [];

        return WorkTask::reconstitute(
            (string)($row['os_id'] ?? ''),
            (string)($row['saga_id'] ?? ''),
            (string)($row['correlation_id'] ?? ''),
            (string)($row['customer_name'] ?? ''),
            [
                'license_plate' => (string)($vehicle['license_plate'] ?? ''),
                'brand'         => (string)($vehicle['brand'] ?? ''),
                'model'         => (string)($vehicle['model'] ?? ''),
            ],
            (string)($row['description'] ?? ''),
            TaskStatus::from((string)($row['status'] ?? '')),
            array_values(array_map(
                static fn (mixed $i): TaskItem => TaskItem::fromArray(is_array($i) ? $i : []),
                $items,
            )),
            isset($row['failure_reason']) ? (string)$row['failure_reason'] : null,
            $this->date((string)($row['created_at'] ?? '')),
            $this->date((string)($row['updated_at'] ?? '')),
            (int)($row['version'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    private function key(string $osId, string $sk): array
    {
        return $this->marshal(['PK' => 'TASK#' . $osId, 'SK' => $sk]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function marshal(array $data): array
    {
        return (array)$this->marshaler->marshalItem($data);
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function unmarshal(array $item): array
    {
        return (array)$this->marshaler->unmarshalItem($item);
    }

    private function date(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value !== '' ? $value : 'now', new \DateTimeZone('UTC'));
    }
}
