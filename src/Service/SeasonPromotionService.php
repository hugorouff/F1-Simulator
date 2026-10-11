<?php

namespace App\Service;

use RuntimeException;

final class SeasonPromotionService
{
    public function __construct(private readonly SeasonJsonStore $store)
    {
    }

    /** @return list<array{division:string,level:int,teams:list<array{rank:int,name:string,points:float,wins:int,podiums:int}>}> */
    public function standings(array $season): array
    {
        $output = [];
        foreach ($this->groups($season, false) as $group) {
            $rows = [];
            foreach ($group['teams'] as $i => $team) {
                $rows[] = [
                    'rank' => $i + 1,
                    'name' => $team['name'],
                    'points' => $team['points'],
                    'wins' => $team['wins'],
                    'podiums' => $team['podiums'],
                ];
            }
            $output[] = ['division' => $group['division']['nom'], 'level' => $group['division']['niveau'], 'teams' => $rows];
        }
        usort($output, static fn (array $a, array $b): int => $a['level'] <=> $b['level']);

        return $output;
    }

    /**
     * @return array{promoted:list<array>,relegated:list<array>}
     */
    public function close(array &$season): array
    {
        if (($season['statut'] ?? '') === 'terminee') {
            throw new RuntimeException('Cette saison est déjà terminée.');
        }

        $groups = $this->groups($season, true);
        foreach ($groups as $group) {
            if (count($group['teams']) < 4) {
                throw new RuntimeException(sprintf(
                    'La division %s doit avoir au moins quatre écuries pour appliquer les deux montées/descentes.',
                    $group['division']['nom'],
                ));
            }
        }

        $byLevel = [];
        foreach ($this->store->divisions() as $division) {
            $byLevel[$division['niveau']] = $division;
        }

        $rawTeams = $this->store->rawTeams();
        $setDivision = static function (string $teamId, string $divisionName) use (&$rawTeams): void {
            foreach ($rawTeams as &$team) {
                if (($team['id'] ?? null) === $teamId) {
                    $team['division'] = $divisionName;
                }
            }
        };

        $promoted = [];
        $relegated = [];
        foreach ($groups as $group) {
            $division = $group['division'];
            $level = $division['niveau'];

            if ($level > 1 && isset($byLevel[$level - 1])) {
                $target = $byLevel[$level - 1];
                foreach (array_slice($group['teams'], 0, 2) as $team) {
                    $setDivision($team['id'], $target['nom']);
                    $promoted[] = ['team' => $team['name'], 'from' => $division['nom'], 'to' => $target['nom']];
                }
            }
            if (isset($byLevel[$level + 1])) {
                $target = $byLevel[$level + 1];
                foreach (array_slice($group['teams'], -2) as $team) {
                    $setDivision($team['id'], $target['nom']);
                    $relegated[] = ['team' => $team['name'], 'from' => $division['nom'], 'to' => $target['nom']];
                }
            }
        }

        $this->store->saveRawTeams($rawTeams);
        $season['statut'] = 'terminee';

        return ['promoted' => $promoted, 'relegated' => $relegated];
    }

    /**
     * @return list<array{division:array,teams:list<array>}>
     */
    private function groups(array $season, bool $requireComplete): array
    {
        $races = $season['courses'] ?? [];
        if ($races === []) {
            if ($requireComplete) {
                throw new RuntimeException('La saison ne contient aucune course.');
            }

            return [];
        }

        $divisions = [];
        foreach ($this->store->divisions() as $division) {
            $divisions[$division['id']] = $division;
        }
        $teams = $this->store->teams();

        $groups = [];
        foreach ($season['engagements'] ?? [] as $entry) {
            $teamId = $entry['ecurie_id'] ?? null;
            $division = $divisions[$entry['division_id'] ?? 0] ?? null;
            if ($teamId === null || $division === null || !isset($teams[$teamId])) {
                continue;
            }

            $row = ['id' => $teamId, 'name' => $teams[$teamId]['nom'], 'points' => 0.0, 'wins' => 0, 'podiums' => 0, 'results' => 0];
            foreach ($races as $race) {
                foreach ($race['resultats'] ?? [] as $result) {
                    if (($result['ecurie_id'] ?? null) !== $teamId) {
                        continue;
                    }
                    $row['points'] += (float) ($result['points'] ?? 0);
                    ++$row['results'];
                    $position = $result['position'] ?? null;
                    if ($position === 1) {
                        ++$row['wins'];
                    }
                    if ($position !== null && $position <= 3) {
                        ++$row['podiums'];
                    }
                }
            }

            $expected = count($entry['pilotes'] ?? []) * count($races);
            if ($requireComplete && $row['results'] < $expected) {
                throw new RuntimeException(sprintf(
                    'La saison est incomplète : %s a %d résultats sur %d attendus.',
                    $row['name'], $row['results'], $expected,
                ));
            }

            $groups[$division['id']]['division'] = $division;
            $groups[$division['id']]['teams'][] = $row;
        }

        if ($groups === [] && $requireComplete) {
            throw new RuntimeException('Aucune écurie engagée ne permet d’établir le classement final.');
        }

        foreach ($groups as &$group) {
            usort($group['teams'], static fn (array $a, array $b): int =>
                [$b['points'], $b['wins'], $b['podiums'], $a['name']]
                <=> [$a['points'], $a['wins'], $a['podiums'], $b['name']]);
        }
        unset($group);

        return array_values($groups);
    }
}
