<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Domain\WorkTask\TaskStatus;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;
use Tests\Support\Harness;

class WorkerTest extends TestCase
{
    private Harness $h;

    protected function setUp(): void
    {
        $this->h = new Harness();
    }

    public function testConsomeConfirmaERegistraUmaLinhaPorMensagem(): void
    {
        $this->h->bus->deliver(Fixtures::command('execution.request-diagnosis', Fixtures::diagnosisPayload())->withTrace('t-1'));

        $result = $this->h->worker->tick(0);

        $this->assertSame(['published' => 0, 'processed' => 1, 'failed' => 0], $result);
        $this->assertSame(0, $this->h->bus->queued(), 'mensagem tratada sai da fila');
        $this->assertNotNull($this->h->tasks->find(Fixtures::OS));
        $this->assertSame(['start:execution.request-diagnosis:t-1', 'end:ok'], $this->h->traces);

        $log = $this->h->logs[0];
        $this->assertSame('message.processed', $log['message']);
        $this->assertSame('ok', $log['context']['outcome']);
        $this->assertSame('msg-1', $log['context']['message_id']);
        $this->assertSame(Fixtures::SAGA, $log['context']['saga_id']);
        $this->assertSame(Fixtures::OS, $log['context']['os_id']);
    }

    public function testPublicaAOutboxComOTraceEEsvaziaAPendencia(): void
    {
        $this->h->bus->deliver(Fixtures::command('execution.request-diagnosis', Fixtures::diagnosisPayload()));
        $this->h->worker->tick(0);
        $this->h->call('startDiagnosis', ['id' => Fixtures::OS]);

        $result = $this->h->worker->tick(0);

        $this->assertSame(1, $result['published']);
        $this->assertSame(['diagnosis.started'], $this->h->bus->publishedTypes());
        $this->assertSame('trace-do-teste', $this->h->bus->published()[0]->trace);
        $this->assertSame([], $this->h->tasks->pending());
    }

    public function testOQueOTratamentoGravaSaiNaMesmaVolta(): void
    {
        $this->h->bus->deliver(Fixtures::command('execution.cancel', ['reason' => 'x']));

        $result = $this->h->worker->tick(0);

        $this->assertSame(1, $result['published']);
        $this->assertSame(['execution.cancelled'], $this->h->bus->publishedTypes());
    }

    public function testFalhaNoTratamentoDeixaAMensagemNaFila(): void
    {
        // start-repair sem tarefa: o handler lança, a mensagem não é confirmada.
        $this->h->bus->deliver(Fixtures::command('execution.start-repair'));

        $result = $this->h->worker->tick(0);

        $this->assertSame(['published' => 0, 'processed' => 0, 'failed' => 1], $result);
        $this->assertSame(1, $this->h->bus->queued(), 'fica para a reentrega e, depois, para a DLQ');
        $this->assertSame('error', $this->h->logs[0]['level']);
        $this->assertSame('error', $this->h->logs[0]['context']['outcome']);
        $this->assertContains('end:falha', $this->h->traces);
    }

    public function testMensagemForaDoEnvelopeNaoDerrubaAsOutras(): void
    {
        $this->h->bus->deliver('isto não é uma mensagem');
        $this->h->bus->deliver(Fixtures::command('execution.request-diagnosis', Fixtures::diagnosisPayload()));

        $result = $this->h->worker->tick(0);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('message.invalid', $this->h->logs[0]['message']);
        $this->assertSame(1, $this->h->bus->queued());
    }

    public function testReentregaDaMesmaMensagemEConfirmadaSemEfeito(): void
    {
        $command = Fixtures::command('execution.request-diagnosis', Fixtures::diagnosisPayload());
        $this->h->bus->deliver($command);
        $this->h->worker->tick(0);
        $this->h->bus->deliver($command);

        $this->h->worker->tick(0);

        $this->assertSame('duplicate', $this->h->logs[1]['context']['outcome']);
        $this->assertSame(0, $this->h->bus->queued());
        $this->assertCount(1, $this->h->tasks->listByStatus(TaskStatus::QueuedDiagnosis));
    }
}
