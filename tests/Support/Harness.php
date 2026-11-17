<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Messaging\MessageFactory;
use App\Application\Messaging\OutboxRelay;
use App\Application\Port\LoggerInterface;
use App\Application\Port\TracerInterface;
use App\Application\UseCase\HandleIncomingMessageUseCase;
use App\Application\UseCase\Task\CompleteDiagnosisUseCase;
use App\Application\UseCase\Task\CompleteRepairUseCase;
use App\Application\UseCase\Task\FailDiagnosisUseCase;
use App\Application\UseCase\Task\FailRepairUseCase;
use App\Application\UseCase\Task\GetTaskUseCase;
use App\Application\UseCase\Task\ListTasksUseCase;
use App\Application\UseCase\Task\StartDiagnosisUseCase;
use App\Application\UseCase\Task\StartRepairUseCase;
use App\Application\Worker\Worker;
use App\Infrastructure\InMemory\InMemoryBus;
use App\Infrastructure\InMemory\InMemoryWorkTaskRepository;
use App\Presentation\Controller\TaskController;

/** O serviço inteiro montado em memória: o que os testes e o BDD usam no lugar da AWS. */
final class Harness implements LoggerInterface, TracerInterface
{
    public InMemoryWorkTaskRepository $tasks;
    public InMemoryBus $bus;
    public FixedClock $clock;
    public MessageFactory $messages;
    public HandleIncomingMessageUseCase $handler;
    public OutboxRelay $relay;
    public Worker $worker;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $logs = [];

    /** @var list<string> */
    public array $traces = [];

    public function __construct()
    {
        $this->tasks    = new InMemoryWorkTaskRepository();
        $this->bus      = new InMemoryBus();
        $this->clock    = new FixedClock();
        $this->messages = new MessageFactory(new SequenceUuid(), $this->clock);
        $this->handler  = new HandleIncomingMessageUseCase($this->tasks, $this->messages, $this->clock);
        $this->relay    = new OutboxRelay($this->tasks, $this->bus, $this, $this);
        $this->worker   = new Worker($this->relay, $this->bus, $this->handler, $this, $this);
    }

    public function controller(): TaskController
    {
        return new TaskController(
            new ListTasksUseCase($this->tasks),
            new GetTaskUseCase($this->tasks),
            new StartDiagnosisUseCase($this->tasks, $this->messages, $this->clock),
            new CompleteDiagnosisUseCase($this->tasks, $this->messages, $this->clock),
            new FailDiagnosisUseCase($this->tasks, $this->messages, $this->clock),
            new StartRepairUseCase($this->tasks, $this->messages, $this->clock),
            new CompleteRepairUseCase($this->tasks, $this->messages, $this->clock),
            new FailRepairUseCase($this->tasks, $this->messages, $this->clock),
        );
    }

    /**
     * Chama uma ação do controller como o roteador chamaria e devolve [status HTTP, corpo].
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function call(string $action, mixed ...$args): array
    {
        http_response_code(200);
        ob_start();
        $this->controller()->{$action}(...$args);
        $body = json_decode((string)ob_get_clean(), true);

        return [(int)http_response_code(), is_array($body) ? $body : []];
    }

    public function info(string $message, array $context = []): void
    {
        $this->logs[] = ['level' => 'info', 'message' => $message, 'context' => $context];
    }

    public function error(string $message, array $context = []): void
    {
        $this->logs[] = ['level' => 'error', 'message' => $message, 'context' => $context];
    }

    public function currentTrace(): ?string
    {
        return 'trace-do-teste';
    }

    public function startMessage(string $type, ?string $trace): void
    {
        $this->traces[] = "start:{$type}:" . ($trace ?? '-');
    }

    public function endMessage(bool $failed): void
    {
        $this->traces[] = 'end:' . ($failed ? 'falha' : 'ok');
    }
}
