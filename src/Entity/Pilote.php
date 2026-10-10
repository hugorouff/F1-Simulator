<?php
namespace App\Entity;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
class Pilote {
 #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id=null;
 #[ORM\Column(length:100)] private string $prenom=''; #[ORM\Column(length:100)] private string $nom='';
 #[ORM\Column(type:'date', nullable:true)] private ?\DateTimeInterface $dateNaissance=null; #[ORM\Column(length:100, nullable:true)] private ?string $nationalite=null;
 #[ORM\Column] private int $nombreVictoire=0; #[ORM\Column] private int $nombreCourse=0; #[ORM\Column] private int $nombrePodium=0; #[ORM\Column] private int $nombreTrophee=0; #[ORM\Column] private int $nombreChampionnatF1=0; #[ORM\Column] private bool $actif=true;
 #[ORM\OneToMany(mappedBy:'pilote', targetEntity:Engagement::class)] private Collection $engagements;
 public function __construct(){ $this->engagements=new ArrayCollection(); }
 public function getId():?int{return $this->id;} public function getPrenom():string{return $this->prenom;} public function setPrenom(string $v):self{$this->prenom=$v;return $this;} public function getNom():string{return $this->nom;} public function setNom(string $v):self{$this->nom=$v;return $this;} public function getDateNaissance():?\DateTimeInterface{return $this->dateNaissance;} public function setDateNaissance(?\DateTimeInterface $v):self{$this->dateNaissance=$v;return $this;} public function getNationalite():?string{return $this->nationalite;} public function setNationalite(?string $v):self{$this->nationalite=$v;return $this;} public function getNombreVictoire():int{return $this->nombreVictoire;} public function setNombreVictoire(int $v):self{$this->nombreVictoire=$v;return $this;} public function getNombreCourse():int{return $this->nombreCourse;} public function setNombreCourse(int $v):self{$this->nombreCourse=$v;return $this;} public function getNombrePodium():int{return $this->nombrePodium;} public function setNombrePodium(int $v):self{$this->nombrePodium=$v;return $this;} public function getNombreTrophee():int{return $this->nombreTrophee;} public function setNombreTrophee(int $v):self{$this->nombreTrophee=$v;return $this;} public function getNombreChampionnatF1():int{return $this->nombreChampionnatF1;} public function setNombreChampionnatF1(int $v):self{$this->nombreChampionnatF1=$v;return $this;} public function isActif():bool{return $this->actif;} public function setActif(bool $v):self{$this->actif=$v;return $this;} public function getEngagements():Collection{return $this->engagements;}
}
