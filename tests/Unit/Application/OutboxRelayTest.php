<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Messaging\OutboxRelay;
use App\Application\Port\MessagePublisherInterface;
use App\Application\Port\OutboxInterface;
use App\Application\UseCase\Task\StartDiagnosisUseCase;
use App\Domain\Messaging\Message;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;
use Tests\Support\Harness;

class OutboxRelayTest extends TestCase
{
    private Harness $h;

    protected function setUp(): void
    {
        $this->h = new Harness();
        $this->h->handler->execute(Fixtures::command('execution.request-diagnosis', Fixtures::diagnosisPayload()));
    }

    private function failingPublisher(string $failOnType): MessagePublisherInterface
    {
        return new class ($failOnType, $this->h) implements MessagePublisherInterface {
            public function __construct(private readonly string $failOnType, private readonly Harness $h)
            {
            }

            public function publish(Message $message): void
            {
                if ($message->type === $this->failOnType) {
                    throw new \RuntimeException('SNS fora do ar');
                }
                $this->h->bus->publish($message);
            }
        };
    }

    public function testFalhaAoPublicarNaoLancaEAMensagemContinuaPendente(): void
    {
        $this->h->call('startDiagnosis', ['id' => Fixtures::OS]);
        $this->h->call('completeDiagnosis', ['id' => Fixtures::OS], ['items' => [['part_id' => 'p']]]);
        $relay = new OutboxRelay($this->h->tasks, $this->failingPublisher('diagnosis.started'), $this->h, $this->h);

        $published = $relay->relay();

        $this->assertSame(1, $published, 'a que falhou não impede a seguinte');
        $this->assertSame(['diagnosis.completed'], $this->h->bus->publishedTypes());
        $this->assertSame(['diagnosis.started'], array_map(static fn (Message $m) => $m->type, $this->h->tasks->pending()));
        $this->assertSame('outbox.publish_failed', $this->h->logs[0]['message']);
        $this->assertSame(Fixtures::SAGA, $this->h->logs[0]['context']['saga_id']);

        $this->assertSame(1, $this->h->relay->relay(), 'a próxima passada publica a que ficou');
    }

    public function testFalhaAoLerAOutboxNaoLanca(): void
    {
        $outbox = new class () implements OutboxInterface {
            public function pending(int $limit = 25): array
            {
                throw new \RuntimeException('DynamoDB fora do ar');
            }

            public function markPublished(Message $message): void
            {
            }
        };

        $this->assertSame(0, (new OutboxRelay($outbox, $this->h->bus, $this->h, $this->h))->relay());
        $this->assertSame('outbox.read_failed', $this->h->logs[0]['message']);
    }

    public function testAcaoDaApiPublicaNaHoraSemEsperarOWorker(): void
    {
        $useCase = new StartDiagnosisUseCase($this->h->tasks, $this->h->messages, $this->h->clock, $this->h->relay);

        $useCase->execute(Fixtures::OS);

        $this->assertSame(['diagnosis.started'], $this->h->bus->publishedTypes());
        $this->assertSame([], $this->h->tasks->pending());
    }

    public function testComOTopicoForaDoArAAcaoDaApiDaCertoEOEventoFicaNaOutbox(): void
    {
        $relay   = new OutboxRelay($this->h->tasks, $this->failingPublisher('diagnosis.started'), $this->h, $this->h);
        $useCase = new StartDiagnosisUseCase($this->h->tasks, $this->h->messages, $this->h->clock, $relay);

        $task = $useCase->execute(Fixtures::OS);

        $this->assertSame('DIAGNOSING', $task->getStatus()->value);
        $this->assertCount(1, $this->h->tasks->pending(), 'o worker publica depois');
    }

    public function testWorkerConsomeAFilaMesmoComOTopicoForaDoAr(): void
    {
        $this->h->call('startDiagnosis', ['id' => Fixtures::OS]);
        $worker = new \App\Application\Worker\Worker(
            new OutboxRelay($this->h->tasks, $this->failingPublisher('diagnosis.started'), $this->h, $this->h),
            $this->h->bus,
            $this->h->handler,
            $this->h,
            $this->h,
        );
        $this->h->bus->deliver(Fixtures::command('execution.cancel', ['reason' => 'x'], 'c-1'));

        $result = $worker->tick(0);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(['execution.cancelled'], $this->h->bus->publishedTypes());
    }
}
