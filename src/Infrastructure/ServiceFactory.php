<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\Messaging\MessageFactory;
use App\Application\Messaging\OutboxRelay;
use App\Application\Port\LoggerInterface;
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
use App\Infrastructure\Aws\AwsClientFactory;
use App\Infrastructure\Aws\DynamoDbWorkTaskRepository;
use App\Infrastructure\Aws\SnsMessagePublisher;
use App\Infrastructure\Aws\SqsMessageQueue;
use App\Infrastructure\Time\SystemClock;
use App\Infrastructure\Tracing\NewRelicTracer;
use App\Presentation\Controller\TaskController;

/**
 * Monta as dependências do serviço a partir do ambiente. É a única parte que conhece as classes
 * concretas; public/index.php (API) e bin/worker.php (worker) usam a mesma montagem.
 */
final class ServiceFactory
{
    private ?DynamoDbWorkTaskRepository $repository = null;

    /** @param array<string, mixed> $env */
    public function __construct(private readonly array $env)
    {
    }

    public function repository(): DynamoDbWorkTaskRepository
    {
        return $this->repository ??= new DynamoDbWorkTaskRepository(
            $this->aws()->dynamoDb(),
            (string)($this->env['DYNAMODB_TABLE'] ?? ''),
        );
    }

    public function relay(LoggerInterface $logger): OutboxRelay
    {
        return new OutboxRelay(
            $this->repository(),
            new SnsMessagePublisher($this->aws()->sns(), (string)($this->env['MESSAGING_TOPIC_ARN'] ?? '')),
            new NewRelicTracer((string)($this->env['NEW_RELIC_APP_NAME'] ?? 'oficina-execucao')),
            $logger,
        );
    }

    public function taskController(LoggerInterface $logger): TaskController
    {
        $tasks    = $this->repository();
        $clock    = new SystemClock();
        $messages = new MessageFactory(new UuidGenerator(), $clock);
        $relay    = $this->relay($logger);

        return new TaskController(
            new ListTasksUseCase($tasks),
            new GetTaskUseCase($tasks),
            new StartDiagnosisUseCase($tasks, $messages, $clock, $relay),
            new CompleteDiagnosisUseCase($tasks, $messages, $clock, $relay),
            new FailDiagnosisUseCase($tasks, $messages, $clock, $relay),
            new StartRepairUseCase($tasks, $messages, $clock, $relay),
            new CompleteRepairUseCase($tasks, $messages, $clock, $relay),
            new FailRepairUseCase($tasks, $messages, $clock, $relay),
        );
    }

    public function worker(LoggerInterface $logger): Worker
    {
        $tasks = $this->repository();
        $clock = new SystemClock();

        return new Worker(
            $this->relay($logger),
            new SqsMessageQueue($this->aws()->sqs(), (string)($this->env['MESSAGING_QUEUE_URL'] ?? '')),
            new HandleIncomingMessageUseCase($tasks, new MessageFactory(new UuidGenerator(), $clock), $clock),
            new NewRelicTracer((string)($this->env['NEW_RELIC_APP_NAME'] ?? 'oficina-execucao')),
            $logger,
        );
    }

    private function aws(): AwsClientFactory
    {
        return new AwsClientFactory($this->env);
    }
}
