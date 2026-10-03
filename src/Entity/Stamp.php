<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Enum\StampCountry;
use App\Enum\StampType;
use App\Repository\StampRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: StampRepository::class)]
#[ORM\Table(name: 'stamps')]
#[ORM\UniqueConstraint(name: 'UNIQ_STAMP_COUNTRY_TYPE_NUMBER', fields: ['country', 'type', 'number'])]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
    ],
    normalizationContext: ['groups' => ['stamp:read']],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'partial',
    'country' => 'exact',
    'type' => 'exact',
    'number' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['number'])]
class Stamp
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['stamp:read'])]
    private ?int $id = null;

    #[ORM\Column]
    #[Assert\Positive]
    #[Groups(['stamp:read'])]
    private int $number = 0;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['stamp:read'])]
    private string $name = '';

    #[ORM\Column(length: 2, enumType: StampCountry::class)]
    #[Groups(['stamp:read'])]
    private StampCountry $country = StampCountry::Cz;

    #[ORM\Column(length: 16, enumType: StampType::class)]
    #[Groups(['stamp:read'])]
    private StampType $type = StampType::Regular;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['stamp:read'])]
    private ?string $district = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['stamp:read'])]
    private ?string $region = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['stamp:read'])]
    private ?float $latitude = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['stamp:read'])]
    private ?float $longitude = null;

    /**
     * @var Collection<int, StampTag>
     */
    #[ORM\ManyToMany(targetEntity: StampTag::class)]
    #[ORM\JoinTable(name: 'stamp_stamp_tag')]
    #[Groups(['stamp:read'])]
    private Collection $tags;

    /**
     * @var Collection<int, StampPlace>
     */
    #[ORM\ManyToMany(targetEntity: StampPlace::class)]
    #[ORM\JoinTable(name: 'stamp_stamp_place')]
    #[Groups(['stamp:read'])]
    private Collection $places;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
        $this->places = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function setNumber(int $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCountry(): StampCountry
    {
        return $this->country;
    }

    public function setCountry(StampCountry $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getType(): StampType
    {
        return $this->type;
    }

    public function setType(StampType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getDistrict(): ?string
    {
        return $this->district;
    }

    public function setDistrict(?string $district): static
    {
        $this->district = $district;

        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): static
    {
        $this->region = $region;

        return $this;
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

    /**
     * @return Collection<int, StampTag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(StampTag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(StampTag $tag): static
    {
        $this->tags->removeElement($tag);

        return $this;
    }

    public function clearTags(): static
    {
        $this->tags->clear();

        return $this;
    }

    /**
     * @return Collection<int, StampPlace>
     */
    public function getPlaces(): Collection
    {
        return $this->places;
    }

    public function addPlace(StampPlace $place): static
    {
        if (!$this->places->contains($place)) {
            $this->places->add($place);
        }

        return $this;
    }

    public function removePlace(StampPlace $place): static
    {
        $this->places->removeElement($place);

        return $this;
    }

    public function clearPlaces(): static
    {
        $this->places->clear();

        return $this;
    }
}
