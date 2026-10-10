<?php
namespace App\Entity;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
class Division {
 #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id=null;
 #[ORM\Column(length:100, unique:true)] private string $nom='';
 #[ORM\Column] private int $niveau=1;
 #[ORM\Column(type:'text', nullable:true)] private ?string $description=null;
 #[ORM\OneToMany(mappedBy:'division', targetEntity:Ecurie::class)] private Collection $ecuries;
 public function __construct(){ $this->ecuries=new ArrayCollection(); }
 public function getId(): ?int{return $this->id;} public function getNom():string{return $this->nom;} public function setNom(string $v):self{$this->nom=$v;return $this;} public function getNiveau():int{return $this->niveau;} public function setNiveau(int $v):self{$this->niveau=$v;return $this;} public function getDescription():?string{return $this->description;} public function setDescription(?string $v):self{$this->description=$v;return $this;} public function getEcuries():Collection{return $this->ecuries;}
}
