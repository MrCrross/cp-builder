<?php

namespace App\Modules\Statistics\Support;

/**
 * Маркерная область авторизации дашборда статистики: Policy привязан к
 * этому классу, а не к Eloquent-модели (сравни AuditEntry для аудита).
 */
final class StatisticsArea {}
