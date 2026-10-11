<?php

namespace App\Service;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SeasonJsonStore
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    private function dataDir(): string
    {
        return $this->projectDir . '/data';
    }

    private function read(string $path): array
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];

        return is_array($data) ? $data : [];
    }

    private function write(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Impossible de créer le dossier ' . $dir);
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Impossible d’écrire ' . basename($path));
        }
    }

    /** @return list<array{id:int,nom:string,niveau:int}> */
    public function divisions(): array
    {
        $divisions = $this->read($this->dataDir() . '/divisions.json');
        usort($divisions, static fn (array $a, array $b): int => $a['niveau'] <=> $b['niveau']);

        return array_values($divisions);
    }

    public function saveDivisions(array $divisions): void
    {
        $this->write($this->dataDir() . '/divisions.json', array_values($divisions));
    }

    /** Écuries telles qu'écrites dans ecuries.json */
    public function rawTeams(): array
    {
        return $this->read($this->dataDir() . '/ecuries.json');
    }

    public function saveRawTeams(array $teams): void
    {
        $this->write($this->dataDir() . '/ecuries.json', array_values($teams));
    }

    /** @return array<string,array{id:string,nom:string,division:string,active:bool}> indexé par id */
    public function teams(): array
    {
        $out = [];
        foreach ($this->rawTeams() as $team) {
            if (!isset($team['id'])) {
                continue;
            }
            $out[$team['id']] = [
                'id' => $team['id'],
                'nom' => (string) ($team['name'] ?? $team['id']),
                'division' => (string) ($team['division'] ?? ''),
                'active' => (bool) ($team['active'] ?? true),
            ];
        }

        return $out;
    }

    public function seasonPath(int $id): string
    {
        return $this->dataDir() . '/saisons/' . $id . '.json';
    }

    /** @return list<array<string,mixed>> */
    public function seasons(): array
    {
        $seasons = [];
        foreach (glob($this->dataDir() . '/saisons/*.json') ?: [] as $file) {
            $season = $this->read($file);
            if (isset($season['id'], $season['nom'])) {
                $season['courses'] ??= [];
                $season['engagements'] ??= [];
                $seasons[] = $season;
            }
        }
        usort($seasons, static fn (array $a, array $b): int =>
            [$b['annee'] ?? 0, $a['nom']] <=> [$a['annee'] ?? 0, $b['nom']]);

        return $seasons;
    }

    public function season(int $id): ?array
    {
        $season = $this->read($this->seasonPath($id));
        if (!isset($season['id'])) {
            return null;
        }
        $season['courses'] ??= [];
        $season['engagements'] ??= [];

        return $season;
    }

    public function saveSeason(array $season): string
    {
        $season['updated_at'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $season['created_at'] ??= $season['updated_at'];
        $path = $this->seasonPath((int) $season['id']);
        $this->write($path, $season);

        return $path;
    }

    public function nextSeasonId(): int
    {
        $max = 0;
        foreach ($this->seasons() as $season) {
            $max = max($max, (int) $season['id']);
        }

        return $max + 1;
    }
}
