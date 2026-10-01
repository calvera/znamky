<?php

declare(strict_types=1);

namespace App\Enum;

enum StampType: string
{
    case Regular = 'regular';
    case Annual = 'annual';

    public static function fromCsv(string $typ): self
    {
        return match (trim($typ)) {
            'Turistická známka' => self::Regular,
            'Výroční turistická známka' => self::Annual,
            default => throw new \InvalidArgumentException(sprintf('Unknown stamp type: "%s".', $typ)),
        };
    }
}
