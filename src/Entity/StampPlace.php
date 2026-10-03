<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\StampPlaceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: StampPlaceRepository::class)]
#[ORM\Table(name: 'stamp_places')]
#[ORM\UniqueConstraint(name: 'UNIQ_STAMP_PLACE_CATALOG_KEY', fields: ['catalogKey'])]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
    ],
    normalizationContext: ['groups' => ['stamp_place:read']],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'partial',
])]
class StampPlace
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['stamp_place:read', 'stamp:read'])]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Groups(['stamp_place:read', 'stamp:read'])]
    private string $name = '';

    /**
     * Empty string when the CSV line has no web URL (keeps the catalog key stable).
     */
    #[ORM\Column(type: 'text')]
    #[Groups(['stamp_place:read', 'stamp:read'])]
    private string $url = '';

    /**
     * SHA-256 of name + NUL + url, used for shared-catalog uniqueness (avoids oversized btree indexes).
     */
    #[ORM\Column(length: 64)]
    #[ApiProperty(readable: false, writable: false)]
    private string $catalogKey = '';

    #[ORM\Column(nullable: true)]
    #[Groups(['stamp_place:read', 'stamp:read'])]
    private ?float $latitude = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['stamp_place:read', 'stamp:read'])]
    private ?float $longitude = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        $this->refreshCatalogKey();

        return $this;
    }

    public function getUrl(): ?string
    {
        return '' === $this->url ? null : $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url ?? '';
        $this->refreshCatalogKey();

        return $this;
    }

    public function getCatalogKey(): string
    {
        return $this->catalogKey;
    }

    public static function buildCatalogKey(string $name, ?string $url): string
    {
        return hash('sha256', $name."\0".($url ?? ''));
    }

    private function refreshCatalogKey(): void
    {
        $this->catalogKey = self::buildCatalogKey($this->name, '' === $this->url ? null : $this->url);
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }
}
