<?php

namespace App\Modules\Statistics\Domain;

/**
 * Измерения разбивок дашборда (план §13.5): тип устройства и ОС.
 */
enum DimensionType: string
{
    case DeviceType = 'device_type';
    case Os = 'os';
}
