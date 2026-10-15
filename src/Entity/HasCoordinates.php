<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Geocoded latitude/longitude stored as DECIMAL(10,7), exposed as floats.
 */
trait HasCoordinates
{
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    private ?string $longitude = null;

    #[Groups(['stamp:read', 'stamp_place:read'])]
    public function getLatitude(): ?float
    {
        return null === $this->latitude ? null : (float) $this->latitude;
    }

    public function setLatitude(?float $latitude): static
    {
        $this->latitude = null === $latitude ? null : \sprintf('%.7F', $latitude);

        return $this;
    }

    #[Groups(['stamp:read', 'stamp_place:read'])]
    public function getLongitude(): ?float
    {
        return null === $this->longitude ? null : (float) $this->longitude;
    }

    public function setLongitude(?float $longitude): static
    {
        $this->longitude = null === $longitude ? null : \sprintf('%.7F', $longitude);

        return $this;
    }
}
