<?php

namespace App\Service;

use App\Entity\Saison;
use App\Entity\SaisonEngagementDivision;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SeasonJsonStore
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string,mixed> $extra */
    public function save(Saison $season, array $extra = []): string
    {
        $directory = $this->projectDir . '/data/saisons';
        $path = $directory . '/' . $season->getId() . '.json';
        $existing = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];

        $courses = [];
        foreach ($season->getCourses() as $course) {
            $circuit = $course->getCircuit();
            $results = [];
            foreach ($course->getResultats() as $result) {
                $entry = $result->getEngagement();
                $pilot = $entry?->getPilote();
                $team = $entry?->getEcurie();
                $snapshot = $entry ? $this->entityManager->getRepository(SaisonEngagementDivision::class)->findOneBy(['engagement' => $entry]) : null;
                $results[] = [
                    'driver' => $pilot ? trim($pilot->getPrenom() . ' ' . $pilot->getNom()) : null,
                    'team' => $team?->getNom(),
                    'division' => ($snapshot?->getDivision() ?? $team?->getDivision())?->getNom(),
                    'position' => $result->getPosition(),
                    'points' => $result->getPoints(),
                    'dnf' => $result->isDnf(),
                    'laps' => $result->getNombreTours(),
                    'time_seconds' => $result->getTempsCourse(),
                    'fastest_lap' => $result->isTourLePlusRapide(),
                    'retirement_reason' => $result->getCauseAbandon(),
                ];
            }

            $courses[] = [
                'id' => $course->getId(),
                'number' => $course->getNumero(),
                'name' => $course->getNom(),
                'circuit' => $circuit?->getVille() ?: $circuit?->getNom(),
                'country' => $circuit?->getPays(),
                'date' => $course->getDate()?->format('Y-m-d'),
                'laps' => $course->getNombreTours(),
                'status' => $course->getStatut(),
                'results' => $results,
            ];
        }

        $entries = [];
        foreach ($season->getEngagements() as $entry) {
            $pilot = $entry->getPilote();
            $team = $entry->getEcurie();
            $snapshot = $this->entityManager->getRepository(SaisonEngagementDivision::class)->findOneBy(['engagement' => $entry]);
            $entries[] = [
                'driver' => $pilot ? trim($pilot->getPrenom() . ' ' . $pilot->getNom()) : null,
                'team' => $team?->getNom(),
                'division' => ($snapshot?->getDivision() ?? $team?->getDivision())?->getNom(),
                'number' => $entry->getNumero(),
                'status' => $entry->getStatut(),
            ];
        }

        $document = array_merge([
            'format_version' => 1,
            'id' => $season->getId(),
            'name' => $season->getNom(),
            'first_year' => $season->getAnnee(),
            'status' => $season->getStatut(),
            'start_date' => $season->getDateDebut()?->format('Y-m-d'),
            'end_date' => $season->getDateFin()?->format('Y-m-d'),
            'created_at' => is_array($existing) && isset($existing['created_at'])
                ? $existing['created_at']
                : (new \DateTimeImmutable())->format(DATE_ATOM),
            'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'entries' => $entries,
            'courses' => $courses,
        ], $extra);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le dossier data/saisons.');
        }

        $temporary = tempnam($directory, 'saison-');
        if ($temporary === false) {
            throw new RuntimeException('Impossible de préparer le fichier JSON de saison.');
        }

        try {
            $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json) === false || !rename($temporary, $path)) {
                throw new RuntimeException('Impossible d’enregistrer le JSON de saison.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $path;
    }
}