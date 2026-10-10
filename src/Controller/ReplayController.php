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

    public function __construct(private readonly PythonRunner $python)
    {
    }

    #[Route('/replay', name: 'app_replay', methods: ['GET'])]
    public function index(Request $request, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $year = $request->query->getInt('year', 2023);
        $year = max(2018, min($year, (int) date('Y')));
        $courseId = $request->query->getInt('course_id');
        $divisionId = $request->query->getInt('division_id');

        return $this->render('replay/index.html.twig', [
            'years' => range((int) date('Y'), 2018),
            'selected_year' => $year,
            'selected_gp' => trim((string) $request->query->get('gp', 'Monza')),
            'course_id' => $courseId > 0 ? $courseId : null,
            'division_id' => $divisionId > 0 ? $divisionId : null,
            'season_csrf_token' => $csrfTokenManager->getToken('record_season_results')->getValue(),
            'drivers' => $this->readDrivers(),
        ]);
    }

    #[Route('/api/fastf1/track/{year}', name: 'api_fastf1_track', requirements: ['year' => '\\d{4}'], methods: ['GET'])]
    public function track(int $year, Request $request): JsonResponse
    {
        try {
            $file = $this->realCircuit($year, trim((string) $request->query->get('gp', 'Italian Grand Prix')));
            $circuit = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            return new JsonResponse($circuit);
        } catch (Throwable $error) {
            return new JsonResponse(['error' => $error->getMessage()], 502);
        }
    }

    /**
     * Génère une course complète : tracé (réel ou démo) + simulation.
     * Retourne directement le JSON produit par sim_engine.py.
     */
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
        try {
            $circuitFile = $demo
                ? $this->demoCircuit()
                : $this->realCircuit($q->getInt('year', 2020), trim((string) $q->get('gp', 'Monza')));
            $driversFile = $this->python->projectDir() . '/data/pilotes.json';
            if ($q->getInt('course_id') > 0 || $q->getInt('division_id') > 0) {
                if ($q->getInt('course_id') < 1 || $q->getInt('division_id') < 1) {
                    throw new \RuntimeException('La course et la division sont nécessaires pour simuler une manche de saison.');
                }
                $temporaryDriversFile = $this->seasonDriversFile(
                    $q->getInt('course_id'),
                    $q->getInt('division_id'),
                    $entityManager,
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

            $json = $this->python->run('sim_engine.py', $args, 120);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        } finally {
            if ($temporaryDriversFile !== null && is_file($temporaryDriversFile)) {
                unlink($temporaryDriversFile);
            }
        }

        return new Response($json, 200, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /**
     * Enregistre les statistiques mises à jour après la course dans data/pilotes.json.
     */
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
        $jsonStore->save($course->getSaison());

        return new JsonResponse(['saved' => $saved, 'course' => $course->getNom()]);
    }

    private function realCircuit(int $year, string $gp): string
    {
        if ($year < 2018 || $year > (int) date('Y') || !preg_match('/^[\p{L}0-9 \'\-]{2,40}$/u', $gp)) {
            throw new \RuntimeException('Année ou Grand Prix invalide.');
        }

        $dir = $this->python->projectDir() . '/var/circuits';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = sprintf('%s/%d_%s.json', $dir, $year, preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($gp)));

        if (!is_file($file)) { // premier appel : téléchargement FastF1 (1 à 2 min), puis cache disque
            $this->python->run('export_circuit.py', [(string) $year, $gp, $file], 300);
        }

        return $file;
    }

    private function demoCircuit(): string
    {
        $dir = $this->python->projectDir() . '/var/circuits';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir . '/demo.json';
        if (!is_file($file)) {
            file_put_contents($file, $this->python->run('make_demo_circuit.py', [], 30));
        }

        return $file;
    }

    private function seasonDriversFile(
        int $courseId,
        int $divisionId,
        EntityManagerInterface $entityManager,
    ): string {
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
            throw new \RuntimeException('Aucun pilote actif n’est engagé dans cette division pour cette saison.');
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

    /** @return array<string,array<string,float>> */
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

    /** @return list<array<string,mixed>> */
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
