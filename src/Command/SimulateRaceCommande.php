<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'app:simulate-race', description: 'Simule une course Symfony et lance le replay Arcade')]
class SimulateRaceCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('courseId', InputArgument::OPTIONAL, 'ID de la course dans SQLite', 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Correction de la syntaxe d'accès à l'argument
        $courseId = $input->getArgument('courseId');

        // 1. Emplacement du fichier JSON généré
        $jsonPath = __DIR__ . '/../../var/race_simulation_' . $courseId . '.json';

        // 2. Exemple de données structurées issues de la BDD
        $data = [
            'title' => 'Grand Prix - Manche ' . $courseId,
            'total_laps' => 10,
            'drivers' => ['VER', 'HAM', 'LEC'],
            'driver_colors' => [
                'VER' => '#0600EF',
                'HAM' => '#00D2BE',
                'LEC' => '#DC0000'
            ],
            'frames' => [], // Coordonnées X/Y calculées
            'session_info' => ['event_name' => 'Symfony GP', 'circuit_name' => 'Monaco']
        ];

        file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT));
        $output->writeln("Données de la course $courseId exportées dans $jsonPath");

        // 3. Lancement du replay Python
        $process = new Process([
            'python/venv/Scripts/python.exe',
            'python/f1-race-replay/main.py',
            '--custom-race',
            $jsonPath
        ]);
        
        $process->setTimeout(3600);
        $process->run(function ($type, $buffer) use ($output) {
            $output->write($buffer);
        });

        return Command::SUCCESS;
    }
}