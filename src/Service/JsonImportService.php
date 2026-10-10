<?php

namespace App\Service;

use App\Entity\Division;
use App\Entity\Ecurie;
use App\Entity\Pilote;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class JsonImportService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PythonRunner $python,
    ) {
    }

    /** @return array{created:int,updated:int} */
    public function importDrivers(UploadedFile $file): array
    {
        $records = $this->records($file, ['drivers', 'pilotes']);
        $normalized = [];
        $normalizedRoster = [];
        $seen = [];

        foreach ($records as $index => $record) {
            $name = trim((string) ($record['name'] ?? $record['nom_complet'] ?? ''));
            if ($name === '') {
                $firstName = trim((string) ($record['prenom'] ?? $record['first_name'] ?? ''));
                $lastName = trim((string) ($record['nom'] ?? $record['last_name'] ?? ''));
                $name = trim($firstName . ' ' . $lastName);
            }
            if ($name === '' || mb_strlen($name) > 200) {
                throw new RuntimeException(sprintf('Le pilote à la ligne %d doit avoir un nom valide.', $index + 1));
            }

            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                throw new RuntimeException(sprintf('Le pilote « %s » apparaît plusieurs fois dans le fichier.', $name));
            }
            $seen[$key] = true;
            $parts = preg_split('/\s+/u', $name, 2) ?: [$name];
            $firstName = trim((string) ($record['prenom'] ?? $record['first_name'] ?? $parts[0]));
            $lastName = trim((string) ($record['nom'] ?? $record['last_name'] ?? ($parts[1] ?? '')));

            $pilot = $this->entityManager->getRepository(Pilote::class)->findOneBy([
                'prenom' => $firstName,
                'nom' => $lastName,
            ]);
            $isNew = $pilot === null;
            $pilot ??= new Pilote();
            $pilot->setPrenom($firstName)->setNom($lastName);

            $nationality = $record['nationality'] ?? $record['nationalite'] ?? null;
            if (is_string($nationality)) {
                $pilot->setNationalite($nationality !== '' ? $nationality : null);
            }
            $birthDate = $record['date_of_birth'] ?? $record['date_naissance'] ?? null;
            if (is_string($birthDate) && $birthDate !== '') {
                try {
                    $pilot->setDateNaissance(new \DateTimeImmutable($birthDate));
                } catch (\Throwable) {
                    throw new RuntimeException(sprintf('Date de naissance invalide pour %s.', $name));
                }
            }

            foreach ([
                'career_wins' => ['career_wins', 'nombre_victoire'],
                'career_races' => ['career_races', 'nombre_course'],
                'career_podiums' => ['career_podiums', 'nombre_podium'],
                'season_wins' => ['season_wins', 'nombre_trophee'],
                'championships' => ['championships', 'nombre_championnat_f1'],
            ] as $keyName => $aliases) {
                $value = $this->field($record, $aliases);
                if ($value === null) {
                    continue;
                }
                if (!is_numeric($value) || (int) $value < 0) {
                    throw new RuntimeException(sprintf('Valeur invalide pour %s (%s).', $name, $keyName));
                }
                match ($keyName) {
                    'career_wins' => $pilot->setNombreVictoire((int) $value),
                    'career_races' => $pilot->setNombreCourse((int) $value),
                    'career_podiums' => $pilot->setNombrePodium((int) $value),
                    'season_wins' => $pilot->setNombreTrophee((int) $value),
                    'championships' => $pilot->setNombreChampionnatF1((int) $value),
                };
            }

            if (array_key_exists('active', $record) || array_key_exists('actif', $record)) {
                $pilot->setActif(filter_var($record['active'] ?? $record['actif'], FILTER_VALIDATE_BOOL));
            }
            $this->entityManager->persist($pilot);
            $countKey = $isNew ? 'created' : 'updated';
            $normalized[$countKey] = ($normalized[$countKey] ?? 0) + 1;

            $normalizedRoster[] = array_merge($record, ['name' => $name]);
        }

        $this->entityManager->flush();
        $this->writeRoster($normalizedRoster);

        return ['created' => $normalized['created'] ?? 0, 'updated' => $normalized['updated'] ?? 0];
    }

    /** @return array{created:int,updated:int} */
    public function importTeams(UploadedFile $file, ?Division $defaultDivision): array
    {
        $records = $this->records($file, ['teams', 'ecuries']);
        $seen = [];
        $validated = [];

        foreach ($records as $index => $record) {
            $name = trim((string) ($record['name'] ?? $record['nom'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150) {
                throw new RuntimeException(sprintf('L’écurie à la ligne %d doit avoir un nom valide.', $index + 1));
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                throw new RuntimeException(sprintf('L’écurie « %s » apparaît plusieurs fois dans le fichier.', $name));
            }
            $seen[$key] = true;

            $divisionValue = $record['division_id'] ?? $record['division'] ?? null;
            $division = is_numeric($divisionValue)
                ? $this->entityManager->getRepository(Division::class)->find((int) $divisionValue)
                : (is_string($divisionValue) && trim($divisionValue) !== ''
                    ? $this->entityManager->getRepository(Division::class)->findOneBy(['nom' => trim($divisionValue)])
                    : $defaultDivision);
            if ($division === null) {
                throw new RuntimeException(sprintf('Division inconnue pour l’écurie « %s »; crée la division ou choisis une division par défaut.', $name));
            }

            $existing = $this->entityManager->getRepository(Ecurie::class)->findOneBy(['nom' => $name]);
            if ($existing !== null && $existing->getDivision()?->getId() !== $division->getId()) {
                throw new RuntimeException(sprintf('« %s » est déjà dans %s; les changements de division se font à la clôture.', $name, $existing->getDivision()?->getNom()));
            }
            $validated[] = [$record, $name, $division, $existing];
        }

        $created = 0;
        $updated = 0;
        foreach ($validated as [$record, $name, $division, $team]) {
            $isNew = $team === null;
            $team ??= (new Ecurie())->setNom($name)->setDivision($division);
            $condition = $record['car_condition'] ?? $record['etat_voiture'] ?? $team->getEtatVoiture();
            $titles = $record['constructor_titles'] ?? $record['nombre_championnat_constructeur'] ?? $team->getNombreChampionnatConstructeur();
            if (!is_numeric($condition) || (float) $condition < 0 || (float) $condition > 100 || !is_numeric($titles) || (int) $titles < 0) {
                throw new RuntimeException(sprintf('État de voiture ou titres invalides pour %s.', $name));
            }
            $team->setEtatVoiture((float) $condition);
            $team->setNombreChampionnatConstructeur((int) $titles);
            if (array_key_exists('active', $record) || array_key_exists('actif', $record)) {
                $team->setActive(filter_var($record['active'] ?? $record['actif'], FILTER_VALIDATE_BOOL));
            }
            $this->entityManager->persist($team);
            $isNew ? ++$created : ++$updated;
        }

        $this->entityManager->flush();
        $this->writeJsonFile('/data/ecuries.json', $records);

        return ['created' => $created, 'updated' => $updated];
    }

    /** @param list<string> $wrapperKeys
     * @return list<array<string,mixed>>
     */
    private function records(UploadedFile $file, array $wrapperKeys): array
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'json') {
            throw new RuntimeException('Choisis un fichier portant l’extension .json.');
        }

        try {
            $data = json_decode((string) file_get_contents($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Le fichier JSON est invalide : ' . $error->getMessage());
        }

        if (is_array($data) && !array_is_list($data)) {
            foreach ($wrapperKeys as $key) {
                if (isset($data[$key]) && is_array($data[$key])) {
                    $data = $data[$key];
                    break;
                }
            }
        }
        if (!is_array($data) || !array_is_list($data) || $data === []) {
            throw new RuntimeException('Le JSON doit contenir une liste non vide d’objets.');
        }
        foreach ($data as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Chaque entrée du JSON doit être un objet.');
            }
        }

        return $data;
    }

    /** @param list<string> $keys */
    private function field(array $record, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $record)) {
                return $record[$key];
            }
        }

        return null;
    }

    /** @param list<array<string,mixed>> $roster */
    private function writeRoster(array $roster): void
    {
        $path = $this->python->projectDir() . '/data/pilotes.json';
        if (is_file($path)) {
            copy($path, $this->python->projectDir() . '/data/pilotes.bak.json');
        }
        $this->writeJsonFile('/data/pilotes.json', $roster);
    }

    private function writeJsonFile(string $relativePath, mixed $data): void
    {
        $path = $this->python->projectDir() . $relativePath;
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le dossier de données JSON.');
        }
        $temporary = tempnam($directory, 'import-');
        if ($temporary === false) {
            throw new RuntimeException('Impossible de préparer le fichier JSON temporaire.');
        }
        try {
            file_put_contents($temporary, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Impossible de remplacer le fichier JSON importé.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}