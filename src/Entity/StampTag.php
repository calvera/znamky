<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\GraphQl\Query;
use ApiPlatform\Metadata\GraphQl\QueryCollection;
use App\Repository\StampTagRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: StampTagRepository::class)]
#[ORM\Table(name: 'stamp_tags')]
#[ORM\UniqueConstraint(name: 'UNIQ_STAMP_TAG_NAME', fields: ['name'])]
#[ORM\UniqueConstraint(name: 'UNIQ_STAMP_TAG_SLUG', fields: ['slug'])]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
    ],
    graphQlOperations: [
        new Query(security: 'is_granted("IS_AUTHENTICATED_FULLY")'),
        new QueryCollection(security: 'is_granted("IS_AUTHENTICATED_FULLY")'),
    ],
    normalizationContext: ['groups' => ['stamp_tag:read']],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'partial',
    'slug' => 'exact',
])]
class StampTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['stamp_tag:read', 'stamp:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['stamp_tag:read', 'stamp:read'])]
    private string $name = '';

    #[ORM\Column(length: 140)]
    #[Assert\NotBlank]
    #[Groups(['stamp_tag:read', 'stamp:read'])]
    private string $slug = '';

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

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }
}
