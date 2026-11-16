<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Controller;

use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;
use Tests\Support\Harness;

class TaskControllerTest extends TestCase
{
    private Harness $h;

    /** @var array{id: string} */
    private array $id = ['id' => Fixtures::OS];

    protected function setUp(): void
    {
        $this->h = new Harness();
        $this->h->handler->execute(Fixtures::command('execution.request-diagnosis', Fixtures::diagnosisPayload()));
    }

    public function testFluxoCompletoDoMecanico(): void
    {
        [$status, $body] = $this->h->call('index', []);
        $this->assertSame(200, $status);
        $this->assertSame(Fixtures::OS, $body['data'][0]['id']);

        $this->assertSame('DIAGNOSING', $this->h->call('startDiagnosis', $this->id)[1]['status']);

        [$status, $body] = $this->h->call('completeDiagnosis', $this->id, [
            'items' => [['service_item_id' => 'svc-1'], ['part_id' => 'p-7', 'quantity' => 2]],
        ]);
        $this->assertSame(200, $status);
        $this->assertSame('DIAGNOSED', $body['status']);
        $this->assertSame(
            [['kind' => 'service', 'id' => 'svc-1', 'quantity' => 1], ['kind' => 'part', 'id' => 'p-7', 'quantity' => 2]],
            $body['items'],
        );

        $this->h->handler->execute(Fixtures::command('execution.start-repair', [], 'm-2'));
        $this->assertSame('QUEUED_REPAIR', $this->h->call('index', ['status' => 'queued_repair'])[1]['data'][0]['status']);
        $this->assertSame('REPAIRING', $this->h->call('startRepair', $this->id)[1]['status']);
        $this->assertSame('REPAIRED', $this->h->call('completeRepair', $this->id)[1]['status']);

        [$status, $body] = $this->h->call('show', $this->id);
        $this->assertSame(200, $status);
        $this->assertSame(
            ['task.opened', 'diagnosis.started', 'diagnosis.completed', 'repair.queued', 'repair.started', 'repair.completed'],
            array_column($body['timeline'], 'type'),
        );
    }

    public function testFalhasLevamOMotivo(): void
    {
        $this->h->call('startDiagnosis', $this->id);

        [$status, $body] = $this->h->call('failDiagnosis', $this->id, ['reason' => 'sem conserto']);

        $this->assertSame(200, $status);
        $this->assertSame('DIAGNOSIS_FAILED', $body['status']);
        $this->assertSame('sem conserto', $body['failure_reason']);
    }

    public function testFalhaNoReparo(): void
    {
        $this->h->call('startDiagnosis', $this->id);
        $this->h->call('completeDiagnosis', $this->id, ['items' => [['part_id' => 'p']]]);
        $this->h->handler->execute(Fixtures::command('execution.start-repair', [], 'm-2'));
        $this->h->call('startRepair', $this->id);

        [$status, $body] = $this->h->call('failRepair', $this->id, ['reason' => 'peça errada']);

        $this->assertSame(200, $status);
        $this->assertSame('REPAIR_FAILED', $body['status']);
    }

    public function testTarefaInexistenteE404(): void
    {
        $this->assertSame(404, $this->h->call('show', ['id' => 'nao-existe'])[0]);
        $this->assertSame(404, $this->h->call('startDiagnosis', ['id' => 'nao-existe'])[0]);
    }

    public function testTransicaoInvalidaE422(): void
    {
        [$status, $body] = $this->h->call('completeRepair', $this->id);

        $this->assertSame(422, $status);
        $this->assertStringContainsString('QUEUED_DIAGNOSIS', $body['error']);
    }

    public function testStatusDesconhecidoNaListagemE422(): void
    {
        $this->assertSame(422, $this->h->call('index', ['status' => 'inventado'])[0]);
    }

    public function testFalhaSemMotivoE422(): void
    {
        $this->h->call('startDiagnosis', $this->id);

        $this->assertSame(422, $this->h->call('failDiagnosis', $this->id, [])[0]);
    }

    public function testItensInvalidosNoDiagnosticoSao422(): void
    {
        $this->h->call('startDiagnosis', $this->id);

        foreach ([
            [],
            ['items' => []],
            ['items' => ['texto']],
            ['items' => [['quantity' => 1]]],
            ['items' => [['service_item_id' => 'a', 'part_id' => 'b']]],
            ['items' => [['part_id' => 'b', 'quantity' => 0]]],
        ] as $body) {
            $this->assertSame(422, $this->h->call('completeDiagnosis', $this->id, $body)[0], (string)json_encode($body));
        }
    }
}
