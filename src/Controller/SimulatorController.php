<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;

class SimulatorController extends AbstractController
{
    private const SESSIONS = [
        'R'   => 'Course',
        'Q'   => 'Qualifications',
        'S'   => 'Sprint',
        'FP1' => 'Essais libres 1',
        'FP2' => 'Essais libres 2',
        'FP3' => 'Essais libres 3',
    ];

    private const GRANDS_PRIX = [
        'Bahrain', 'Saudi Arabia', 'Australia', 'Imola', 'Miami', 'Monaco',
        'Spain', 'Canada', 'Austria', 'Silverstone', 'Hungary', 'Spa',
        'Netherlands', 'Monza', 'Singapore', 'Japan', 'Mexico', 'Brazil',
        'Abu Dhabi',
    ];

    #[Route('/simulator', name: 'app_simulator', methods: ['GET'])]
    public function index(Request $request): Response
    {
        set_time_limit(300);

        $year = $request->query->getInt('year', 2020);
        $grandPrix = trim((string) $request->query->get('grand_prix', 'Monza'));
        $sessionType = strtoupper((string) $request->query->get('session', 'R'));

        $drivers = [];
        $eventName = null;
        $error = null;

        if ($year < 2018 || $year > (int) date('Y')) {
            $error = 'Année invalide (2018 minimum).';
        } elseif (!preg_match('/^[\p{L}0-9 \'\-]{2,40}$/u', $grandPrix)) {
            $error = 'Grand Prix invalide.';
        } elseif (!isset(self::SESSIONS[$sessionType])) {
            $error = 'Type de session invalide.';
        }

        if (!$error) {
            $projectDir = $this->getParameter('kernel.project_dir');
            $isWindows = str_starts_with(PHP_OS, 'WIN');
            $python = $projectDir . ($isWindows
                ? '/python/venv/Scripts/python.exe'
                : '/python/venv/bin/python3');
            $script = $projectDir . '/scripts/get_f1_data.py';

            if (!is_file($python)) {
                $error = 'Python introuvable : ' . $python;
            } elseif (!is_file($script)) {
                $error = 'Script introuvable : ' . $script;
            } else {
                $process = new Process([$python, $script, (string) $year, $grandPrix, $sessionType]);
                $process->setTimeout(240);

                try {
                    $process->run();
                } catch (\Throwable $e) {
                    $error = 'Le chargement a dépassé le délai : ' . $e->getMessage();
                }

                if (!$error) {
                    $result = json_decode($process->getOutput(), true);

                    if (!$process->isSuccessful() || !is_array($result)) {
                        $error = 'Erreur Python : ' . trim($process->getErrorOutput() ?: 'sortie invalide');
                    } elseif (($result['status'] ?? null) !== 'success') {
                        $error = 'FastF1 : ' . ($result['message'] ?? 'erreur inconnue');
                    } else {
                        $drivers = $result['data'];
                        $eventName = $result['event'] ?? null;
                    }
                }
            }
        }

        return $this->render('simulator/index.html.twig', [
            'drivers' => $drivers,
            'event_name' => $eventName,
            'error' => $error,
            'years' => range((int) date('Y'), 2018),
            'grands_prix' => self::GRANDS_PRIX,
            'sessions' => self::SESSIONS,
            'selected_year' => $year,
            'selected_gp' => $grandPrix,
            'selected_session' => $sessionType,
        ]);
    }
}