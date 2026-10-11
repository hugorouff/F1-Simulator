<?php
namespace App\Controller;

use App\Entity\Course;
use App\Entity\Division;
use App\Entity\Ecurie;
use App\Entity\Pilote;
use App\Entity\Saison;
use App\Service\PythonRunner;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(EntityManagerInterface $entityManager, PythonRunner $python): Response
    {
        $rosterPath = $python->projectDir() . '/data/pilotes.json';
        $roster = is_file($rosterPath) ? json_decode((string) file_get_contents($rosterPath), true) : [];
        $roster = is_array($roster) ? $roster : [];
        $teamNames = array_unique(array_filter(array_map(
            static fn (mixed $driver): string => is_array($driver) ? trim((string) ($driver['team'] ?? $driver['team_id'] ?? '')) : '',
            $roster,
        )));

        // Exécute une requête Doctrine, renvoie $fallback si la base/table n'existe pas
        $safe = static function (callable $fn, mixed $fallback) {
            try {
                return $fn();
            } catch (\Throwable) {
                return $fallback;
            }
        };

        $drivers = count($roster) ?: $safe(fn () => $entityManager->getRepository(Pilote::class)->count([]), 0);
        $teams = count($teamNames) ?: $safe(fn () => $entityManager->getRepository(Ecurie::class)->count([]), 0);

        return $this->render('home.html.twig', [
            'current_year' => (int) date('Y'),
            'stats' => [
                'drivers' => $drivers,
                'teams' => $teams,
                'divisions' => $safe(fn () => $entityManager->getRepository(Division::class)->count([]), 0),
                'seasons' => $safe(fn () => $entityManager->getRepository(Saison::class)->count([]), 0),
                'races' => $safe(fn () => $entityManager->getRepository(Course::class)->count([]), 0),
            ],
            'recent_seasons' => $safe(
                fn () => $entityManager->getRepository(Saison::class)->findBy([], ['id' => 'DESC'], 4),
                [],
            ),
            'upcoming_races' => $safe(
                fn () => $entityManager->getRepository(Course::class)->createQueryBuilder('race')
                    ->addSelect('circuit')
                    ->join('race.circuit', 'circuit')
                    ->where('race.statut = :status')
                    ->andWhere('race.date >= :today')
                    ->setParameter('status', 'planifiee')
                    ->setParameter('today', new DateTimeImmutable('today'))
                    ->orderBy('race.date', 'ASC')
                    ->setMaxResults(5)
                    ->getQuery()
                    ->getResult(),
                [],
            ),
        ]);
    }
}
