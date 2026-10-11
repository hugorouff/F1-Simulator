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

    #[Route('/simulator', name: 'app_simulator', methods: ['GET'])]
    public function index(Request $request, PythonRunner $python): Response
    {
        set_time_limit(300);

        $currentYear = (int) date('Y');
        $year = $request->query->getInt('year', $currentYear);
        $round = $request->query->getInt('round', 0);
        $sessionType = strtoupper((string) $request->query->get('session', 'R'));

        $drivers = [];
        $eventName = null;
        $error = null;

        // On ne charge FastF1 que si le formulaire a été validé (une manche est choisie)
        if ($round > 0) {
            if ($year < 2018 || $year > $currentYear) {
                $error = 'Année invalide (2018 minimum).';
            } elseif ($round > 40) {
                $error = 'Numéro de manche invalide.';
            } elseif (!isset(self::SESSIONS[$sessionType])) {
                $error = 'Type de session invalide.';
            } else {
                try {
                    $output = $python->run('get_f1_data.py', [(string) $year, (string) $round, $sessionType], 240);
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
        }

        return $this->render('simulator/index.html.twig', [
            'drivers' => $drivers,
            'event_name' => $eventName,
            'error' => $error,
            'loaded' => $round > 0 && $error === null,
            'years' => range($currentYear, 2018),
            'sessions' => self::SESSIONS,
            'selected_year' => $year,
            'selected_round' => $round,
            'selected_session' => $sessionType,
        ]);
    }
}