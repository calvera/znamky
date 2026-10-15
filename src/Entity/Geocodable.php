<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * An entity that `app:stamps:geocode` can place on the map.
 */
interface Geocodable
{
    public function getId(): ?int;

    public function setLatitude(?float $latitude): static;

    public function setLongitude(?float $longitude): static;
}
