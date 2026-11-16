<?php

declare(strict_types=1);

namespace App\Domain\Exception;

class InvalidTaskTransitionException extends DomainException
{
    public static function from(string $status, string $action): self
    {
        return new self("A tarefa está em {$status} e não aceita a ação '{$action}'.");
    }
}
