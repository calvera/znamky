<?php

declare(strict_types=1);

namespace App\Enum;

enum StampCountry: string
{
    case Cz = 'CZ';
    case Sk = 'SK';

    public function label(): string
    {
        return match ($this) {
            self::Cz => 'Czech Republic',
            self::Sk => 'Slovakia',
        };
    }

    public static function fromCsv(string $zeme): self
    {
        return match (trim($zeme)) {
            'Česká Republika' => self::Cz,
            'Slovenská Republika' => self::Sk,
            default => throw new \InvalidArgumentException(sprintf('Unknown stamp country: "%s".', $zeme)),
        };
    }
}
