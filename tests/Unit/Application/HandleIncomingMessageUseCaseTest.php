<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\UseCase\HandleIncomingMessageUseCase as Handler;
use App\Domain\Exception\NotFoundException;
use App\Domain\WorkTask\TaskStatus;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;
use Tests\Support\Harness;

class HandleIncomingMessageUseCaseTest extends TestCase
{
    private Harness $h;

    protected function setUp(): void
    {
        $this->h = new Harness();
    }

    private function diagnosed(): void
    {
        $this->h->handler->execute(Fixtures::command('execution.request-diagnosis', Fixtures::diagnosisPayload(), 'm-diag'));
        $this->h->call('startDiagnosis', ['id' => Fixtures::OS]);
        $this->h->call('completeDiagnosis', ['id' => Fixtures::OS], ['items' => [['service_item_id' => 'svc-1']]]);
    }

    public function testRequestDiagnosisAbreATarefaNaFila(): void
    {
        $outcome = $this->h->handler->execute(Fixtures::command('execution.request-diagnosis', Fixtures::diagnosisPayload()));

        $task = $this->h->tasks->find(Fixtures::OS);
        $this->assertSame(Handler::OUTCOME_OK, $outcome);
        $this->assertNotNull($task);
        $this->assertSame(TaskStatus::QueuedDiagnosis, $task->getStatus());
        $this->assertSame('Maria Silva', $task->getCustomerName());
        $this->assertSame('ABC1D23', $task->getVehicle()['license_plate']);
        $this->assertSame([], $this->h->tasks->pending(), 'abrir a tarefa não publica nada');
    }

    public function testMesmaMensagemDuasVezesNaoRepeteOEfeito(): void
    {
        $command = Fixtures::command('execution.request-diagnosis', Fixtures::diagnosisPayload());

        $this->h->handler->execute($command);
        $outcome = $this->h->handler->execute($command);

        $this->assertSame(Handler::OUTCOME_DUPLICATE, $outcome);
        $this->assertCount(1, $this->h->tasks->timeline(Fixtures::OS));
    }

    public function testComandoRepetidoComOutroIdNaoAbreSegundaTarefa(): void
    {
        $this->h->handler->execute(Fixtures::command('execution.request-diagnosis', Fixtures::diagnosisPayload(), 'a'));
        $outcome = $this->h->handler->execute(Fixtures::command('execution.request-diagnosis', Fixtures::diagnosisPayload(), 'b'));

        $this->assertSame(Handler::OUTCOME_DUPLICATE, $outcome);
        $this->assertCount(1, $this->h->tasks->listByStatus(TaskStatus::QueuedDiagnosis));
    }

    public function testStartRepairEnfileiraOReparoComOsItensAprovados(): void
    {
        $this->diagnosed();

        $outcome = $this->h->handler->execute(Fixtures::command(
            'execution.start-repair',
            ['items' => [['kind' => 'part', 'id' => 'p-2', 'quantity' => 4]]],
            'm-repair',
        ));

        $task = $this->h->tasks->find(Fixtures::OS);
        $this->assertSame(Handler::OUTCOME_OK, $outcome);
        $this->assertNotNull($task);
        $this->assertSame(TaskStatus::QueuedRepair, $task->getStatus());
        $this->assertSame([['kind' => 'part', 'id' => 'p-2', 'quantity' => 4]], $task->itemsToArray());
    }

    public function testStartRepairRepetidoEDuplicata(): void
    {
        $this->diagnosed();
        $this->h->handler->execute(Fixtures::command('execution.start-repair', [], 'r1'));

        $this->assertSame(Handler::OUTCOME_DUPLICATE, $this->h->handler->execute(Fixtures::command('execution.start-repair', [], 'r1')));
        $this->assertSame(Handler::OUTCOME_DUPLICATE, $this->h->handler->execute(Fixtures::command('execution.start-repair', [], 'r2')));
    }

    public function testStartRepairDuranteODiagnosticoNaoEDescartado(): void
    {
        $this->h->handler->execute(Fixtures::command('execution.request-diagnosis', Fixtures::diagnosisPayload(), 'm-diag'));

        // A ordem de reparar não pode ser atendida ainda. Descartá-la deixaria a saga esperando
        // para sempre; a exceção devolve a mensagem à fila e, se persistir, à DLQ.
        try {
            $this->h->handler->execute(Fixtures::command('execution.start-repair', [], 'r1'));
            $this->fail('esperava DomainException');
        } catch (\App\Domain\Exception\DomainException $e) {
            $this->assertStringContainsString('QUEUED_DIAGNOSIS', $e->getMessage());
        }

        // A inbox não registrou a mensagem: a reentrega será tratada de verdade.
        $this->h->call('startDiagnosis', ['id' => Fixtures::OS]);
        $this->h->call('completeDiagnosis', ['id' => Fixtures::OS], ['items' => [['part_id' => 'p']]]);
        $this->assertSame(Handler::OUTCOME_OK, $this->h->handler->execute(Fixtures::command('execution.start-repair', [], 'r1')));
    }

    public function testStartRepairDeTarefaEncerradaEConfirmadoSemEfeito(): void
    {
        $this->h->handler->execute(Fixtures::command('execution.cancel', [], 'c1'));

        $this->assertSame(Handler::OUTCOME_DUPLICATE, $this->h->handler->execute(Fixtures::command('execution.start-repair', [], 'r1')));
    }

    public function testStartRepairSemTarefaFalhaParaAMensagemVoltarAFila(): void
    {
        $this->expectException(NotFoundException::class);

        $this->h->handler->execute(Fixtures::command('execution.start-repair'));
    }

    public function testCancelEncerraATarefaEConfirmaPelaOutbox(): void
    {
        $this->diagnosed();
        foreach ($this->h->tasks->pending() as $m) {
            $this->h->tasks->markPublished($m);
        }

        $outcome = $this->h->handler->execute(Fixtures::command('execution.cancel', ['reason' => 'orçamento rejeitado'], 'm-cancel'));

        $task = $this->h->tasks->find(Fixtures::OS);
        $this->assertSame(Handler::OUTCOME_OK, $outcome);
        $this->assertNotNull($task);
        $this->assertSame(TaskStatus::Cancelled, $task->getStatus());
        $pending = $this->h->tasks->pending();
        $this->assertSame(['execution.cancelled'], array_map(static fn ($m) => $m->type, $pending));
        $this->assertSame(Fixtures::SAGA, $pending[0]->sagaId);
        $this->assertSame('execucao', $pending[0]->producer);
        $this->assertSame('corr-1', $pending[0]->correlationId);
    }

    public function testCancelDeTarefaQueNaoExisteConfirmaEBloqueiaDiagnosticoAtrasado(): void
    {
        $this->h->handler->execute(Fixtures::command('execution.cancel', [], 'm-cancel'));

        $task = $this->h->tasks->find(Fixtures::OS);
        $this->assertNotNull($task);
        $this->assertSame(TaskStatus::Cancelled, $task->getStatus());
        $this->assertSame(['execution.cancelled'], array_map(static fn ($m) => $m->type, $this->h->tasks->pending()));

        // O pedido de diagnóstico chegou depois do cancelamento: não reabre a tarefa.
        $outcome = $this->h->handler->execute(Fixtures::command('execution.request-diagnosis', Fixtures::diagnosisPayload(), 'm-diag'));
        $this->assertSame(Handler::OUTCOME_DUPLICATE, $outcome);
        $found = $this->h->tasks->find(Fixtures::OS);
        $this->assertNotNull($found);
        $this->assertSame(TaskStatus::Cancelled, $found->getStatus());
    }

    public function testTipoDesconhecidoEIgnorado(): void
    {
        $this->assertSame(Handler::OUTCOME_IGNORED, $this->h->handler->execute(Fixtures::command('budget.generated')));
        $this->assertNull($this->h->tasks->find(Fixtures::OS));
    }
}
