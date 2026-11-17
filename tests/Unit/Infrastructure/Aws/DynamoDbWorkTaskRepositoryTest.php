<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Aws;

use App\Domain\Exception\ConcurrencyException;
use App\Domain\Exception\DuplicateMessageException;
use App\Domain\WorkTask\TaskStatus;
use App\Infrastructure\Aws\DynamoDbWorkTaskRepository;
use Aws\CommandInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;

/**
 * O que estes testes travam é o desenho da tabela: chaves, condições e a transação única. O
 * comportamento contra um DynamoDB de verdade é conferido pelo e2e do repositório principal.
 */
class DynamoDbWorkTaskRepositoryTest extends TestCase
{
    private MockHandler $mock;

    /** @var list<CommandInterface<mixed>> */
    private array $commands = [];

    private DynamoDbWorkTaskRepository $repo;

    protected function setUp(): void
    {
        $this->mock = new MockHandler();
        $client     = new DynamoDbClient([
            'version'     => 'latest',
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler'     => $this->mock,
        ]);
        $this->repo = new DynamoDbWorkTaskRepository($client, 'tabela');
    }

    /** @param array<string, mixed> $data */
    private function respond(array $data = []): void
    {
        $this->mock->append(function (CommandInterface $command) use ($data): Result {
            $this->commands[] = $command;

            return new Result($data);
        });
    }

    /** @param list<array<string, mixed>> $reasons */
    private function cancel(array $reasons): void
    {
        $this->mock->append(function (CommandInterface $command) use ($reasons): never {
            throw new DynamoDbException('cancelada', $command, [
                'code' => 'TransactionCanceledException',
                'body' => ['CancellationReasons' => $reasons],
            ]);
        });
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function plain(array $item): array
    {
        return (array)(new Marshaler())->unmarshalItem($item);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function stored(array $overrides = []): array
    {
        return (array)(new Marshaler())->marshalItem($overrides + [
            'PK'             => 'TASK#os-1',
            'SK'             => 'META',
            'os_id'          => 'os-1',
            'saga_id'        => 'saga-1',
            'correlation_id' => 'corr-1',
            'customer_name'  => 'Maria Silva',
            'vehicle'        => ['license_plate' => 'ABC1D23', 'brand' => 'Fiat', 'model' => 'Uno'],
            'description'    => 'Barulho no freio',
            'status'         => 'DIAGNOSED',
            'items'          => [['kind' => 'part', 'id' => 'p-1', 'quantity' => 2]],
            'failure_reason' => null,
            'created_at'     => '2026-11-15T10:00:00.000Z',
            'updated_at'     => '2026-11-15T10:05:00.000Z',
            'version'        => 3,
        ]);
    }

    public function testTarefaNovaVaiNumaTransacaoComCondicaoDeNaoExistir(): void
    {
        $this->respond();

        $this->repo->save(Fixtures::task(), [], 'msg-1');

        $this->assertSame('TransactWriteItems', $this->commands[0]->getName());
        $items = $this->commands[0]['TransactItems'];
        $this->assertCount(3, $items, 'tarefa, linha do tempo e inbox');

        $meta = $this->plain($items[0]['Put']['Item']);
        $this->assertSame('tabela', $items[0]['Put']['TableName']);
        $this->assertSame('attribute_not_exists(PK)', $items[0]['Put']['ConditionExpression']);
        $this->assertSame('TASK#os-1', $meta['PK']);
        $this->assertSame('META', $meta['SK']);
        $this->assertSame('STATUS#QUEUED_DIAGNOSIS', $meta['GSI1PK']);
        $this->assertSame(1, $meta['version']);

        $this->assertStringStartsWith('EVENT#2026-11-15T10:00:00.000Z#', $this->plain($items[1]['Put']['Item'])['SK']);

        $this->assertSame('INBOX#msg-1', $this->plain($items[2]['Put']['Item'])['SK']);
        $this->assertSame('attribute_not_exists(PK)', $items[2]['Put']['ConditionExpression']);
    }

    public function testTarefaExistenteUsaTravaOtimistaEGravaAOutboxJunto(): void
    {
        $this->respond(['Item' => $this->stored(['status' => 'DIAGNOSING'])]);
        $this->respond();
        $task = $this->repo->find('os-1');
        $this->assertNotNull($task);
        $task->completeDiagnosis(Fixtures::items(), new \DateTimeImmutable('2026-11-15T10:06:00Z'));
        $message = Fixtures::command('diagnosis.completed', ['task_id' => 'os-1'], 'out-1');

        $this->repo->save($task, [$message]);

        $items = $this->commands[1]['TransactItems'];
        $this->assertSame('#v = :expected', $items[0]['Put']['ConditionExpression']);
        $this->assertSame(3, $this->plain($items[0]['Put']['ExpressionAttributeValues'])[':expected']);
        $this->assertSame(4, $this->plain($items[0]['Put']['Item'])['version']);

        $outbox = $this->plain($items[2]['Put']['Item']);
        $this->assertSame('OUTBOX#out-1', $outbox['SK']);
        $this->assertSame('OUTBOX#PENDING', $outbox['GSI1PK']);
        $this->assertSame($message->toJson(), $outbox['body']);
    }

    public function testInboxRepetidaViraDuplicateMessage(): void
    {
        // Ordem dos itens: tarefa, linha do tempo, inbox. A condição que falhou é a da inbox.
        $this->cancel([['Code' => 'None'], ['Code' => 'None'], ['Code' => 'ConditionalCheckFailed']]);

        $this->expectException(DuplicateMessageException::class);
        $this->repo->save(Fixtures::task(), [], 'msg-1');
    }

    public function testVersaoDiferenteViraConcurrency(): void
    {
        $this->cancel([['Code' => 'ConditionalCheckFailed'], ['Code' => 'None'], ['Code' => 'None']]);

        $this->expectException(ConcurrencyException::class);
        $this->repo->save(Fixtures::task(), [], 'msg-1');
    }

    public function testTransacaoCanceladaPorOutroMotivoNaoViraConcorrencia(): void
    {
        // Conflito de transação ou throttling não são "a tarefa mudou": sobem como estão.
        $this->cancel([['Code' => 'TransactionConflict'], ['Code' => 'None'], ['Code' => 'None']]);

        $this->expectException(DynamoDbException::class);
        $this->repo->save(Fixtures::task(), [], 'msg-1');
    }

    public function testInboxExpiraDepoisDaRetencaoDaFila(): void
    {
        $this->respond();

        $this->repo->save(Fixtures::task(), [], 'msg-1');

        $inbox = $this->plain($this->commands[0]['TransactItems'][2]['Put']['Item']);
        $this->assertGreaterThan(time() + 14 * 86400, $inbox['expires_at'], 'a DLQ guarda a mensagem por 14 dias');
    }

    public function testItemIlegivelDaOutboxNaoTravaOsOutros(): void
    {
        $boa = Fixtures::command('repair.completed', [], 'out-2');
        $this->respond(['Items' => [
            (new Marshaler())->marshalItem(['body' => 'lixo']),
            (new Marshaler())->marshalItem(['body' => $boa->toJson()]),
        ]]);

        $this->assertSame(['out-2'], array_map(static fn ($m) => $m->id, $this->repo->pending()));
    }

    public function testMarcarPublicadaExigeOItemEIgnoraAusencia(): void
    {
        $this->mock->append(function (CommandInterface $command): never {
            $this->commands[] = $command;
            throw new DynamoDbException('condição', $command, ['code' => 'ConditionalCheckFailedException']);
        });

        $this->repo->markPublished(Fixtures::command('repair.completed', [], 'out-3'));

        $this->assertSame('attribute_exists(PK)', $this->commands[0]['ConditionExpression']);
    }

    public function testFalhaAoMarcarPublicadaQueNaoEACondicaoSobe(): void
    {
        $this->mock->append(function (CommandInterface $command): never {
            throw new DynamoDbException('inválida', $command, ['code' => 'ValidationException']);
        });

        $this->expectException(DynamoDbException::class);
        $this->repo->markPublished(Fixtures::command('repair.completed', [], 'out-3'));
    }

    public function testOutroErroDoDynamoNaoEEngolido(): void
    {
        $this->mock->append(function (CommandInterface $command): never {
            throw new DynamoDbException('requisição inválida', $command, ['code' => 'ValidationException']);
        });

        $this->expectException(DynamoDbException::class);
        $this->repo->save(Fixtures::task());
    }

    public function testFindHidrataATarefaComLeituraConsistente(): void
    {
        $this->respond(['Item' => $this->stored()]);

        $task = $this->repo->find('os-1');

        $this->assertTrue($this->commands[0]['ConsistentRead']);
        $this->assertSame(['PK' => 'TASK#os-1', 'SK' => 'META'], $this->plain($this->commands[0]['Key']));
        $this->assertNotNull($task);
        $this->assertSame(TaskStatus::Diagnosed, $task->getStatus());
        $this->assertSame(3, $task->getVersion());
        $this->assertSame([['kind' => 'part', 'id' => 'p-1', 'quantity' => 2]], $task->itemsToArray());
        $this->assertSame('Uno', $task->getVehicle()['model']);
        $this->assertNull($task->getFailureReason());
    }

    public function testFindSemItemDevolveNull(): void
    {
        $this->respond();

        $this->assertNull($this->repo->find('nao-existe'));
    }

    public function testFilaConsultaOIndicePorStatusEmOrdemDeChegada(): void
    {
        $this->respond(['Items' => [$this->stored(['status' => 'QUEUED_REPAIR'])]]);

        $tasks = $this->repo->listByStatus(TaskStatus::QueuedRepair, 10);

        $command = $this->commands[0];
        $this->assertSame('GSI1', $command['IndexName']);
        $this->assertSame('STATUS#QUEUED_REPAIR', $this->plain($command['ExpressionAttributeValues'])[':pk']);
        $this->assertTrue($command['ScanIndexForward']);
        $this->assertSame(10, $command['Limit']);
        $this->assertCount(1, $tasks);
    }

    public function testLinhaDoTempoLeSoOsEventosDaTarefa(): void
    {
        $this->respond(['Items' => [(new Marshaler())->marshalItem([
            'PK'          => 'TASK#os-1', 'SK' => 'EVENT#x', 'type' => 'repair.started',
            'occurred_at' => '2026-11-15T10:00:00.000Z', 'detail' => null,
        ])]]);

        $timeline = $this->repo->timeline('os-1');

        $values = $this->plain($this->commands[0]['ExpressionAttributeValues']);
        $this->assertSame('TASK#os-1', $values[':pk']);
        $this->assertSame('EVENT#', $values[':prefix']);
        $this->assertSame('repair.started', $timeline[0]->type);
        $this->assertNull($timeline[0]->detail);
    }

    public function testPendentesDaOutboxEMarcacaoDePublicada(): void
    {
        $message = Fixtures::command('repair.completed', ['task_id' => 'os-1'], 'out-9');
        $this->respond(['Items' => [(new Marshaler())->marshalItem(['body' => $message->toJson()])]]);
        $this->respond();

        $pending = $this->repo->pending(5);
        $this->repo->markPublished($pending[0]);

        $this->assertSame('OUTBOX#PENDING', $this->plain($this->commands[0]['ExpressionAttributeValues'])[':pk']);
        $this->assertSame('out-9', $pending[0]->id);
        $this->assertSame('UpdateItem', $this->commands[1]->getName());
        $this->assertSame(['PK' => 'TASK#os-1', 'SK' => 'OUTBOX#out-9'], $this->plain($this->commands[1]['Key']));
        $this->assertStringContainsString('REMOVE GSI1PK, GSI1SK', $this->commands[1]['UpdateExpression']);
    }
}
