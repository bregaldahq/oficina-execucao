<?php

declare(strict_types=1);

namespace App\Domain\WorkTask;

use App\Domain\Exception\DomainException;

/** Um serviço ou uma peça levantados no diagnóstico. O preço não é assunto deste serviço. */
final class TaskItem
{
    public const KIND_SERVICE = 'service';
    public const KIND_PART    = 'part';

    public function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly int $quantity,
    ) {
        if (!in_array($kind, [self::KIND_SERVICE, self::KIND_PART], true)) {
            throw new DomainException("Tipo de item inválido: {$kind}.");
        }
        if ($id === '') {
            throw new DomainException('Item sem identificador.');
        }
        if ($quantity < 1) {
            throw new DomainException('A quantidade do item deve ser de pelo menos 1.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['kind'] ?? ''),
            (string)($data['id'] ?? ''),
            (int)($data['quantity'] ?? 0),
        );
    }

    /** @return array{kind: string, id: string, quantity: int} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'id' => $this->id, 'quantity' => $this->quantity];
    }
}
