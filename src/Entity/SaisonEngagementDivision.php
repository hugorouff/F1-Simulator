<?php

namespace App\Entity;

use App\Entity\Engagement;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'saison_engagement_division', uniqueConstraints: [
    new ORM\UniqueConstraint(name: 'UNIQ_CF7A5C4D30F6F97', columns: ['engagement_id']),
])]
class SaisonEngagementDivision
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Engagement $engagement = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Division $division = null;

    public function getId(): ?int { return $this->id; }
    public function getEngagement(): ?Engagement { return $this->engagement; }
    public function setEngagement(?Engagement $engagement): self { $this->engagement = $engagement; return $this; }
    public function getDivision(): ?Division { return $this->division; }
    public function setDivision(?Division $division): self { $this->division = $division; return $this; }
}