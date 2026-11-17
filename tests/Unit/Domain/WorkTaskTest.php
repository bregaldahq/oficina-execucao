<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Exception\DomainException;
use App\Domain\Exception\InvalidTaskTransitionException;
use App\Domain\WorkTask\TaskItem;
use App\Domain\WorkTask\TaskStatus;
use App\Domain\WorkTask\WorkTask;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;

class WorkTaskTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-15T10:00:00Z');
    }

    /** @return list<string> */
    private function eventTypes(WorkTask $task): array
    {
        return array_column($task->releaseEvents(), 'type');
    }

    public function testNasceNaFilaDeDiagnosticoSemAvisarNinguem(): void
    {
        $task = Fixtures::task($this->now);

        $this->assertSame(TaskStatus::QueuedDiagnosis, $task->getStatus());
        $this->assertSame(0, $task->getVersion());
        $this->assertSame([], $task->releaseEvents());
        $this->assertSame(['task.opened'], array_map(static fn ($e) => $e->type, $task->releaseTimeline()));
    }

    public function testExigeOsESaga(): void
    {
        $this->expectException(DomainException::class);

        WorkTask::open('', 'saga', 'c', 'n', [], 'd', $this->now);
    }

    public function testCaminhoFelizEmiteOsEventosDoContrato(): void
    {
        $task = Fixtures::task($this->now);

        $task->startDiagnosis($this->now);
        $task->completeDiagnosis(Fixtures::items(), $this->now);
        $task->queueRepair([], $this->now);
        $task->startRepair($this->now);
        $task->completeRepair($this->now);

        $this->assertSame(TaskStatus::Repaired, $task->getStatus());
        $this->assertTrue($task->getStatus()->isFinal());

        $events = $task->releaseEvents();
        $this->assertSame(
            ['diagnosis.started', 'diagnosis.completed', 'repair.started', 'repair.completed'],
            array_column($events, 'type'),
        );
        $this->assertSame(Fixtures::OS, $events[0]['payload']['task_id']);
        $this->assertSame(
            [['kind' => 'service', 'id' => 'svc-1', 'quantity' => 1], ['kind' => 'part', 'id' => 'part-9', 'quantity' => 2]],
            $events[1]['payload']['items'],
        );
        $this->assertSame([], $task->releaseEvents(), 'os eventos saem uma vez só');
    }

    public function testQueueRepairTrocaOsItensPelosAprovados(): void
    {
        $task = Fixtures::task($this->now);
        $task->startDiagnosis($this->now);
        $task->completeDiagnosis(Fixtures::items(), $this->now);

        $task->queueRepair([new TaskItem(TaskItem::KIND_SERVICE, 'svc-1', 1)], $this->now);

        $this->assertCount(1, $task->getItems());
        $this->assertSame(TaskStatus::QueuedRepair, $task->getStatus());
    }

    public function testDiagnosticoSemItensERecusado(): void
    {
        $task = Fixtures::task($this->now);
        $task->startDiagnosis($this->now);

        $this->expectException(DomainException::class);
        $task->completeDiagnosis([], $this->now);
    }

    public function testFalhaNoDiagnosticoEncerraATarefaComOMotivo(): void
    {
        $task = Fixtures::task($this->now);
        $task->startDiagnosis($this->now);
        $task->releaseEvents();

        $task->failDiagnosis('veículo sem conserto', $this->now);

        $this->assertSame(TaskStatus::DiagnosisFailed, $task->getStatus());
        $this->assertSame('veículo sem conserto', $task->getFailureReason());
        $events = $task->releaseEvents();
        $this->assertSame('diagnosis.failed', $events[0]['type']);
        $this->assertSame('veículo sem conserto', $events[0]['payload']['reason']);
    }

    public function testFalhaNoReparoEmiteRepairFailed(): void
    {
        $task = Fixtures::task($this->now);
        $task->startDiagnosis($this->now);
        $task->completeDiagnosis(Fixtures::items(), $this->now);
        $task->queueRepair([], $this->now);
        $task->startRepair($this->now);
        $task->releaseEvents();

        $task->failRepair('peça com defeito', $this->now);

        $this->assertSame(TaskStatus::RepairFailed, $task->getStatus());
        $this->assertSame(['repair.failed'], $this->eventTypes($task));
    }

    public function testFalhaSemMotivoERecusada(): void
    {
        $task = Fixtures::task($this->now);
        $task->startDiagnosis($this->now);

        $this->expectException(DomainException::class);
        $task->failDiagnosis('  ', $this->now);
    }

    /** @return array<string, array{0: callable(WorkTask, \DateTimeImmutable): void}> */
    public static function acoesForaDeHora(): array
    {
        return [
            'concluir diagnóstico sem iniciar'  => [fn (WorkTask $t, $n) => $t->completeDiagnosis(Fixtures::items(), $n)],
            'falhar diagnóstico sem iniciar'    => [fn (WorkTask $t, $n) => $t->failDiagnosis('x', $n)],
            'enfileirar reparo sem diagnóstico' => [fn (WorkTask $t, $n) => $t->queueRepair([], $n)],
            'iniciar reparo sem pagamento'      => [fn (WorkTask $t, $n) => $t->startRepair($n)],
            'concluir reparo sem iniciar'       => [fn (WorkTask $t, $n) => $t->completeRepair($n)],
            'falhar reparo sem iniciar'         => [fn (WorkTask $t, $n) => $t->failRepair('x', $n)],
        ];
    }

    /** @param callable(WorkTask, \DateTimeImmutable): void $action */
    #[DataProvider('acoesForaDeHora')]
    public function testTransicaoInvalidaLancaExcecaoENaoMudaNada(callable $action): void
    {
        $task = Fixtures::task($this->now);

        try {
            $action($task, $this->now);
            $this->fail('esperava InvalidTaskTransitionException');
        } catch (InvalidTaskTransitionException $e) {
            $this->assertStringContainsString('QUEUED_DIAGNOSIS', $e->getMessage());
        }

        $this->assertSame(TaskStatus::QueuedDiagnosis, $task->getStatus());
        $this->assertSame([], $task->releaseEvents());
    }

    public function testIniciarDiagnosticoDuasVezesERecusado(): void
    {
        $task = Fixtures::task($this->now);
        $task->startDiagnosis($this->now);

        $this->expectException(InvalidTaskTransitionException::class);
        $task->startDiagnosis($this->now);
    }

    public function testCancelarEncerraEConfirma(): void
    {
        $task = Fixtures::task($this->now);
        $task->startDiagnosis($this->now);
        $task->releaseEvents();

        $task->cancel('orçamento rejeitado', $this->now);

        $this->assertSame(TaskStatus::Cancelled, $task->getStatus());
        $this->assertSame('orçamento rejeitado', $task->getFailureReason());
        $this->assertSame(['execution.cancelled'], $this->eventTypes($task));
    }

    public function testCancelarTarefaJaEncerradaSoConfirmaDeNovo(): void
    {
        $task = Fixtures::task($this->now);
        $task->startDiagnosis($this->now);
        $task->failDiagnosis('sem conserto', $this->now);
        $task->releaseEvents();
        $task->releaseTimeline();

        $task->cancel('compensação', $this->now);

        $this->assertSame(TaskStatus::DiagnosisFailed, $task->getStatus(), 'o status final não muda');
        $this->assertSame('sem conserto', $task->getFailureReason());
        $this->assertSame([], $task->releaseTimeline());
        $this->assertSame(['execution.cancelled'], $this->eventTypes($task));
    }

    public function testReconstituteNaoGeraEventoNemLinhaDoTempo(): void
    {
        $task = WorkTask::reconstitute(
            'os-9',
            'saga-9',
            'c',
            'João',
            ['license_plate' => 'X', 'brand' => 'Y', 'model' => 'Z'],
            'desc',
            TaskStatus::Diagnosed,
            Fixtures::items(),
            null,
            $this->now,
            $this->now,
            3,
        );

        $this->assertSame(3, $task->getVersion());
        $this->assertSame([], $task->releaseEvents());
        $this->assertSame([], $task->releaseTimeline());
        $this->assertSame('DIAGNOSED', $task->toArray()['status']);
        $this->assertSame('João', $task->getCustomerName());
        $this->assertSame('desc', $task->getDescription());
        $this->assertSame('X', $task->getVehicle()['license_plate']);
        $this->assertSame('saga-9', $task->getSagaId());
        $this->assertEquals($this->now, $task->getCreatedAt());
        $this->assertEquals($this->now, $task->getUpdatedAt());
    }
}
