<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Aws;

use App\Application\Port\ReceivedMessage;
use App\Application\Worker\Worker;
use App\Infrastructure\Aws\AwsClientFactory;
use App\Infrastructure\Aws\SnsMessagePublisher;
use App\Infrastructure\Aws\SqsMessageQueue;
use App\Infrastructure\ServiceFactory;
use App\Infrastructure\Time\SystemClock;
use App\Infrastructure\Tracing\NewRelicTracer;
use App\Presentation\Controller\TaskController;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sns\SnsClient;
use Aws\Sqs\SqsClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;
use Tests\Support\Harness;

class MessagingAdaptersTest extends TestCase
{
    /** @var list<CommandInterface<mixed>> */
    private array $commands = [];

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function config(array $result = []): array
    {
        $mock = new MockHandler();
        $mock->append(function (CommandInterface $command) use ($result): Result {
            $this->commands[] = $command;

            return new Result($result);
        });

        return ['version' => 'latest', 'region' => 'us-east-1', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $mock];
    }

    public function testPublicaNoTopicoComOAtributoTypeDoFiltro(): void
    {
        $message = Fixtures::command('diagnosis.started', ['task_id' => 'os-1']);

        (new SnsMessagePublisher(new SnsClient($this->config()), 'arn:topico'))->publish($message);

        $command = $this->commands[0];
        $this->assertSame('arn:topico', $command['TopicArn']);
        $this->assertSame($message->toJson(), $command['Message']);
        $this->assertSame(
            ['DataType' => 'String', 'StringValue' => 'diagnosis.started'],
            $command['MessageAttributes']['type'],
        );
    }

    public function testRecebeDaFilaComLongPollingDentroDosLimitesDoSqs(): void
    {
        $queue = new SqsMessageQueue(
            new SqsClient($this->config(['Messages' => [['Body' => '{"a":1}', 'ReceiptHandle' => 'r-1']]])),
            'https://fila',
        );

        $messages = $queue->receive(50, 99);

        $this->assertSame('https://fila', $this->commands[0]['QueueUrl']);
        $this->assertSame(10, $this->commands[0]['MaxNumberOfMessages']);
        $this->assertSame(20, $this->commands[0]['WaitTimeSeconds']);
        $this->assertEquals([new ReceivedMessage('{"a":1}', 'r-1')], $messages);
    }

    public function testFilaVaziaDevolveListaVazia(): void
    {
        $queue = new SqsMessageQueue(new SqsClient($this->config()), 'https://fila');

        $this->assertSame([], $queue->receive());
    }

    public function testConfirmaPeloRecibo(): void
    {
        $queue = new SqsMessageQueue(new SqsClient($this->config()), 'https://fila');

        $queue->delete(new ReceivedMessage('corpo', 'r-7'));

        $this->assertSame('DeleteMessage', $this->commands[0]->getName());
        $this->assertSame('r-7', $this->commands[0]['ReceiptHandle']);
    }

    public function testNoClusterNaoHaCredencialNemEndpointNoCodigo(): void
    {
        $config = (new AwsClientFactory(['AWS_REGION' => 'sa-east-1']))->config();

        $this->assertSame('sa-east-1', $config['region']);
        $this->assertArrayNotHasKey('endpoint', $config);
        $this->assertArrayNotHasKey('credentials', $config, 'a credencial vem do role do ServiceAccount');
    }

    public function testEndpointLocalUsaCredencialDeMentira(): void
    {
        $factory = new AwsClientFactory(['AWS_ENDPOINT_URL' => 'http://localstack:4566']);

        $this->assertSame('http://localstack:4566', $factory->config()['endpoint']);
        $this->assertSame('us-east-1', $factory->config()['region']);
        $this->assertSame('http://localstack:4566', (string)$factory->dynamoDb()->getEndpoint());
        $this->assertSame('http://localstack:4566', (string)$factory->sns()->getEndpoint());
        $this->assertSame('http://localstack:4566', (string)$factory->sqs()->getEndpoint());
    }

    public function testMontagemDoServicoAPartirDoAmbiente(): void
    {
        $factory = new ServiceFactory([
            'AWS_ENDPOINT_URL'    => 'http://localstack:4566',
            'DYNAMODB_TABLE'      => 't',
            'AWS_REGION'          => 'us-east-1',
            'MESSAGING_TOPIC_ARN' => 'arn:t',
            'MESSAGING_QUEUE_URL' => 'http://q',
        ]);

        $this->assertInstanceOf(TaskController::class, $factory->taskController(new Harness()));
        $this->assertInstanceOf(Worker::class, $factory->worker(new Harness()));
        $this->assertSame($factory->repository(), $factory->repository());
    }

    public function testSemAgenteORastreadorNaoFazNada(): void
    {
        $tracer = new NewRelicTracer('oficina-execucao', false);

        $this->assertNull($tracer->currentTrace());
        $tracer->startMessage('execution.cancel', '{"traceparent":"x"}');
        $tracer->endMessage(true);
        $this->addToAssertionCount(1);
    }

    public function testRelogioEmUtc(): void
    {
        $this->assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
    }
}
