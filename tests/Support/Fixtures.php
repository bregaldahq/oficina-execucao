<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Messaging\Message;
use App\Domain\WorkTask\TaskItem;
use App\Domain\WorkTask\WorkTask;

final class Fixtures
{
    public const OS   = 'os-1';
    public const SAGA = 'saga-1';

    public static function task(?\DateTimeImmutable $now = null): WorkTask
    {
        return WorkTask::open(
            self::OS,
            self::SAGA,
            'corr-1',
            'Maria Silva',
            ['license_plate' => 'ABC1D23', 'brand' => 'Fiat', 'model' => 'Uno'],
            'Barulho no freio',
            $now ?? new \DateTimeImmutable('2026-11-15T10:00:00Z'),
        );
    }

    /** @return list<TaskItem> */
    public static function items(): array
    {
        return [new TaskItem(TaskItem::KIND_SERVICE, 'svc-1', 1), new TaskItem(TaskItem::KIND_PART, 'part-9', 2)];
    }

    /** @param array<string, mixed> $payload */
    public static function command(string $type, array $payload = [], string $id = 'msg-1', string $osId = self::OS): Message
    {
        return new Message($id, $type, 'os', self::SAGA, $osId, '2026-11-15T10:00:00.000Z', 'corr-1', $payload);
    }

    /** @return array<string, mixed> */
    public static function diagnosisPayload(): array
    {
        return [
            'customer_name' => 'Maria Silva',
            'vehicle'       => ['license_plate' => 'ABC1D23', 'brand' => 'Fiat', 'model' => 'Uno'],
            'description'   => 'Barulho no freio',
        ];
    }
}
