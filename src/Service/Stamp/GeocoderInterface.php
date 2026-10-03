<?php

declare(strict_types=1);

namespace App\Service\Stamp;

interface GeocoderInterface
{
    /**
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(string $address): ?array;
}
