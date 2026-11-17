<?php

declare(strict_types=1);

use App\Domain\Messaging\Message;
use App\Domain\WorkTask\TaskStatus;
use Behat\Behat\Context\Context;
use PHPUnit\Framework\Assert;
use Tests\Support\Harness;

/**
 * Os passos falam com o serviço pelas duas portas que ele tem de verdade: a fila (comandos do
 * orquestrador, tratados pelo worker) e a API (ações do mecânico, pelo controller). A AWS é
 * trocada pelos adaptadores em memória, que têm as mesmas garantias de inbox e outbox.
 */
final class FeatureContext implements Context
{
    private Harness $service;

    private int $sequence = 0;

    private int $lastStatus = 200;

    private ?Message $lastCommand = null;

    public function __construct()
    {
        $this->service = new Harness();
    }

    /** @param array<string, mixed> $payload */
    private function command(string $type, string $osId, array $payload = []): void
    {
        $this->lastCommand = new Message(
            'cmd-' . ++$this->sequence,
            $type,
            'os',
            'saga-' . $osId,
            $osId,
            '2026-11-15T10:00:00.000Z',
            'corr-' . $osId,
            $payload,
        );
        $this->service->bus->deliver($this->lastCommand);
        $this->service->worker->tick(0);
    }

    /** @param array<string, mixed> $body */
    private function mechanic(string $action, string $osId, ?array $body = null): void
    {
        $args             = $body === null ? [['id' => $osId]] : [['id' => $osId], $body];
        $this->lastStatus = $this->service->call($action, ...$args)[0];
        $this->service->worker->tick(0);
    }

    /**
     * @Given que o orquestrador pediu o diagnóstico da OS :osId
     */
    public function orquestradorPediuDiagnostico(string $osId): void
    {
        $this->command('execution.request-diagnosis', $osId, [
            'customer_name' => 'Maria Silva',
            'vehicle'       => ['license_plate' => 'ABC1D23', 'brand' => 'Fiat', 'model' => 'Uno'],
            'description'   => 'Barulho no freio',
        ]);
    }

    /**
     * @When o orquestrador repete o pedido de diagnóstico da OS :osId
     */
    public function orquestradorRepeteOPedido(string $osId): void
    {
        Assert::assertNotNull($this->lastCommand);
        $this->service->bus->deliver($this->lastCommand);
        $this->service->worker->tick(0);
    }

    /**
     * @When o mecânico inicia o diagnóstico da OS :osId
     */
    public function mecanicoIniciaDiagnostico(string $osId): void
    {
        $this->mechanic('startDiagnosis', $osId);
    }

    /**
     * @When o mecânico conclui o diagnóstico da OS :osId com :services serviço e :parts peças
     */
    public function mecanicoConcluiDiagnostico(string $osId, int $services, int $parts): void
    {
        $this->mechanic('completeDiagnosis', $osId, ['items' => [
            ['service_item_id' => 'svc-1', 'quantity' => $services],
            ['part_id' => 'part-1', 'quantity' => $parts],
        ]]);
    }

    /**
     * @Given que o mecânico concluiu o diagnóstico da OS :osId
     */
    public function diagnosticoConcluido(string $osId): void
    {
        $this->mecanicoIniciaDiagnostico($osId);
        $this->mecanicoConcluiDiagnostico($osId, 1, 2);
    }

    /**
     * @When o orquestrador manda iniciar o reparo da OS :osId
     */
    public function orquestradorMandaIniciarReparo(string $osId): void
    {
        $this->command('execution.start-repair', $osId, ['items' => [['kind' => 'service', 'id' => 'svc-1', 'quantity' => 1]]]);
    }

    /**
     * @When o mecânico inicia o reparo da OS :osId
     * @When o mecânico tenta iniciar o reparo da OS :osId
     */
    public function mecanicoIniciaReparo(string $osId): void
    {
        $this->mechanic('startRepair', $osId);
    }

    /**
     * @When o mecânico conclui o reparo da OS :osId
     */
    public function mecanicoConcluiReparo(string $osId): void
    {
        $this->mechanic('completeRepair', $osId);
    }

    /**
     * @Given que a OS :osId já foi diagnosticada e está em reparo
     */
    public function emReparo(string $osId): void
    {
        $this->diagnosticoConcluido($osId);
        $this->orquestradorMandaIniciarReparo($osId);
        $this->mecanicoIniciaReparo($osId);
    }

    /**
     * @When o mecânico registra que o reparo da OS :osId falhou por :reason
     */
    public function reparoFalhou(string $osId, string $reason): void
    {
        $this->mechanic('failRepair', $osId, ['reason' => $reason]);
    }

    /**
     * @When o orquestrador manda cancelar a execução da OS :osId por :reason
     */
    public function orquestradorCancela(string $osId, string $reason): void
    {
        $this->command('execution.cancel', $osId, ['reason' => $reason]);
    }

    /**
     * @Then a tarefa da OS :osId fica com o status :status
     */
    public function tarefaComStatus(string $osId, string $status): void
    {
        $task = $this->service->tasks->find($osId);
        Assert::assertNotNull($task, "não há tarefa para a OS {$osId}");
        Assert::assertSame($status, $task->getStatus()->value);
    }

    /**
     * @Then a tarefa da OS :osId está na fila :status
     */
    public function tarefaNaFila(string $osId, string $status): void
    {
        $ids = array_map(
            static fn ($task): string => $task->getOsId(),
            $this->service->tasks->listByStatus(TaskStatus::from($status)),
        );
        Assert::assertContains($osId, $ids);
    }

    /**
     * @Then existe uma única tarefa na fila :status
     */
    public function umaUnicaTarefa(string $status): void
    {
        Assert::assertCount(1, $this->service->tasks->listByStatus(TaskStatus::from($status)));
    }

    /**
     * @Then nenhuma mensagem fica parada na fila do serviço
     */
    public function filaVazia(): void
    {
        Assert::assertSame(0, $this->service->bus->queued());
    }

    /**
     * @Then o serviço de OS recebe os eventos :types
     */
    public function eventosPublicados(string $types): void
    {
        Assert::assertSame(array_map('trim', explode(',', $types)), $this->service->bus->publishedTypes());
    }

    /**
     * @Then o evento :type leva :count itens
     */
    public function eventoLevaItens(string $type, int $count): void
    {
        foreach ($this->service->bus->published() as $message) {
            if ($message->type === $type) {
                Assert::assertCount($count, $message->payload['items']);

                return;
            }
        }
        Assert::fail("o evento {$type} não foi publicado");
    }

    /**
     * @Then o último evento publicado é :type
     * @Then o último evento publicado é :type com o motivo :reason
     */
    public function ultimoEvento(string $type, ?string $reason = null): void
    {
        $published = $this->service->bus->published();
        $last      = end($published);
        Assert::assertInstanceOf(Message::class, $last);
        Assert::assertSame($type, $last->type);
        if ($reason !== null) {
            Assert::assertSame($reason, $last->payload['reason']);
        }
    }

    /**
     * @Then todo evento publicado leva a saga e a OS de origem
     */
    public function eventosLevamSagaEOs(): void
    {
        foreach ($this->service->bus->published() as $message) {
            Assert::assertSame('saga-' . $message->osId, $message->sagaId);
            Assert::assertSame('corr-' . $message->osId, $message->correlationId);
            Assert::assertSame('execucao', $message->producer);
            Assert::assertSame($message->osId, $message->payload['task_id']);
        }
    }

    /**
     * @Then a ação é recusada
     */
    public function acaoRecusada(): void
    {
        Assert::assertSame(422, $this->lastStatus);
    }
}
