<?php

namespace App\Service;

use App\Entity\Division;
use App\Entity\Engagement;
use App\Entity\Saison;
use App\Entity\SaisonEngagementDivision;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final class SeasonPromotionService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return list<array{division:string,level:int,teams:list<array{rank:int,name:string,points:float,wins:int,podiums:int}>}> */
    public function standings(Saison $season): array
    {
        $groups = $this->buildStandings($season, false);
        $output = [];
        foreach ($groups as $teams) {
            if ($teams === []) {
                continue;
            }
            $division = $teams[0]['division'];
            $rows = [];
            foreach (array_values($teams) as $index => $team) {
                $rows[] = [
                    'rank' => $index + 1,
                    'name' => $team['team']->getNom(),
                    'points' => $team['points'],
                    'wins' => $team['wins'],
                    'podiums' => $team['podiums'],
                ];
            }
            $output[] = ['division' => $division->getNom(), 'level' => $division->getNiveau(), 'teams' => $rows];
        }

        usort($output, static fn (array $left, array $right): int => $left['level'] <=> $right['level']);

        return $output;
    }

    /** @return array{promoted:list<array{team:string,from:string,to:string}>,relegated:list<array{team:string,from:string,to:string}>} */
    public function close(Saison $season): array
    {
        if ($season->getStatut() === 'terminee') {
            throw new RuntimeException('Cette saison est déjà terminée.');
        }

        $groups = $this->buildStandings($season, true);
        $divisions = $this->entityManager->getRepository(Division::class)->findBy([], ['niveau' => 'ASC']);
        $divisionByLevel = [];
        foreach ($divisions as $division) {
            $divisionByLevel[$division->getNiveau()] = $division;
        }

        foreach ($groups as $teams) {
            if ($teams === []) {
                continue;
            }
            if (count($teams) < 4) {
                throw new RuntimeException(sprintf(
                    'La division %s doit avoir au moins quatre écuries pour appliquer les deux montées/descentes.',
                    $teams[0]['division']->getNom(),
                ));
            }
        }

        $this->saveDivisionSnapshots($season);

        $promoted = [];
        $relegated = [];
        foreach ($groups as $teams) {
            if ($teams === []) {
                continue;
            }
            $division = $teams[0]['division'];
            $level = $division->getNiveau();

            if ($level > 1 && isset($divisionByLevel[$level - 1])) {
                foreach (array_slice($teams, 0, 2) as $standing) {
                    $target = $divisionByLevel[$level - 1];
                    $standing['team']->setDivision($target);
                    $promoted[] = ['team' => $standing['team']->getNom(), 'from' => $division->getNom(), 'to' => $target->getNom()];
                }
            }

            if (isset($divisionByLevel[$level + 1])) {
                foreach (array_slice($teams, -2) as $standing) {
                    $target = $divisionByLevel[$level + 1];
                    $standing['team']->setDivision($target);
                    $relegated[] = ['team' => $standing['team']->getNom(), 'from' => $division->getNom(), 'to' => $target->getNom()];
                }
            }
        }

        $season->setStatut('terminee');
        $this->entityManager->flush();

        return ['promoted' => $promoted, 'relegated' => $relegated];
    }

    /** @return array<int,list<array{team:object,division:Division,points:float,wins:int,podiums:int,results:int,entries:int}>> */
    private function buildStandings(Saison $season, bool $requireComplete): array
    {
        $races = $season->getCourses()->toArray();
        if ($races === []) {
            throw new RuntimeException('La saison ne contient aucune course.');
        }

        $standings = [];
        foreach ($this->entityManager->getRepository(Engagement::class)->findBy(['saison' => $season]) as $entry) {
            if ($entry->getStatut() !== 'actif') {
                continue;
            }
            $team = $entry->getEcurie();
            $snapshot = $this->entityManager->getRepository(SaisonEngagementDivision::class)->findOneBy(['engagement' => $entry]);
            $division = $snapshot?->getDivision() ?? $team?->getDivision();
            if ($team === null || $division === null) {
                continue;
            }

            $teamId = $team->getId();
            $divisionId = $division->getId();
            $standings[$divisionId][$teamId] ??= [
                'team' => $team,
                'division' => $division,
                'points' => 0.0,
                'wins' => 0,
                'podiums' => 0,
                'results' => 0,
                'entries' => 0,
            ];
            ++$standings[$divisionId][$teamId]['entries'];

            foreach ($entry->getResultats() as $result) {
                if ($result->getCourse()?->getSaison()?->getId() !== $season->getId()) {
                    continue;
                }
                $standings[$divisionId][$teamId]['points'] += $result->getPoints();
                ++$standings[$divisionId][$teamId]['results'];
                if ($result->getPosition() === 1) {
                    ++$standings[$divisionId][$teamId]['wins'];
                }
                if ($result->getPosition() !== null && $result->getPosition() <= 3) {
                    ++$standings[$divisionId][$teamId]['podiums'];
                }
            }
        }

        if ($standings === []) {
            if ($requireComplete) {
                throw new RuntimeException('Aucune écurie engagée ne permet d’établir le classement final.');
            }

            return [];
        }

        foreach ($standings as &$teams) {
            foreach ($teams as $standing) {
                $expectedResults = $standing['entries'] * count($races);
                if ($requireComplete && $standing['results'] < $expectedResults) {
                    throw new RuntimeException(sprintf(
                        'La saison est incomplète : %s a %d résultats sur %d attendus.',
                        $standing['team']->getNom(),
                        $standing['results'],
                        $expectedResults,
                    ));
                }
            }

            uasort($teams, static fn (array $left, array $right): int =>
                [$right['points'], $right['wins'], $right['podiums'], $left['team']->getNom()]
                <=> [$left['points'], $left['wins'], $left['podiums'], $right['team']->getNom()]
            );
        }
        unset($teams);

        return $standings;
    }

    private function saveDivisionSnapshots(Saison $season): void
    {
        $repository = $this->entityManager->getRepository(SaisonEngagementDivision::class);
        foreach ($this->entityManager->getRepository(Engagement::class)->findBy(['saison' => $season]) as $entry) {
            if ($entry->getStatut() !== 'actif' || $repository->findOneBy(['engagement' => $entry]) !== null) {
                continue;
            }
            $division = $entry->getEcurie()?->getDivision();
            if ($division === null) {
                continue;
            }
            $this->entityManager->persist((new SaisonEngagementDivision())
                ->setEngagement($entry)
                ->setDivision($division));
        }
    }
}