<?php

namespace App\Modules\Imports\Domain;

/**
 * Жизненный цикл job'а импорта (план §13.1):
 * uploaded → validating → awaiting_confirmation → processing →
 * completed | completed_with_errors | failed | cancelled.
 */
enum ImportJobStatus: string
{
    case Uploaded = 'uploaded';
    case Validating = 'validating';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return match ($this) {
            self::Uploaded, self::Validating, self::Processing => true,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::CompletedWithErrors, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Загружен',
            self::Validating => 'Проверка',
            self::AwaitingConfirmation => 'Ожидает подтверждения',
            self::Processing => 'Применение',
            self::Completed => 'Завершён',
            self::CompletedWithErrors => 'Завершён с ошибками',
            self::Failed => 'Ошибка',
            self::Cancelled => 'Отменён',
        };
    }
}
