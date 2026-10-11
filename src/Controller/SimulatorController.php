<?php

namespace App\Controller;

use App\Service\PythonRunner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    public function index(Request $request, PythonRunner $python): Response
    {
        set_time_limit(300);

        $year = $request->query->getInt('year', 2023);
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
            try {
                // PythonRunner : venv local s'il existe, sinon python3 système (Render)
                $output = $python->run('get_f1_data.py', [(string) $year, $grandPrix, $sessionType], 240);
                $result = json_decode($output, true);

                if (!is_array($result)) {
                    $error = 'Erreur Python : sortie invalide (' . mb_substr(trim($output), 0, 300) . ')';
                } elseif (($result['status'] ?? null) !== 'success') {
                    $error = 'FastF1 : ' . ($result['message'] ?? 'erreur inconnue');
                } else {
                    $drivers = $result['data'] ?? [];
                    $eventName = $result['event'] ?? null;
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
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