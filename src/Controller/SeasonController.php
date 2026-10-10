<?php

namespace App\Controller;

use App\Entity\Circuit;
use App\Entity\Course;
use App\Entity\Division;
use App\Entity\Ecurie;
use App\Entity\Engagement;
use App\Entity\Pilote;
use App\Entity\Saison;
use App\Entity\SaisonEngagementDivision;
use App\Service\FastF1CalendarService;
use App\Service\SeasonJsonStore;
use App\Service\SeasonPromotionService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class SeasonController extends AbstractController
{
    #[Route('/saisons', name: 'app_seasons', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager, SeasonPromotionService $promotion): Response
    {
        $seasons = $entityManager->getRepository(Saison::class)->findBy([], ['annee' => 'DESC', 'nom' => 'ASC']);
        $divisions = $entityManager->getRepository(Division::class)->findBy([], ['niveau' => 'ASC']);
        $standings = [];
        foreach ($seasons as $season) {
            $standings[$season->getId()] = $promotion->standings($season);
        }
        $seasonTeams = [];
        foreach ($seasons as $season) {
            $seasonId = $season->getId();
            $assignedIds = [];
            foreach ($season->getEngagements() as $entry) {
                $team = $entry->getEcurie();
                if ($entry->getStatut() !== 'actif' || $team === null) {
                    continue;
                }
                $snapshot = $entityManager->getRepository(SaisonEngagementDivision::class)->findOneBy(['engagement' => $entry]);
                $entryDivision = $snapshot?->getDivision() ?? $team->getDivision();
                $assignedIds[$team->getId()] = true;
                $seasonTeams[$seasonId][$entryDivision->getId()]['registered'][$team->getId()] = $team->getNom();
            }
            foreach ($divisions as $division) {
                $available = $entityManager->getRepository(Ecurie::class)->findBy(
                    ['division' => $division, 'active' => true],
                    ['nom' => 'ASC'],
                );
                $seasonTeams[$seasonId][$division->getId()]['available'] = array_values(array_filter(
                    $available,
                    static fn (Ecurie $team): bool => !isset($assignedIds[$team->getId()]),
                ));
                $seasonTeams[$seasonId][$division->getId()]['registered'] ??= [];
            }
            $previousDivisionReady = true;
            foreach ($divisions as $division) {
                $registeredCount = count($seasonTeams[$seasonId][$division->getId()]['registered'] ?? []);
                $seasonTeams[$seasonId][$division->getId()]['can_register'] = $previousDivisionReady;
                $previousDivisionReady = $previousDivisionReady && $registeredCount >= 4;
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
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        FastF1CalendarService $calendar,
        SeasonJsonStore $jsonStore,
    ): Response {
        if (!$this->isCsrfTokenValid('create_season', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de formulaire invalide.');
        }

        $name = trim((string) $request->request->get('name', ''));
        $requestedCount = $request->request->getInt('race_count');
        $selections = [];
        foreach ($request->request->all('events') as $selection) {
            if (!is_string($selection) || !preg_match('/^(\d{4}):(\d{1,2})$/', $selection, $match)) {
                continue;
            }

            $year = (int) $match[1];
            $round = (int) $match[2];
            if ($year >= 1950 && $year <= (int) date('Y') + 2 && $round > 0) {
                $selections[$year][$round] = true;
            }
        }

        $selectionCount = array_sum(array_map('count', $selections));
        if ($name === '' || mb_strlen($name) > 100 || $requestedCount < 1 || $requestedCount > 100 || $selectionCount !== $requestedCount) {
            $this->addFlash('error', 'Le nom, le nombre de courses et la sélection doivent correspondre exactement.');

            return $this->redirectToRoute('app_seasons');
        }

        if ($entityManager->getRepository(Saison::class)->findOneBy(['nom' => $name]) !== null) {
            $this->addFlash('error', 'Une saison porte déjà ce nom.');

            return $this->redirectToRoute('app_seasons');
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
                    $circuitKey = mb_strtolower(trim((string) ($event['location'] ?? $event['name'] ?? '')) . '|' . trim((string) ($event['country'] ?? '')));
                    if (isset($selectedCircuits[$circuitKey])) {
                        throw new \RuntimeException('Un circuit ne peut être sélectionné qu’une seule fois dans une saison.');
                    }
                    $selectedCircuits[$circuitKey] = true;
                    $selectedEvents[] = ['year' => (int) $year, 'event' => $event];
                }
            }
        } catch (Throwable $error) {
            $this->addFlash('error', 'Impossible de vérifier les courses FastF1 : ' . $error->getMessage());

            return $this->redirectToRoute('app_seasons');
        }

        usort($selectedEvents, static function (array $left, array $right): int {
            return [$left['event']['date'] ?? '', $left['year'], $left['event']['round']]
                <=> [$right['event']['date'] ?? '', $right['year'], $right['event']['round']];
        });

        $firstDate = $selectedEvents[0]['event']['date'] ?? null;
        $lastDate = $selectedEvents[array_key_last($selectedEvents)]['event']['date'] ?? null;
        $season = (new Saison())
            ->setNom($name)
            ->setAnnee($firstDate ? (int) substr($firstDate, 0, 4) : $selectedEvents[0]['year'])
            ->setStatut('brouillon')
            ->setDateDebut($firstDate ? new DateTimeImmutable($firstDate) : null)
            ->setDateFin($lastDate ? new DateTimeImmutable($lastDate) : null);

        foreach ($selectedEvents as $index => $selection) {
            $event = $selection['event'];
            $eventName = (string) ($event['name'] ?? '');
            $location = $event['location'] ?? null;
            $country = $event['country'] ?? null;
            $circuit = $entityManager->getRepository(Circuit::class)->findOneBy([
                'nom' => $eventName,
                'ville' => $location,
                'pays' => $country,
            ]);

            if ($circuit === null) {
                $circuit = (new Circuit())
                    ->setNom($eventName)
                    ->setVille($location)
                    ->setPays($country);
                $entityManager->persist($circuit);
            }

            $date = isset($event['date']) ? new DateTimeImmutable($event['date']) : null;
            $course = (new Course())
                ->setSaison($season)
                ->setCircuit($circuit)
                ->setNom($eventName)
                ->setNumero($index + 1)
                ->setDate($date)
                ->setStatut('planifiee');

            $entityManager->persist($course);
        }

        $entityManager->persist($season);
        $entityManager->flush();
        $jsonStore->save($season);

        $this->addFlash('success', sprintf('%s créée avec %d courses.', $season->getNom(), count($selectedEvents)));

        return $this->redirectToRoute('app_seasons');
    }

    #[Route('/saisons/{id}/cloturer', name: 'app_season_close', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function close(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        SeasonPromotionService $promotion,
        SeasonJsonStore $jsonStore,
    ): Response {
        if (!$this->isCsrfTokenValid('close_season_' . $id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de formulaire invalide.');
        }

        $season = $entityManager->getRepository(Saison::class)->find($id);
        if ($season === null) {
            throw $this->createNotFoundException('Saison introuvable.');
        }

        try {
            $changes = $promotion->close($season);
            $jsonStore->save($season, [
                'standings' => $promotion->standings($season),
                'promotion_movements' => $changes,
            ]);
        } catch (\RuntimeException $error) {
            $this->addFlash('error', $error->getMessage());

            return $this->redirectToRoute('app_seasons');
        }

        $messages = [];
        foreach ($changes['promoted'] as $move) {
            $messages[] = sprintf('%s monte de %s en %s', $move['team'], $move['from'], $move['to']);
        }
        foreach ($changes['relegated'] as $move) {
            $messages[] = sprintf('%s descend de %s en %s', $move['team'], $move['from'], $move['to']);
        }

        $this->addFlash('success', sprintf(
            '%s clôturée. %s',
            $season->getNom(),
            $messages === [] ? 'Aucun mouvement de division.' : implode(' · ', $messages),
        ));

        return $this->redirectToRoute('app_seasons');
    }

    #[Route('/saisons/{id}/export.json', name: 'app_season_export', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function export(int $id, EntityManagerInterface $entityManager, SeasonJsonStore $jsonStore): Response
    {
        $season = $entityManager->getRepository(Saison::class)->find($id);
        if ($season === null) {
            throw $this->createNotFoundException('Saison introuvable.');
        }

        return $this->file($jsonStore->save($season), sprintf('saison-%d.json', $id));
    }

    #[Route('/divisions', name: 'app_division_create', methods: ['POST'])]
    public function createDivision(Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('create_division', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de formulaire invalide.');
        }

        $name = trim((string) $request->request->get('division_name', ''));
        $level = $request->request->getInt('division_level');
        if ($name === '' || mb_strlen($name) > 100 || $level < 1 || $level > 50) {
            $this->addFlash('error', 'Nom de division ou niveau invalide.');
        } elseif ($entityManager->getRepository(Division::class)->findOneBy(['nom' => $name]) !== null) {
            $this->addFlash('error', 'Cette division existe déjà.');
        } else {
            $highest = $entityManager->getRepository(Division::class)->findOneBy([], ['niveau' => 'DESC']);
            $expectedLevel = $highest === null ? 1 : $highest->getNiveau() + 1;
            if ($level !== $expectedLevel) {
                $this->addFlash('error', sprintf('Crée les divisions dans l’ordre : le prochain niveau disponible est %d.', $expectedLevel));

                return $this->redirectToRoute('app_seasons');
            }
            $entityManager->persist((new Division())->setNom($name)->setNiveau($level));
            $entityManager->flush();
            $this->addFlash('success', sprintf('Division %s créée au niveau %d.', $name, $level));
        }

        return $this->redirectToRoute('app_seasons');
    }

    #[Route('/saisons/{id}/engagements', name: 'app_season_entry_create', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function registerTeam(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        SeasonJsonStore $jsonStore,
    ): Response
    {
        if (!$this->isCsrfTokenValid('register_team_' . $id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de formulaire invalide.');
        }

        $season = $entityManager->getRepository(Saison::class)->find($id);
        $division = $entityManager->getRepository(Division::class)->find($request->request->getInt('division_id'));
        $team = $entityManager->getRepository(Ecurie::class)->find($request->request->getInt('team_id'));
        $driverNames = array_values(array_unique(array_filter($request->request->all('drivers'), 'is_string')));

        if ($season === null || $season->getStatut() === 'terminee' || $division === null || $team === null || $driverNames === []) {
            $this->addFlash('error', 'Choisis une saison ouverte, une division, une écurie importée et au moins un pilote.');

            return $this->redirectToRoute('app_seasons');
        }

        if ($team->getDivision()?->getId() !== $division->getId()) {
            $this->addFlash('error', 'Cette écurie n’appartient pas à la division choisie.');

            return $this->redirectToRoute('app_seasons');
        }
        if ($entityManager->getRepository(Engagement::class)->findOneBy(['saison' => $season, 'ecurie' => $team, 'statut' => 'actif']) !== null) {
            $this->addFlash('error', 'Cette écurie est déjà inscrite dans une division de cette saison.');

            return $this->redirectToRoute('app_seasons');
        }

        foreach ($entityManager->getRepository(Division::class)->findBy([], ['niveau' => 'ASC']) as $previousDivision) {
            if ($previousDivision->getNiveau() >= $division->getNiveau()) {
                break;
            }

            $previousTeamIds = [];
            foreach ($season->getEngagements() as $entry) {
                if ($entry->getStatut() !== 'actif' || $entry->getEcurie() === null) {
                    continue;
                }
                $snapshot = $entityManager->getRepository(SaisonEngagementDivision::class)->findOneBy(['engagement' => $entry]);
                $entryDivision = $snapshot?->getDivision() ?? $entry->getEcurie()->getDivision();
                if ($entryDivision?->getId() === $previousDivision->getId()) {
                    $previousTeamIds[$entry->getEcurie()->getId()] = true;
                }
            }
            if (count($previousTeamIds) < 4) {
                $this->addFlash('error', sprintf('Inscris quatre écuries en %s avant de passer à %s.', $previousDivision->getNom(), $division->getNom()));

                return $this->redirectToRoute('app_seasons');
            }
        }

        $rosterByName = [];
        foreach ($this->readRoster() as $driver) {
            if (isset($driver['name']) && is_string($driver['name'])) {
                $rosterByName[$driver['name']] = $driver;
            }
        }
        foreach ($driverNames as $driverName) {
            if (!isset($rosterByName[$driverName])) {
                $this->addFlash('error', 'Un pilote choisi ne fait pas partie du roster disponible.');

                return $this->redirectToRoute('app_seasons');
            }
        }

        $added = 0;
        foreach ($driverNames as $driverName) {
            $driverData = $rosterByName[$driverName];
            [$firstName, $lastName] = array_pad(explode(' ', $driverName, 2), 2, '');
            $pilot = $entityManager->getRepository(Pilote::class)->findOneBy([
                'prenom' => $firstName,
                'nom' => $lastName,
            ]);
            if ($pilot === null) {
                $pilot = (new Pilote())
                    ->setPrenom($firstName)
                    ->setNom($lastName)
                    ->setNationalite($driverData['nationality'] ?? null)
                    ->setNombreVictoire((int) ($driverData['career_wins'] ?? 0))
                    ->setNombreCourse((int) ($driverData['career_races'] ?? 0))
                    ->setNombrePodium((int) ($driverData['career_podiums'] ?? 0))
                    ->setNombreTrophee((int) ($driverData['season_wins'] ?? 0))
                    ->setNombreChampionnatF1((int) ($driverData['championships'] ?? 0));
                $entityManager->persist($pilot);
            }

            if ($entityManager->getRepository(Engagement::class)->findOneBy(['saison' => $season, 'pilote' => $pilot]) !== null) {
                continue;
            }
            $entry = (new Engagement())
                ->setSaison($season)
                ->setPilote($pilot)
                ->setEcurie($team)
                ->setStatut('actif');
            $entityManager->persist($entry);
            $entityManager->persist((new SaisonEngagementDivision())
                ->setEngagement($entry)
                ->setDivision($division));
            ++$added;
        }

        $entityManager->flush();
        $jsonStore->save($season);
        $this->addFlash('success', sprintf('%s inscrite en %s avec %d pilote(s).', $team->getNom(), $division->getNom(), $added));

        return $this->redirectToRoute('app_seasons');
    }

    /** @return list<array<string,mixed>> */
    private function readRoster(): array
    {
        $file = $this->getParameter('kernel.project_dir') . '/data/pilotes.json';
        $roster = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        return is_array($roster) ? $roster : [];
    }
}