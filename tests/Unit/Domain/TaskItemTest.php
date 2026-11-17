<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Exception\DomainException;
use App\Domain\WorkTask\TaskItem;
use App\Domain\WorkTask\TimelineEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TaskItemTest extends TestCase
{
    public function testIdaEVolta(): void
    {
        $item = TaskItem::fromArray(['kind' => 'part', 'id' => 'p1', 'quantity' => 3]);

        $this->assertSame(['kind' => 'part', 'id' => 'p1', 'quantity' => 3], $item->toArray());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidos(): array
    {
        return [
            'tipo desconhecido' => [['kind' => 'outro', 'id' => 'x', 'quantity' => 1]],
            'sem id'            => [['kind' => 'part', 'id' => '', 'quantity' => 1]],
            'quantidade zero'   => [['kind' => 'service', 'id' => 'x', 'quantity' => 0]],
            'vazio'             => [[]],
        ];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidos')]
    public function testItemInvalidoERecusado(array $data): void
    {
        $this->expectException(DomainException::class);

        TaskItem::fromArray($data);
    }

    public function testLinhaDoTempoEmUtcComMilissegundos(): void
    {
        $entry = new TimelineEntry('repair.started', new \DateTimeImmutable('2026-11-15T10:00:00.250Z'), 'box 2');

        $this->assertSame(
            ['type' => 'repair.started', 'occurred_at' => '2026-11-15T10:00:00.250Z', 'detail' => 'box 2'],
            $entry->toArray(),
        );
    }
}
