<?php

declare(strict_types=1);

namespace App\Domain\WorkTask;

/**
 * Estados da tarefa da oficina. O caminho feliz é a primeira linha; os três últimos são finais.
 *
 *   QUEUED_DIAGNOSIS → DIAGNOSING → DIAGNOSED → QUEUED_REPAIR → REPAIRING → REPAIRED
 *   DIAGNOSING → DIAGNOSIS_FAILED      REPAIRING → REPAIR_FAILED      (antes de REPAIRED) → CANCELLED
 */
enum TaskStatus: string
{
    case QueuedDiagnosis = 'QUEUED_DIAGNOSIS';
    case Diagnosing      = 'DIAGNOSING';
    case Diagnosed       = 'DIAGNOSED';
    case QueuedRepair    = 'QUEUED_REPAIR';
    case Repairing       = 'REPAIRING';
    case Repaired        = 'REPAIRED';
    case DiagnosisFailed = 'DIAGNOSIS_FAILED';
    case RepairFailed    = 'REPAIR_FAILED';
    case Cancelled       = 'CANCELLED';

    /** A tarefa terminou: nada mais muda nela. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Repaired, self::DiagnosisFailed, self::RepairFailed, self::Cancelled], true);
    }
}
