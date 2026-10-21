<?php

namespace App\Controller;

use App\Service\PythonRunner;
use App\Service\SeasonJsonStore;
use App\Entity\Course;
use App\Entity\Engagement;
use App\Entity\ResultatCourse;
use App\Entity\SaisonEngagementDivision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Throwable;

class ReplayController extends AbstractController
{
    private const EVENT_TYPES = ['safety_car', 'puncture', 'mechanical', 'collision', 'spin', 'penalty'];

    private const INT_FIELDS = [
        'season_wins', 'age', 'points', 'car_wear', 'career_wins',
        'career_races', 'dnf', 'career_podiums', 'championships',
    ];

    private const TEAM_COLORS = [
        'red-bull' => '#3671C6',
        'mercedes' => '#27F4D2',
        'ferrari' => '#E8002D',
        'mclaren' => '#FF8000',
        'aston-martin' => '#229971',
        'alpine' => '#FF87BC',
        'williams' => '#64C4FF',
        'rb' => '#6692FF',
        'stake-sauber' => '#52E252',
        'haas' => '#B6BABD',
        'cadillac-f1-team' => '#C9A227',
    ];

    private const FALLBACK_PALETTE = [
        '#E8002D', '#27F4D2', '#FF8000', '#3671C6', '#229971', '#FF87BC',
        '#64C4FF', '#52E252', '#B6BABD', '#C9A227', '#9B5DE5', '#F15BB5',
    ];

    public function __construct(private readonly PythonRunner $python)
    {
    }

    #[Route('/replay', name: 'app_replay', methods: ['GET'])]
    public function index(Request $request, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $circuits = $this->getLocalCircuits();
        $selectedCircuit = trim((string) $request->query->get('circuit', ''));
        if (!isset($circuits[$selectedCircuit]) && $circuits !== []) {
            $selectedCircuit = array_key_first($circuits);
        }

        $courseId = $request->query->getInt('course_id');
        $divisionId = $request->query->getInt('division_id');

        // Association des écuries aux pilotes pour l'affichage
        $teams = $this->teamsIndex();
        $drivers = $this->readDrivers();
        foreach ($drivers as &$driver) {
            $team = $this->teamOf($driver, $teams);
            $driver['display_team'] = $team['name'] ?? ($driver['team'] ?? $driver['ecurie'] ?? 'Indépendant');
        }
        unset($driver);

        return $this->render('replay/index.html.twig', [
            'circuits' => $circuits,
            'selected_circuit' => $selectedCircuit,
            'course_id' => $courseId > 0 ? $courseId : null,
            'division_id' => $divisionId > 0 ? $divisionId : null,
            'season_csrf_token' => $csrfTokenManager->getToken('record_season_results')->getValue(),
            'drivers' => $drivers,
        ]);
    }

    #[Route('/api/circuit/track', name: 'api_circuit_track', methods: ['GET'])]
    public function track(Request $request): JsonResponse
    {
        try {
            $circuitSlug = trim((string) $request->query->get('circuit', ''));
            $file = $this->realCircuit($circuitSlug);
            $circuit = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            return new JsonResponse($circuit);
        } catch (Throwable $error) {
            return new JsonResponse(['error' => $error->getMessage()], 404);
        }
    }

    #[Route('/api/replay/simulate', name: 'api_replay_simulate', methods: ['GET'])]
    public function simulate(Request $request, EntityManagerInterface $entityManager): Response
    {
        set_time_limit(360);
        $q = $request->query;

        $laps = max(1, min($q->getInt('laps', 15), 200));
        $weather = in_array($q->get('weather'), ['dry', 'rain', 'random'], true) ? $q->get('weather') : 'dry';
        $chaos = max(0.0, min((float) $q->get('chaos', 1), 4.0));
        $seed = $q->has('seed') && $q->get('seed') !== '' ? max(1, $q->getInt('seed')) : null;
        $demo = $q->getBoolean('demo');

        $config = [
            'weather' => $weather,
            'chaos' => $chaos,
            'safety_car' => $q->getBoolean('safety_car', true),
            'driver_modifiers' => (object) $this->sanitizeModifiers($q->get('mods')),
            'forced_events' => $this->sanitizeEvents($q->get('events'), $laps),
        ];

        $temporaryDriversFile = null;
        $enrichedRosterFile = null;
        try {
            $circuitSlug = trim((string) $q->get('circuit', ''));
            $circuitFile = $demo
                ? $this->demoCircuit()
                : $this->realCircuit($circuitSlug);

            $enrichedRosterFile = $this->writeEnrichedRoster();
            $driversFile = $enrichedRosterFile;

            if ($q->getInt('course_id') > 0 || $q->getInt('division_id') > 0) {
                if ($q->getInt('course_id') < 1 || $q->getInt('division_id') < 1) {
                    throw new \RuntimeException('La course et la division sont nécessaires pour simuler une manche de saison.');
                }
                $temporaryDriversFile = $this->seasonDriversFile(
                    $q->getInt('course_id'),
                    $q->getInt('division_id'),
                    $entityManager
                );
                $driversFile = $temporaryDriversFile;
            }

            $args = [
                '--drivers', $driversFile,
                '--circuit', $circuitFile,
                '--laps', (string) $laps,
                '--config', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ];
            if ($seed !== null) {
                array_push($args, '--seed', (string) $seed);
            }

            $json = $this->applyTeamColors($this->python->run('sim_engine.py', $args, 120));
        } catch (\Throwable $e) {
            return new JsonResponse([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 500);
        } finally {
            foreach ([$temporaryDriversFile, $enrichedRosterFile] as $tmp) {
                if ($tmp !== null && is_file($tmp)) {
                    unlink($tmp);
                }
            }
        }

        return new Response($json, 200, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    #[Route('/api/replay/commit', name: 'api_replay_commit', methods: ['POST'])]
    public function commit(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $updated = is_array($body) ? ($body['updated_drivers'] ?? null) : null;
        if (!is_array($updated)) {
            return new JsonResponse(['error' => 'Données invalides.'], 422);
        }

        $current = $this->readDrivers();
        $byName = [];
        foreach ($updated as $u) {
            if (is_array($u) && isset($u['name']) && is_string($u['name'])) {
                $byName[$u['name']] = $u;
            }
        }

        $changed = 0;
        foreach ($current as &$driver) {
            $u = $byName[$driver['name']] ?? null;
            if ($u === null) {
                continue;
            }
            foreach (self::INT_FIELDS as $field) {
                if (isset($u[$field]) && is_numeric($u[$field])) {
                    $driver[$field] = max(0, min((int) $u[$field], 100000));
                }
            }
            ++$changed;
        }
        unset($driver);

        $file = $this->python->projectDir() . '/data/pilotes.json';
        @copy($file, $this->python->projectDir() . '/data/pilotes.bak.json');
        file_put_contents(
            $file,
            json_encode($current, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );

        return new JsonResponse(['saved' => $changed]);
    }

    #[Route('/api/replay/season/commit', name: 'api_replay_season_commit', methods: ['POST'])]
    public function commitSeasonResults(Request $request, EntityManagerInterface $entityManager, SeasonJsonStore $jsonStore): JsonResponse
    {
        if (!$this->isCsrfTokenValid('record_season_results', $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['error' => 'Jeton de sécurité invalide.'], 403);
        }

        $body = json_decode($request->getContent(), true);
        $courseId = is_array($body) ? (int) ($body['course_id'] ?? 0) : 0;
        $divisionId = is_array($body) ? (int) ($body['division_id'] ?? 0) : 0;
        $results = is_array($body) ? ($body['results'] ?? null) : null;
        if ($courseId < 1 || $divisionId < 1 || !is_array($results)) {
            return new JsonResponse(['error' => 'Résultats de saison invalides.'], 422);
        }

        $course = $entityManager->getRepository(Course::class)->find($courseId);
        if ($course === null || $course->getSaison()?->getStatut() === 'terminee') {
            return new JsonResponse(['error' => 'Course de saison introuvable ou saison terminée.'], 409);
        }

        $entriesByName = [];
        foreach ($entityManager->getRepository(Engagement::class)->findBy([
            'saison' => $course->getSaison(),
            'statut' => 'actif',
        ]) as $entry) {
            $snapshot = $entityManager->getRepository(SaisonEngagementDivision::class)->findOneBy(['engagement' => $entry]);
            $entryDivision = $snapshot?->getDivision() ?? $entry->getEcurie()?->getDivision();
            if ($entryDivision?->getId() !== $divisionId || $entry->getPilote() === null) {
                continue;
            }
            $pilot = $entry->getPilote();
            $entriesByName[trim($pilot->getPrenom() . ' ' . $pilot->getNom())] = $entry;
        }

        if ($entriesByName === []) {
            return new JsonResponse(['error' => 'Aucun engagement actif dans cette division.'], 409);
        }

        $saved = 0;
        foreach ($results as $raceResult) {
            if (!is_array($raceResult) || !isset($raceResult['name']) || !is_string($raceResult['name']) || !isset($entriesByName[$raceResult['name']])) {
                continue;
            }
            $entry = $entriesByName[$raceResult['name']];
            $result = $entityManager->getRepository(ResultatCourse::class)->findOneBy([
                'course' => $course,
                'engagement' => $entry,
            ]) ?? (new ResultatCourse())->setCourse($course)->setEngagement($entry);

            $result
                ->setPosition(isset($raceResult['position']) ? (int) $raceResult['position'] : null)
                ->setPoints((float) ($raceResult['points'] ?? 0))
                ->setDnf(($raceResult['status'] ?? '') === 'DNF')
                ->setTourLePlusRapide((bool) ($raceResult['fastest_lap'] ?? false))
                ->setNombreTours((int) ($raceResult['laps'] ?? 0))
                ->setTempsCourse(isset($raceResult['time_s']) ? (float) $raceResult['time_s'] : null)
                ->setPositionDepart(isset($raceResult['grid']) ? (int) $raceResult['grid'] : null)
                ->setPositionArrivee(isset($raceResult['position']) ? (int) $raceResult['position'] : null)
                ->setCauseAbandon(isset($raceResult['reason']) ? (string) $raceResult['reason'] : null);
            $entityManager->persist($result);
            ++$saved;
        }

        if ($saved !== count($entriesByName)) {
            return new JsonResponse(['error' => sprintf('Résultats incomplets : %d sur %d.', $saved, count($entriesByName))], 422);
        }

        $entityManager->flush();
        $activeEntries = $entityManager->getRepository(Engagement::class)->findBy([
            'saison' => $course->getSaison(),
            'statut' => 'actif',
        ]);
        $completedEntries = 0;
        foreach ($activeEntries as $entry) {
            if ($entityManager->getRepository(ResultatCourse::class)->findOneBy(['course' => $course, 'engagement' => $entry]) !== null) {
                ++$completedEntries;
            }
        }
        if ($completedEntries === count($activeEntries)) {
            $course->setStatut('terminee');
        }
        $entityManager->flush();
        $jsonStore->saveSeason($course->getSaison());

        return new JsonResponse(['saved' => $saved, 'course' => $course->getNom()]);
    }

    /**
     * Liste les circuits de public/circuits (.geojson puis .json : le .json l'emporte s'il existe).
     *
     * @return array<string,array{slug:string,name:string,length_m:float}>
     */
    private function getLocalCircuits(): array
    {
        $dir = $this->python->projectDir() . '/public/circuits';
        $circuits = [];

        if (is_dir($dir)) {
            // geojson d'abord, json ensuite : le json écrase l'entrée du geojson du même nom
            $files = array_merge(glob($dir . '/*.geojson') ?: [], glob($dir . '/*.json') ?: []);
            foreach ($files as $file) {
                $content = json_decode((string) file_get_contents($file), true);
                if (!is_array($content)) {
                    continue;
                }

                $slug = pathinfo($file, PATHINFO_FILENAME);
                if ($slug === 'demo') {
                    continue;
                }

                $name = $content['name'] ?? null;
                $length = $content['length_m'] ?? 0;

                if (isset($content['features'][0]['properties'])) {
                    $props = $content['features'][0]['properties'];
                    $name = $props['Name'] ?? $props['Location'] ?? null;
                    $length = $props['length'] ?? 0;
                }

                $circuits[$slug] = [
                    'slug' => $slug,
                    'name' => $name ?: ucfirst(str_replace(['_', '-'], ' ', $slug)),
                    'length_m' => (float) $length,
                ];
            }
        }

        uasort($circuits, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $circuits;
    }

    private function realCircuit(string $circuitSlug): string
    {
        if (!preg_match('/^[a-z0-9\-_]{2,50}$/', $circuitSlug)) {
            throw new \RuntimeException('Identifiant de circuit invalide.');
        }

        $dir = $this->python->projectDir() . '/public/circuits';
        $jsonFile = $dir . '/' . $circuitSlug . '.json';
        $geoJsonFile = $dir . '/' . $circuitSlug . '.geojson';

        if (is_file($jsonFile)) {
            return $jsonFile;
        }

        if (is_file($geoJsonFile)) {
            return $this->convertGeoJsonToEngineFormat($geoJsonFile, $circuitSlug);
        }

        throw new \RuntimeException(sprintf("Le circuit '%s' est introuvable dans la bibliothèque locale.", $circuitSlug));
    }

    /**
     * Convertit un GeoJSON (lon/lat) en circuit exploitable par sim_engine.py :
     * projection en mètres, rééchantillonnage à distance régulière, boucle fermée.
     */
    private function convertGeoJsonToEngineFormat(string $geoJsonPath, string $slug): string
    {
        $tmpDir = $this->python->projectDir() . '/var/cache';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }
        // "v2" : évite de réutiliser d'anciennes conversions faites avec l'échantillonnage par index
        $tmpFile = $tmpDir . '/converted_v2_' . $slug . '.json';
        if (is_file($tmpFile) && filemtime($tmpFile) >= filemtime($geoJsonPath)) {
            return $tmpFile;
        }

        $content = json_decode((string) file_get_contents($geoJsonPath), true);
        $feature = is_array($content) ? ($content['features'][0] ?? null) : null;
        $geometry = is_array($feature) ? ($feature['geometry'] ?? []) : [];
        $coords = $geometry['coordinates'] ?? null;

        if (!is_array($coords) || $coords === []) {
            throw new \RuntimeException('Fichier GeoJSON invalide ou corrompu.');
        }
        // MultiLineString : on met bout à bout les segments
        if (($geometry['type'] ?? '') === 'MultiLineString') {
            $flat = [];
            foreach ($coords as $line) {
                foreach ($line as $pt) {
                    $flat[] = $pt;
                }
            }
            $coords = $flat;
        }
        if (count($coords) < 3) {
            throw new \RuntimeException('Tracé GeoJSON trop court.');
        }

        $props = $feature['properties'] ?? [];
        $name = $props['Name'] ?? $props['Location'] ?? ucfirst(str_replace(['_', '-'], ' ', $slug));
        $lengthM = (float) ($props['length'] ?? 5000);
        if ($lengthM <= 0) {
            $lengthM = 5000.0;
        }

        // Projection locale lon/lat -> mètres
        $refLon = (float) $coords[0][0];
        $refLat = (float) $coords[0][1];
        $cosLat = cos(deg2rad($refLat));
        $xRaw = [];
        $yRaw = [];
        foreach ($coords as $pt) {
            $xRaw[] = ((float) $pt[0] - $refLon) * 111320 * $cosLat;
            $yRaw[] = ((float) $pt[1] - $refLat) * 110540;
        }

        // Fermeture de la boucle
        $xRaw[] = $xRaw[0];
        $yRaw[] = $yRaw[0];

        // Distances cumulées sur le tracé brut
        $cumRaw = [0.0];
        for ($i = 1, $n = count($xRaw); $i < $n; $i++) {
            $cumRaw[] = $cumRaw[$i - 1] + hypot($xRaw[$i] - $xRaw[$i - 1], $yRaw[$i] - $yRaw[$i - 1]);
        }
        $total = end($cumRaw);
        if ($total <= 0) {
            throw new \RuntimeException('Tracé GeoJSON vide.');
        }

        // Rééchantillonnage à intervalles de distance réguliers (interpolation linéaire)
        $nPoints = 700;
        $x = [];
        $y = [];
        $s = [];
        $j = 0;
        $last = count($cumRaw) - 2;
        for ($i = 0; $i < $nPoints; $i++) {
            $target = $total * $i / $nPoints;
            while ($j < $last && $cumRaw[$j + 1] < $target) {
                $j++;
            }
            $seg = ($cumRaw[$j + 1] - $cumRaw[$j]) ?: 1.0;
            $t = ($target - $cumRaw[$j]) / $seg;
            $x[] = $xRaw[$j] + ($xRaw[$j + 1] - $xRaw[$j]) * $t;
            $y[] = $yRaw[$j] + ($yRaw[$j + 1] - $yRaw[$j]) * $t;
            $s[] = $target;
        }

        // Mise à l'échelle sur la longueur officielle
        $scale = $lengthM / $total;
        $x = array_map(fn ($v) => round($v * $scale, 1), $x);
        $y = array_map(fn ($v) => round($v * $scale, 1), $y);
        $s = array_map(fn ($v) => round($v * $scale, 1), $s);

        // Temps au tour : propriété "ref_lap_s" du GeoJSON si présente, sinon estimation (~58 m/s)
        $refLap = isset($props['ref_lap_s']) && (float) $props['ref_lap_s'] > 0
            ? (float) $props['ref_lap_s']
            : round($lengthM / 58, 1);

        $simData = [
            'name' => $name,
            'location' => $props['Location'] ?? 'Circuit',
            'country' => $props['Country'] ?? 'International',
            'year' => (int) ($props['opened'] ?? 2023),
            'length_m' => $lengthM,
            'ref_lap_s' => $refLap,
            'x' => $x,
            'y' => $y,
            's' => $s,
            'drs_zones' => [],
            'corners' => [],
        ];

        file_put_contents($tmpFile, json_encode($simData, JSON_UNESCAPED_UNICODE));

        return $tmpFile;
    }

    private function demoCircuit(): string
    {
        $dir = $this->python->projectDir() . '/public/circuits';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir . '/demo.json';
        if (!is_file($file)) {
            file_put_contents($file, $this->python->run('make_demo_circuit.py', [], 30));
        }

        return $file;
    }

    private function seasonDriversFile(int $courseId, int $divisionId, EntityManagerInterface $entityManager): string
    {
        $course = $entityManager->getRepository(Course::class)->find($courseId);
        if ($course === null || $course->getSaison()?->getStatut() === 'terminee') {
            throw new \RuntimeException('Course de saison introuvable ou déjà terminée.');
        }

        $drivers = [];
        foreach ($entityManager->getRepository(Engagement::class)->findBy([
            'saison' => $course->getSaison(),
            'statut' => 'actif',
        ]) as $entry) {
            $team = $entry->getEcurie();
            $divisionSnapshot = $entityManager->getRepository(SaisonEngagementDivision::class)->findOneBy(['engagement' => $entry]);
            $entryDivision = $divisionSnapshot?->getDivision() ?? $team?->getDivision();
            if ($entryDivision?->getId() !== $divisionId) {
                continue;
            }

            $pilot = $entry->getPilote();
            if ($pilot === null) {
                continue;
            }
            $birthDate = $pilot->getDateNaissance();
            $drivers[] = [
                'name' => trim($pilot->getPrenom() . ' ' . $pilot->getNom()),
                'nationality' => $pilot->getNationalite(),
                'age' => $birthDate ? (int) $birthDate->diff(new \DateTimeImmutable())->y : 28,
                'season_wins' => 0,
                'points' => 0,
                'car_wear' => max(0, min(100, (int) round(100 - $team->getEtatVoiture()))),
                'career_wins' => $pilot->getNombreVictoire(),
                'career_races' => $pilot->getNombreCourse(),
                'career_podiums' => $pilot->getNombrePodium(),
                'championships' => $pilot->getNombreChampionnatF1(),
                'dnf' => 0,
                'team' => $team->getNom(),
            ];
        }

        if ($drivers === []) {
            throw new \RuntimeException('Aucun pilote actif engagé dans cette division.');
        }

        $directory = $this->python->projectDir() . '/var/cache';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $file = tempnam($directory, 'season-drivers-');
        if ($file === false || file_put_contents($file, json_encode($drivers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
            throw new \RuntimeException('Impossible de préparer la grille de départ.');
        }

        return $file;
    }

    /** @return list<array<string,mixed>> */
    private function readDrivers(): array
    {
        $file = $this->python->projectDir() . '/data/pilotes.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        return is_array($data) ? $data : [];
    }

    private function teamsIndex(): array
    {
        $file = $this->python->projectDir() . '/data/ecuries.json';
        $raw = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $index = [];

        foreach (is_array($raw) ? $raw : [] as $team) {
            if (!is_array($team) || !isset($team['id'])) {
                continue;
            }
            $id = (string) $team['id'];
            $color = $team['color'] ?? null;
            if (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $color = self::TEAM_COLORS[$id] ?? self::FALLBACK_PALETTE[crc32($id) % count(self::FALLBACK_PALETTE)];
            }
            $index[$id] = ['id' => $id, 'name' => (string) ($team['name'] ?? $id), 'color' => $color];
        }

        return $index;
    }

    private function teamOf(array $driver, array $teams): ?array
    {
        $teamId = $driver['team_id'] ?? null;
        if (is_string($teamId) && isset($teams[$teamId])) {
            return $teams[$teamId];
        }
        $teamName = $driver['team'] ?? $driver['ecurie'] ?? null;
        if (is_string($teamName) && $teamName !== '') {
            foreach ($teams as $team) {
                if (strcasecmp($team['name'], $teamName) === 0) {
                    return $team;
                }
            }
        }

        return null;
    }

    private function writeEnrichedRoster(): string
    {
        $teams = $this->teamsIndex();
        $drivers = $this->readDrivers();
        foreach ($drivers as &$driver) {
            $team = $this->teamOf($driver, $teams);
            if ($team !== null) {
                $driver['team'] = $team['name'];
            }
        }
        unset($driver);

        $directory = $this->python->projectDir() . '/var/cache';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $file = tempnam($directory, 'roster-');
        if ($file === false || file_put_contents($file, json_encode($drivers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
            throw new \RuntimeException('Impossible de préparer le roster des pilotes.');
        }

        return $file;
    }

    private function applyTeamColors(string $json): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $json;
        }

        $teams = $this->teamsIndex();
        $byName = [];
        foreach ($this->readDrivers() as $driver) {
            if (!isset($driver['name']) || !is_string($driver['name'])) {
                continue;
            }
            $team = $this->teamOf($driver, $teams);
            if ($team !== null) {
                $byName[$driver['name']] = $team;
            }
        }

        foreach ($data['drivers'] ?? [] as $i => $driver) {
            $team = $byName[$driver['name'] ?? ''] ?? null;
            if ($team !== null) {
                $data['drivers'][$i]['color'] = $team['color'];
            }
        }
        foreach ($data['results'] ?? [] as $i => $result) {
            $team = $byName[$result['name'] ?? ''] ?? null;
            if ($team !== null) {
                $data['results'][$i]['team'] = $team['name'];
            }
        }

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? $json : $encoded;
    }

    private function sanitizeModifiers(mixed $raw): array
    {
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        $out = [];
        if (is_array($data)) {
            foreach (array_slice($data, 0, 30, true) as $code => $mods) {
                if (!preg_match('/^[A-Z0-9]{3}$/', (string) $code) || !is_array($mods)) {
                    continue;
                }
                $out[$code] = [
                    'pace' => max(-3.0, min((float) ($mods['pace'] ?? 0), 3.0)),
                    'reliability' => max(0.2, min((float) ($mods['reliability'] ?? 1), 3.0)),
                ];
            }
        }

        return $out;
    }

    private function sanitizeEvents(mixed $raw, int $laps): array
    {
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        $out = [];
        if (is_array($data)) {
            foreach (array_slice($data, 0, 30) as $e) {
                if (!is_array($e) || !in_array($e['type'] ?? null, self::EVENT_TYPES, true)) {
                    continue;
                }
                $lap = (int) ($e['lap'] ?? 0);
                if ($lap < 1 || $lap > $laps) {
                    continue;
                }
                $item = ['lap' => $lap, 'type' => $e['type']];
                if (isset($e['driver']) && preg_match('/^[A-Z0-9]{3}$/', (string) $e['driver'])) {
                    $item['driver'] = $e['driver'];
                }
                $out[] = $item;
            }
        }

        return $out;
    }
}
