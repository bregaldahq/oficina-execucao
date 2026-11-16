<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\InMemory;

use App\Domain\Exception\ConcurrencyException;
use App\Domain\Exception\DuplicateMessageException;
use App\Domain\WorkTask\TaskStatus;
use App\Infrastructure\InMemory\InMemoryBus;
use App\Infrastructure\InMemory\InMemoryWorkTaskRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;

class InMemoryWorkTaskRepositoryTest extends TestCase
{
    public function testGravaERecarregaComAVersaoSeguinte(): void
    {
        $repo = new InMemoryWorkTaskRepository();
        $repo->save(Fixtures::task());

        $task = $repo->find(Fixtures::OS);

        $this->assertNotNull($task);
        $this->assertSame(1, $task->getVersion());
        $this->assertNull($repo->find('outra'));
        $this->assertSame([], $repo->timeline('outra'));
    }

    public function testDuasEscritasSobreAMesmaLeituraASegundaFalha(): void
    {
        $repo = new InMemoryWorkTaskRepository();
        $repo->save(Fixtures::task());
        $a = $repo->find(Fixtures::OS);
        $b = $repo->find(Fixtures::OS);
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $now = new \DateTimeImmutable('2026-11-15T11:00:00Z');

        $a->startDiagnosis($now);
        $repo->save($a);

        $b->startDiagnosis($now);
        $this->expectException(ConcurrencyException::class);
        $repo->save($b);
    }

    public function testInboxRecusaAMesmaMensagemSemGravarNada(): void
    {
        $repo = new InMemoryWorkTaskRepository();
        $repo->save(Fixtures::task(), [], 'm-1');
        $task = $repo->find(Fixtures::OS);
        $this->assertNotNull($task);
        $task->startDiagnosis(new \DateTimeImmutable('2026-11-15T11:00:00Z'));

        try {
            $repo->save($task, [], 'm-1');
            $this->fail('esperava DuplicateMessageException');
        } catch (DuplicateMessageException) {
            $found = $repo->find(Fixtures::OS);
            $this->assertNotNull($found);
            $this->assertSame(TaskStatus::QueuedDiagnosis, $found->getStatus());
        }
    }

    public function testFilaPorStatusDasMaisAntigasParaAsMaisNovas(): void
    {
        $repo = new InMemoryWorkTaskRepository();
        foreach (['os-b' => '2026-11-15T12:00:00Z', 'os-a' => '2026-11-15T09:00:00Z'] as $osId => $when) {
            $repo->save(\App\Domain\WorkTask\WorkTask::open($osId, 's', 'c', 'n', [], 'd', new \DateTimeImmutable($when)));
        }

        $ids = array_map(static fn ($t) => $t->getOsId(), $repo->listByStatus(TaskStatus::QueuedDiagnosis));

        $this->assertSame(['os-a', 'os-b'], $ids);
        $this->assertCount(1, $repo->listByStatus(TaskStatus::QueuedDiagnosis, 1));
        $this->assertSame([], $repo->listByStatus(TaskStatus::Repaired));
    }

    public function testBarramentoEntregaPeloMenosUmaVez(): void
    {
        $bus = new InMemoryBus();
        $bus->deliver(Fixtures::command('execution.cancel'));

        $first = $bus->receive();
        $this->assertCount(1, $bus->receive(), 'sem delete a mensagem volta');

        $bus->delete($first[0]);
        $this->assertSame([], $bus->receive());
        $this->assertSame(0, $bus->queued());
    }
}
