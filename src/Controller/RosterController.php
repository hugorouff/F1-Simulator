<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class RosterController extends AbstractController
{
    private function getJsonData(string $filename): array
    {
        $path = $this->getParameter('kernel.project_dir') . '/data/' . $filename;
        if (!file_exists($path)) {
            return [];
        }
        return json_decode(file_get_contents($path), true) ?? [];
    }

    private function saveJsonData(string $filename, array $data): void
    {
        $dir = $this->getParameter('kernel.project_dir') . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/' . $filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    #[Route('/roster/driver/add', name: 'app_roster_add_driver', methods: ['POST'])]
    public function addDriver(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('add_driver', $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_import');
        }

        $teamId = $request->request->get('team_id');
        $drivers = $this->getJsonData('pilotes.json');

        // REGLE : Maximum 2 pilotes par écurie
        if (!empty($teamId)) {
            $currentTeamDrivers = array_filter($drivers, fn($d) => ($d['team_id'] ?? null) === $teamId);
            if (count($currentTeamDrivers) >= 2) {
                $this->addFlash('error', 'Cette écurie compte déjà 2 pilotes (maximum atteint).');
                return $this->redirectToRoute('app_import');
            }
        }

        $name = trim($request->request->get('name'));

        $drivers[] = [
            'name' => $name,
            'team_id' => $teamId,
            'season_wins' => 0,
            'age' => (int) $request->request->get('age', 20),
            'points' => 0,
            'car_wear' => 0,
            'career_wins' => (int) $request->request->get('career_wins', 0),
            'career_races' => 0,
            'dnf' => 0,
            'nationality' => $request->request->get('nationality', 'Français'),
            'career_podiums' => 0,
            'championships' => (int) $request->request->get('championships', 0)
        ];

        $this->saveJsonData('pilotes.json', $drivers);
        $this->addFlash('success', "Le pilote $name a été créé avec succès.");

        return $this->redirectToRoute('app_import');
    }

    #[Route('/roster/team/add', name: 'app_roster_add_team', methods: ['POST'])]
    public function addTeam(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('add_team', $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_import');
        }

        $name = trim($request->request->get('name'));
        $division = strtoupper(trim($request->request->get('division', 'F1')));
        if (empty($division)) {
            $division = 'F1';
        }

        $id = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));

        $teams = $this->getJsonData('ecuries.json');

        foreach ($teams as $t) {
            if ($t['id'] === $id) {
                $this->addFlash('error', 'Cette écurie existe déjà.');
                return $this->redirectToRoute('app_import');
            }
        }

        $teams[] = [
            'id' => $id,
            'name' => $name,
            'division' => $division,
            'car_condition' => 100,
            'constructor_titles' => (int) $request->request->get('constructor_titles', 0),
            'active' => true
        ];

        $this->saveJsonData('ecuries.json', $teams);
        $this->addFlash('success', "L'écurie $name ($division) a été créée avec succès !");

        return $this->redirectToRoute('app_import');
    }
}