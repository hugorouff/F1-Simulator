<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ImportController extends AbstractController
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

        // Sauvegarde de précaution (backup) avant écriture
        $targetPath = $dir . '/' . $filename;
        if (file_exists($targetPath)) {
            $backupName = pathinfo($filename, PATHINFO_FILENAME) . '_backup_' . date('Y-m-d_H-i-s') . '.json';
            copy($targetPath, $dir . '/' . $backupName);
        }

        file_put_contents($targetPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    #[Route('/import', name: 'app_import', methods: ['GET'])]
    public function index(): Response
    {
        $driversData = $this->getJsonData('pilotes.json');
        $teamsData = $this->getJsonData('ecuries.json');

        // Extraire les divisions uniques pour le filtre / datalist
        $divisions = [];
        $uniqueDivisions = array_unique(array_column($teamsData, 'division'));
        foreach ($uniqueDivisions as $index => $div) {
            if (!empty($div)) {
                $divisions[] = [
                    'id' => $div,
                    'niveau' => $index + 1,
                    'nom' => 'Division ' . strtoupper($div)
                ];
            }
        }

        return $this->render('import/index.html.twig', [
            'drivers' => count($driversData),
            'teams' => count($teamsData),
            'divisions' => $divisions,
            'ecuriesList' => $teamsData, // <--- C'est cette ligne qui manquait pour peupler le select des écuries !
        ]);
    }

    #[Route('/import/drivers', name: 'app_import_drivers', methods: ['POST'])]
    public function importDrivers(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('import_drivers', $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_import_index');
        }

        $uploadedFile = $request->files->get('json_file');
        if (!$uploadedFile) {
            $this->addFlash('error', 'Aucun fichier sélectionné.');
            return $this->redirectToRoute('app_import_index');
        }

        try {
            $content = file_get_contents($uploadedFile->getPathname());
            $importedDrivers = json_decode($content, true);

            if (!is_array($importedDrivers)) {
                throw new \Exception('Le fichier JSON des pilotes doit contenir un tableau d\'objets valide.');
            }

            // Vérification du respect de la règle : Max 2 pilotes par team_id
            $teamCounts = [];
            foreach ($importedDrivers as $driver) {
                $teamId = $driver['team_id'] ?? null;
                if ($teamId) {
                    $teamCounts[$teamId] = ($teamCounts[$teamId] ?? 0) + 1;
                    if ($teamCounts[$teamId] > 2) {
                        throw new \Exception("Importation annulée : L'écurie \"$teamId\" dépasse la limite de 2 pilotes maximum.");
                    }
                }
            }

            $this->saveJsonData('pilotes.json', $importedDrivers);
            $this->addFlash('success', 'Roster des pilotes importé et mis à jour avec succès !');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de l\'importation des pilotes : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_import');
    }

    #[Route('/import/teams', name: 'app_import_teams', methods: ['POST'])]
    public function importTeams(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('import_teams', $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_import_index');
        }

        $uploadedFile = $request->files->get('json_file');
        if (!$uploadedFile) {
            $this->addFlash('error', 'Aucun fichier sélectionné.');
            return $this->redirectToRoute('app_import_index');
        }

        $defaultDivision = $request->request->get('division_id');

        try {
            $content = file_get_contents($uploadedFile->getPathname());
            $importedTeams = json_decode($content, true);

            if (!is_array($importedTeams)) {
                throw new \Exception('Le fichier JSON des écuries doit contenir un tableau d\'objets valide.');
            }

            // Traitement et normalisation des écuries importées
            $processedTeams = [];
            foreach ($importedTeams as $team) {
                // Si l'écurie n'a pas d'ID unique (slug), on le génère depuis son nom
                $id = $team['id'] ?? strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $team['name'] ?? 'ecurie'));

                $processedTeams[] = [
                    'id' => $id,
                    'name' => $team['name'] ?? 'Écurie Inconnue',
                    'division' => ($defaultDivision && $defaultDivision !== '0') ? $defaultDivision : ($team['division'] ?? 'F1'),
                    'car_condition' => $team['car_condition'] ?? 100,
                    'constructor_titles' => $team['constructor_titles'] ?? 0,
                    'active' => $team['active'] ?? true,
                ];
            }

            $this->saveJsonData('ecuries.json', $processedTeams);
            $this->addFlash('success', 'Écuries importées et mises à jour avec succès !');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de l\'importation des écuries : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_import');
    }
}