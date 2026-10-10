<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Lance les scripts Python du projet avec le venv (Windows ou Linux).
 */
class PythonRunner
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    public function projectDir(): string
    {
        return $this->projectDir;
    }

    public function pythonPath(): string
    {
        $isWindows = str_starts_with(PHP_OS, 'WIN');

        return $this->projectDir . ($isWindows
            ? '/python/venv/Scripts/python.exe'
            : '/python/venv/bin/python3');
    }

    /**
     * @param list<string> $args arguments passés au script (le chemin du script est ajouté devant)
     *
     * @throws \RuntimeException si Python ou le script est introuvable, ou si le script échoue
     */
    public function run(string $script, array $args, int $timeout = 240): string
    {
        $python = $this->pythonPath();
        $scriptPath = $this->projectDir . '/scripts/' . $script;

        if (!is_file($python)) {
            throw new \RuntimeException('Python introuvable : ' . $python);
        }
        if (!is_file($scriptPath)) {
            throw new \RuntimeException('Script introuvable : ' . $scriptPath);
        }

        $process = new Process([$python, $scriptPath, ...$args], $this->projectDir);
        $process->setTimeout($timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            $detail = trim($process->getOutput() . "\n" . $process->getErrorOutput());
            // dernier message JSON d'erreur du script s'il existe
            if (preg_match('/"message":\s*"([^"]+)"/', $detail, $m)) {
                $detail = $m[1];
            }
            throw new \RuntimeException(mb_substr($detail ?: 'Le script Python a échoué.', 0, 600));
        }

        return $process->getOutput();
    }
}
