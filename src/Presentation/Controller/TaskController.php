<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\UseCase\Task\CompleteDiagnosisUseCase;
use App\Application\UseCase\Task\CompleteRepairUseCase;
use App\Application\UseCase\Task\FailDiagnosisUseCase;
use App\Application\UseCase\Task\FailRepairUseCase;
use App\Application\UseCase\Task\GetTaskUseCase;
use App\Application\UseCase\Task\ListTasksUseCase;
use App\Application\UseCase\Task\StartDiagnosisUseCase;
use App\Application\UseCase\Task\StartRepairUseCase;
use App\Domain\Exception\ConcurrencyException;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\NotFoundException;
use App\Domain\WorkTask\TaskItem;

/**
 * A fila da oficina e as ações do mecânico (docs/fase-4/CONTRATOS.md §4, no oficina-api).
 * Toda rota é de `admin`; a matriz fica em public/index.php.
 */
class TaskController
{
    public function __construct(
        private readonly ListTasksUseCase $list,
        private readonly GetTaskUseCase $get,
        private readonly StartDiagnosisUseCase $startDiagnosis,
        private readonly CompleteDiagnosisUseCase $completeDiagnosis,
        private readonly FailDiagnosisUseCase $failDiagnosis,
        private readonly StartRepairUseCase $startRepair,
        private readonly CompleteRepairUseCase $completeRepair,
        private readonly FailRepairUseCase $failRepair,
    ) {
    }

    /** @param array<string, mixed> $query */
    public function index(array $query): void
    {
        $this->respond(fn (): array => ['data' => $this->list->execute((string)($query['status'] ?? 'QUEUED_DIAGNOSIS'))]);
    }

    /** @param array<string, string> $params */
    public function show(array $params): void
    {
        $this->respond(fn (): array => $this->get->execute($params['id']));
    }

    /** @param array<string, string> $params */
    public function startDiagnosis(array $params): void
    {
        $this->respond(fn (): array => $this->startDiagnosis->execute($params['id'])->toArray());
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>  $body
     */
    public function completeDiagnosis(array $params, array $body): void
    {
        $this->respond(function () use ($params, $body): array {
            return $this->completeDiagnosis->execute($params['id'], $this->items($body))->toArray();
        });
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>  $body
     */
    public function failDiagnosis(array $params, array $body): void
    {
        $this->respond(fn (): array => $this->failDiagnosis->execute($params['id'], (string)($body['reason'] ?? ''))->toArray());
    }

    /** @param array<string, string> $params */
    public function startRepair(array $params): void
    {
        $this->respond(fn (): array => $this->startRepair->execute($params['id'])->toArray());
    }

    /** @param array<string, string> $params */
    public function completeRepair(array $params): void
    {
        $this->respond(fn (): array => $this->completeRepair->execute($params['id'])->toArray());
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>  $body
     */
    public function failRepair(array $params, array $body): void
    {
        $this->respond(fn (): array => $this->failRepair->execute($params['id'], (string)($body['reason'] ?? ''))->toArray());
    }

    /**
     * O corpo usa os nomes da API (`service_item_id` ou `part_id`); a mensagem para os outros
     * serviços usa `kind` e `id`.
     *
     * @param array<string, mixed> $body
     *
     * @return list<TaskItem>
     */
    private function items(array $body): array
    {
        $raw = $body['items'] ?? null;
        if (!is_array($raw) || $raw === []) {
            throw new DomainException("Informe 'items' com pelo menos um serviço ou peça.");
        }

        $items = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                throw new DomainException('Cada item deve ser um objeto.');
            }

            $serviceId = (string)($item['service_item_id'] ?? '');
            $partId    = (string)($item['part_id'] ?? '');
            if (($serviceId === '') === ($partId === '')) {
                throw new DomainException("Cada item leva 'service_item_id' ou 'part_id', e só um dos dois.");
            }

            $items[] = new TaskItem(
                $serviceId !== '' ? TaskItem::KIND_SERVICE : TaskItem::KIND_PART,
                $serviceId !== '' ? $serviceId : $partId,
                (int)($item['quantity'] ?? 1),
            );
        }

        return $items;
    }

    /** @param callable(): array<mixed> $action */
    private function respond(callable $action): void
    {
        try {
            $data = $action();
            http_response_code(200);
            echo json_encode($data, JSON_UNESCAPED_UNICODE);
        } catch (NotFoundException $e) {
            http_response_code(404);
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (ConcurrencyException) {
            http_response_code(409);
            echo json_encode(['error' => 'A tarefa foi alterada por outra operação. Tente de novo.'], JSON_UNESCAPED_UNICODE);
        } catch (DomainException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}
