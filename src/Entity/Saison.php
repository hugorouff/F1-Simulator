<?php

namespace App\Entity;

use App\Entity\Course;
use App\Entity\Engagement;
use App\Entity\Simulation;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Saison
{
	#[ORM\Id, ORM\GeneratedValue, ORM\Column]
	private ?int $id = null;

	#[ORM\Column(length: 100)]
	private string $nom = '';

	#[ORM\Column]
	private int $annee = 0;

	#[ORM\Column(length: 30)]
	private string $statut = 'brouillon';

	#[ORM\Column(type: 'date', nullable: true)]
	private ?\DateTimeInterface $dateDebut = null;

	#[ORM\Column(type: 'date', nullable: true)]
	private ?\DateTimeInterface $dateFin = null;

	#[ORM\ManyToOne]
	#[ORM\JoinColumn(nullable: true)]
	private ?Division $division = null;

	#[ORM\OneToMany(mappedBy: 'saison', targetEntity: Engagement::class)]
	private Collection $engagements;

	#[ORM\OneToMany(mappedBy: 'saison', targetEntity: Course::class)]
	#[ORM\OrderBy(['numero' => 'ASC'])]
	private Collection $courses;

	#[ORM\OneToMany(mappedBy: 'saison', targetEntity: Simulation::class)]
	private Collection $simulations;

	public function __construct()
	{
		$this->engagements = new ArrayCollection();
		$this->courses = new ArrayCollection();
		$this->simulations = new ArrayCollection();
	}

	public function getId(): ?int { return $this->id; }
	public function getNom(): string { return $this->nom; }
	public function setNom(string $value): self { $this->nom = $value; return $this; }
	public function getAnnee(): int { return $this->annee; }
	public function setAnnee(int $value): self { $this->annee = $value; return $this; }
	public function getStatut(): string { return $this->statut; }
	public function setStatut(string $value): self { $this->statut = $value; return $this; }
	public function getDateDebut(): ?\DateTimeInterface { return $this->dateDebut; }
	public function setDateDebut(?\DateTimeInterface $value): self { $this->dateDebut = $value; return $this; }
	public function getDateFin(): ?\DateTimeInterface { return $this->dateFin; }
	public function setDateFin(?\DateTimeInterface $value): self { $this->dateFin = $value; return $this; }
	public function getDivision(): ?Division { return $this->division; }
	public function setDivision(?Division $division): self { $this->division = $division; return $this; }
	public function getEngagements(): Collection { return $this->engagements; }
	public function getCourses(): Collection { return $this->courses; }
	public function getSimulations(): Collection { return $this->simulations; }
}
