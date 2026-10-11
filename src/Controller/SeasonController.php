<?php

namespace App\Controller;

use App\Service\FastF1CalendarService;
use App\Service\SeasonJsonStore;
use App\Service\SeasonPromotionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class SeasonController extends AbstractController
{
    #[Route('/saisons', name: 'app_seasons', methods: ['GET'])]
    public function index(SeasonJsonStore $store, SeasonPromotionService $promotion): Response
    {
        $seasons = $store->seasons();
        $teams = $store->teams();
        $divisions = $store->divisions();

        foreach ($divisions as &$division) {
            $name = $division['nom'];
            $division['ecuries'] = array_values(array_filter(
                $teams,
                static fn (array $t): bool => strcasecmp($t['division'], $name) === 0,
            ));
        }
        unset($division);

        $standings = [];
        $seasonTeams = [];
        foreach ($seasons as $season) {
            $sid = $season['id'];
            $standings[$sid] = $promotion->standings($season);

            $assigned = [];
            foreach ($season['engagements'] as $entry) {
                $team = $teams[$entry['ecurie_id']] ?? null;
                if ($team === null) {
                    continue;
                }
                $assigned[$team['id']] = true;
                $seasonTeams[$sid][$entry['division_id']]['registered'][$team['id']] = $team['nom'];
            }

            $previousReady = true;
            foreach ($divisions as $division) {
                $did = $division['id'];
                $seasonTeams[$sid][$did]['registered'] ??= [];
                $seasonTeams[$sid][$did]['available'] = array_values(array_filter(
                    $division['ecuries'],
                    static fn (array $t): bool => $t['active'] && !isset($assigned[$t['id']]),
                ));
                $seasonTeams[$sid][$did]['can_register'] = $previousReady;
                $previousReady = $previousReady && count($seasonTeams[$sid][$did]['registered']) >= 4;
            }
        }

        return $this->render('season/index.html.twig', [
            'seasons' => $seasons,
            'standings' => $standings,
            'divisions' => $divisions,
            'season_teams' => $seasonTeams,
            'roster' => $this->readRoster(),
            'current_year' => (int) date('Y'),
        ]);
    }

    #[Route('/api/fastf1/calendar/{year}', name: 'api_fastf1_calendar', requirements: ['year' => '\\d{4}'], methods: ['GET'])]
    public function calendar(int $year, FastF1CalendarService $calendar): JsonResponse
    {
        if ($year < 1950 || $year > (int) date('Y') + 2) {
            return new JsonResponse(['error' => 'Année hors de la plage des calendriers FastF1.'], 422);
        }
        try {
            return new JsonResponse(['year' => $year, 'events' => $calendar->events($year)]);
        } catch (Throwable $error) {
            return new JsonResponse(['error' => $error->getMessage()], 502);
        }
    }

    #[Route('/api/fastf1/events', name: 'api_fastf1_events', methods: ['GET'])]
    public function searchEvents(Request $request, FastF1CalendarService $calendar): JsonResponse
    {
        set_time_limit(360);
        $query = trim((string) $request->query->get('q', ''));
        if (($query !== '' && mb_strlen($query) < 2) || mb_strlen($query) > 80) {
            return new JsonResponse(['error' => 'Saisis au moins deux caractères pour filtrer le catalogue.'], 422);
        }
        try {
            return new JsonResponse(['events' => $calendar->search($query)]);
        } catch (Throwable $error) {
            return new JsonResponse(['error' => $error->getMessage()], 502);
        }
    }

    #[Route('/saisons', name: 'app_seasons_create', methods: ['POST'])]
    public function create(Request $request, FastF1CalendarService $calendar, SeasonJsonStore $store): Response
    {
        if (!$this->isCsrfTokenValid('create_season', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de formulaire invalide.');
        }

        $name = trim((string) $request->request->get('name', ''));
        $requestedCount = $request->request->getInt('race_count');
        $selections = [];
        foreach ($request->request->all('events') as $selection) {
            if (!is_string($selection) || !preg_match('/^(\d{4}):(\d{1,2})$/', $selection, $m)) {
                continue;
            }
            $year = (int) $m[1];
            $round = (int) $m[2];
            if ($year >= 1950 && $year <= (int) date('Y') + 2 && $round > 0) {
                $selections[$year][$round] = true;
            }
        }

        $selectionCount = array_sum(array_map('count', $selections));
        if ($name === '' || mb_strlen($name) > 100 || $requestedCount < 1 || $requestedCount > 100 || $selectionCount !== $requestedCount) {
            $this->addFlash('error', 'Le nom, le nombre de courses et la sélection doivent correspondre exactement.');

            return $this->redirectToRoute('app_seasons');
        }

        foreach ($store->seasons() as $existing) {
            if (mb_strtolower($existing['nom']) === mb_strtolower($name)) {
                $this->addFlash('error', 'Une saison porte déjà ce nom.');

                return $this->redirectToRoute('app_seasons');
            }
        }

        $selectedEvents = [];
        $selectedCircuits = [];
        try {
            foreach ($selections as $year => $rounds) {
                $eventsByRound = [];
                foreach ($calendar->events((int) $year) as $event) {
                    $eventsByRound[(int) $event['round']] = $event;
                }
                foreach (array_keys($rounds) as $round) {
                    if (!isset($eventsByRound[$round])) {
                        throw new \RuntimeException(sprintf('Manche %d absente du calendrier %d.', $round, $year));
                    }
                    $event = $eventsByRound[$round];
                    $key = mb_strtolower(trim((string) ($event['location'] ?? $event['name'] ?? '')) . '|' . trim((string) ($event['country'] ?? '')));
                    if (isset($selectedCircuits[$key])) {
                        throw new \RuntimeException('Un circuit ne peut être sélectionné qu’une seule fois dans une saison.');
                    }
                    $selectedCircuits[$key] = true;
                    $selectedEvents[] = ['year' => (int) $year, 'event' => $event];
                }
            }
        } catch (Throwable $error) {
            $this->addFlash('error', 'Impossible de vérifier les courses FastF1 : ' . $error->getMessage());

            return $this->redirectToRoute('app_seasons');
        }

        usort($selectedEvents, static fn (array $l, array $r): int =>
            [$l['event']['date'] ?? '', $l['year'], $l['event']['round']]
            <=> [$r['event']['date'] ?? '', $r['year'], $r['event']['round']]);

        $firstDate = $selectedEvents[0]['event']['date'] ?? null;
        $lastDate = $selectedEvents[array_key_last($selectedEvents)]['event']['date'] ?? null;

        $courses = [];
        foreach ($selectedEvents as $i => $selection) {
            $event = $selection['event'];
            $courses[] = [
                'id' => $i + 1,
                'numero' => $i + 1,
                'nom' => (string) ($event['name'] ?? ''),
                'date' => $event['date'] ?? null,
                'statut' => 'planifiee',
                'annee_fastf1' => $selection['year'],
                'circuit' => [
                    'nom' => (string) ($event['name'] ?? ''),
                    'ville' => $event['location'] ?? null,
                    'pays' => $event['country'] ?? null,
                ],
                'resultats' => [],
            ];
        }

        $store->saveSeason([
            'id' => $store->nextSeasonId(),
            'nom' => $name,
            'annee' => $firstDate ? (int) substr($firstDate, 0, 4) : $selectedEvents[0]['year'],
            'statut' => 'brouillon',
            'date_debut' => $firstDate,
            'date_fin' => $lastDate,
            'courses' => $courses,
            'engagements' => [],
        ]);

        $this->addFlash('success', sprintf('%s créée avec %d courses.', $name, count($courses)));

        return $this->redirectToRoute('app_seasons');
    }

    #[Route('/saisons/import', name: 'app_season_import', methods: ['POST'])]
    public function import(Request $request, SeasonJsonStore $store): Response
    {
        if (!$this->isCsrfTokenValid('import_season', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de formulaire invalide.');
        }

        $file = $request->files->get('json_file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', 'Aucun fichier valide sélectionné.');

            return $this->redirectToRoute('app_seasons');
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            $this->addFlash('error', 'Fichier trop volumineux (5 Mo maximum).');

            return $this->redirectToRoute('app_seasons');
        }

        try {
            $data = json_decode((string) file_get_contents($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \RuntimeException('Le JSON doit contenir un objet de saison.');
            }
            $season = $this->normalizeSeason($data);
        } catch (\JsonException) {
            $this->addFlash('error', 'Fichier JSON invalide.');

            return $this->redirectToRoute('app_seasons');
        } catch (\RuntimeException $error) {
            $this->addFlash('error', 'Import refusé : ' . $error->getMessage());

            return $this->redirectToRoute('app_seasons');
        }

        $replace = $request->request->getBoolean('replace');
        $existing = $store->seasons();
        $sameId = null;
        foreach ($existing as $other) {
            if ((int) $other['id'] === $season['id']) {
                $sameId = $other;
            }
        }

        if ($sameId !== null && !$replace) {
            $season['id'] = $store->nextSeasonId();
            $sameId = null;
        }
        foreach ($existing as $other) {
            if ((int) $other['id'] !== $season['id'] && mb_strtolower($other['nom']) === mb_strtolower($season['nom'])) {
                $this->addFlash('error', 'Une autre saison porte déjà ce nom.');

                return $this->redirectToRoute('app_seasons');
            }
        }
        if ($sameId !== null && isset($sameId['created_at'])) {
            $season['created_at'] = $sameId['created_at'];
        }

        $store->saveSeason($season);
        $this->addFlash('success', sprintf(
            'Saison « %s » importée (n°%d, %d course(s), %d engagement(s)).',
            $season['nom'], $season['id'], count($season['courses']), count($season['engagements']),
        ));

        return $this->redirectToRoute('app_seasons');
    }

    #[Route('/saisons/{id}/cloturer', name: 'app_season_close', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function close(int $id, Request $request, SeasonJsonStore $store, SeasonPromotionService $promotion): Response
    {
        if (!$this->isCsrfTokenValid('close_season_' . $id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de formulaire invalide.');
        }
        $season = $store->season($id);
        if ($season === null) {
            throw $this->createNotFoundException('Saison introuvable.');
        }

        try {
            $standings = $promotion->standings($season);
            $changes = $promotion->close($season);
            $season['standings'] = $standings;
            $season['promotion_movements'] = $changes;
            $store->saveSeason($season);
        } catch (\RuntimeException $error) {
            $this->addFlash('error', $error->getMessage());

            return $this->redirectToRoute('app_seasons');
        }

        $messages = [];
        foreach ($changes['promoted'] as $m) {
            $messages[] = sprintf('%s monte de %s en %s', $m['team'], $m['from'], $m['to']);
        }
        foreach ($changes['relegated'] as $m) {
            $messages[] = sprintf('%s descend de %s en %s', $m['team'], $m['from'], $m['to']);
        }
        $this->addFlash('success', sprintf('%s clôturée. %s', $season['nom'], $messages === [] ? 'Aucun mouvement de division.' : implode(' · ', $messages)));

        return $this->redirectToRoute('app_seasons');
    }

    #[Route('/saisons/{id}/export.json', name: 'app_season_export', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function export(int $id, SeasonJsonStore $store): Response
    {
        if ($store->season($id) === null) {
            throw $this->createNotFoundException('Saison introuvable.');
        }

        return $this->file($store->seasonPath($id), sprintf('saison-%d.json', $id));
    }

    #[Route('/divisions', name: 'app_division_create', methods: ['POST'])]
    public function createDivision(Request $request, SeasonJsonStore $store): Response
    {
        if (!$this->isCsrfTokenValid('create_division', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de formulaire invalide.');
        }

        $name = trim((string) $request->request->get('division_name', ''));
        $level = $request->request->getInt('division_level');
        $divisions = $store->divisions();

        if ($name === '' || mb_strlen($name) > 100 || $level < 1 || $level > 50) {
            $this->addFlash('error', 'Nom de division ou niveau invalide.');

            return $this->redirectToRoute('app_seasons');
        }
        foreach ($divisions as $d) {
            if (strcasecmp($d['nom'], $name) === 0) {
                $this->addFlash('error', 'Cette division existe déjà.');

                return $this->redirectToRoute('app_seasons');
            }
        }

        $expected = $divisions === [] ? 1 : max(array_column($divisions, 'niveau')) + 1;
        if ($level !== $expected) {
            $this->addFlash('error', sprintf('Crée les divisions dans l’ordre : le prochain niveau disponible est %d.', $expected));

            return $this->redirectToRoute('app_seasons');
        }

        $divisions[] = [
            'id' => ($divisions === [] ? 0 : max(array_column($divisions, 'id'))) + 1,
            'nom' => $name,
            'niveau' => $level,
        ];
        $store->saveDivisions($divisions);
        $this->addFlash('success', sprintf('Division %s créée au niveau %d.', $name, $level));

        return $this->redirectToRoute('app_seasons');
    }

    #[Route('/saisons/{id}/engagements', name: 'app_season_entry_create', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function registerTeam(int $id, Request $request, SeasonJsonStore $store): Response
    {
        if (!$this->isCsrfTokenValid('register_team_' . $id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de formulaire invalide.');
        }

        $season = $store->season($id);
        $divisions = $store->divisions();
        $divisionId = $request->request->getInt('division_id');
        $division = null;
        foreach ($divisions as $d) {
            if ($d['id'] === $divisionId) {
                $division = $d;
            }
        }
        $teams = $store->teams();
        $team = $teams[(string) $request->request->get('team_id', '')] ?? null;
        $driverNames = array_values(array_unique(array_filter($request->request->all('drivers'), 'is_string')));

        if ($season === null || ($season['statut'] ?? '') === 'terminee' || $division === null || $team === null || $driverNames === []) {
            $this->addFlash('error', 'Choisis une saison ouverte, une division, une écurie importée et au moins un pilote.');

            return $this->redirectToRoute('app_seasons');
        }
        if (count($driverNames) > 2) {
            $this->addFlash('error', 'Une écurie compte 2 pilotes maximum.');

            return $this->redirectToRoute('app_seasons');
        }
        if (strcasecmp($team['division'], $division['nom']) !== 0) {
            $this->addFlash('error', 'Cette écurie n’appartient pas à la division choisie.');

            return $this->redirectToRoute('app_seasons');
        }

        $takenDrivers = [];
        foreach ($season['engagements'] as $entry) {
            if ($entry['ecurie_id'] === $team['id']) {
                $this->addFlash('error', 'Cette écurie est déjà inscrite dans une division de cette saison.');

                return $this->redirectToRoute('app_seasons');
            }
            foreach ($entry['pilotes'] ?? [] as $p) {
                $takenDrivers[$p] = true;
            }
        }
        foreach ($driverNames as $driverName) {
            if (isset($takenDrivers[$driverName])) {
                $this->addFlash('error', sprintf('%s est déjà engagé dans cette saison.', $driverName));

                return $this->redirectToRoute('app_seasons');
            }
        }

        foreach ($divisions as $previous) {
            if ($previous['niveau'] >= $division['niveau']) {
                break;
            }
            $count = count(array_filter($season['engagements'], static fn (array $e): bool => $e['division_id'] === $previous['id']));
            if ($count < 4) {
                $this->addFlash('error', sprintf('Inscris quatre écuries en %s avant de passer à %s.', $previous['nom'], $division['nom']));

                return $this->redirectToRoute('app_seasons');
            }
        }

        $rosterNames = array_column($this->readRoster(), 'name');
        foreach ($driverNames as $driverName) {
            if (!in_array($driverName, $rosterNames, true)) {
                $this->addFlash('error', 'Un pilote choisi ne fait pas partie du roster disponible.');

                return $this->redirectToRoute('app_seasons');
            }
        }

        $season['engagements'][] = [
            'ecurie_id' => $team['id'],
            'division_id' => $division['id'],
            'pilotes' => $driverNames,
        ];
        $store->saveSeason($season);

        $this->addFlash('success', sprintf('%s inscrite en %s avec %d pilote(s).', $team['nom'], $division['nom'], count($driverNames)));

        return $this->redirectToRoute('app_seasons');
    }

    /** Valide et nettoie un JSON de saison avant écriture. */
    private function normalizeSeason(array $data): array
    {
        $nom = trim((string) ($data['nom'] ?? ''));
        if ($nom === '' || mb_strlen($nom) > 100) {
            throw new \RuntimeException('champ « nom » manquant ou trop long.');
        }
        if (!isset($data['courses']) || !is_array($data['courses']) || $data['courses'] === []) {
            throw new \RuntimeException('la saison doit contenir au moins une course.');
        }

        $courses = [];
        foreach (array_values($data['courses']) as $i => $race) {
            if (!is_array($race) || trim((string) ($race['nom'] ?? '')) === '') {
                throw new \RuntimeException(sprintf('la course %d n’a pas de nom.', $i + 1));
            }
            $results = [];
            foreach ($race['resultats'] ?? [] as $result) {
                if (!is_array($result) || !isset($result['ecurie_id'])) {
                    continue;
                }
                $results[] = [
                    'pilote' => (string) ($result['pilote'] ?? ''),
                    'ecurie_id' => (string) $result['ecurie_id'],
                    'position' => isset($result['position']) ? (int) $result['position'] : null,
                    'points' => (float) ($result['points'] ?? 0),
                ];
            }
            $circuit = is_array($race['circuit'] ?? null) ? $race['circuit'] : [];
            $courses[] = [
                'id' => $i + 1,
                'numero' => $i + 1,
                'nom' => trim((string) $race['nom']),
                'date' => isset($race['date']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $race['date']) ? substr((string) $race['date'], 0, 10) : null,
                'statut' => in_array($race['statut'] ?? '', ['planifiee', 'terminee'], true) ? $race['statut'] : 'planifiee',
                'annee_fastf1' => isset($race['annee_fastf1']) ? (int) $race['annee_fastf1'] : null,
                'circuit' => [
                    'nom' => (string) ($circuit['nom'] ?? $race['nom']),
                    'ville' => $circuit['ville'] ?? null,
                    'pays' => $circuit['pays'] ?? null,
                ],
                'resultats' => $results,
            ];
        }

        $engagements = [];
        $seenTeams = [];
        foreach ($data['engagements'] ?? [] as $entry) {
            if (!is_array($entry) || !isset($entry['ecurie_id'], $entry['division_id'])) {
                continue;
            }
            $pilots = array_values(array_unique(array_filter((array) ($entry['pilotes'] ?? []), 'is_string')));
            if (count($pilots) > 2) {
                throw new \RuntimeException(sprintf('l’écurie « %s » a plus de 2 pilotes.', $entry['ecurie_id']));
            }
            if (isset($seenTeams[$entry['ecurie_id']])) {
                throw new \RuntimeException(sprintf('l’écurie « %s » est engagée deux fois.', $entry['ecurie_id']));
            }
            $seenTeams[$entry['ecurie_id']] = true;
            $engagements[] = [
                'ecurie_id' => (string) $entry['ecurie_id'],
                'division_id' => (int) $entry['division_id'],
                'pilotes' => $pilots,
            ];
        }

        $dates = array_values(array_filter(array_column($courses, 'date')));
        $season = [
            'id' => max(1, (int) ($data['id'] ?? 0)),
            'nom' => $nom,
            'annee' => (int) ($data['annee'] ?? ($dates !== [] ? substr($dates[0], 0, 4) : date('Y'))),
            'statut' => in_array($data['statut'] ?? '', ['brouillon', 'terminee'], true) ? $data['statut'] : 'brouillon',
            'date_debut' => $data['date_debut'] ?? ($dates[0] ?? null),
            'date_fin' => $data['date_fin'] ?? ($dates !== [] ? end($dates) : null),
            'courses' => $courses,
            'engagements' => $engagements,
        ];
        foreach (['standings', 'promotion_movements'] as $optional) {
            if (isset($data[$optional]) && is_array($data[$optional])) {
                $season[$optional] = $data[$optional];
            }
        }

        return $season;
    }

    /** @return list<array<string,mixed>> */
    private function readRoster(): array
    {
        $file = $this->getParameter('kernel.project_dir') . '/data/pilotes.json';
        $roster = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        return is_array($roster) ? $roster : [];
    }
}
