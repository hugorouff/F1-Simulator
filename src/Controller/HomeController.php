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
            static fn (mixed $driver): string => is_array($driver) ? trim((string) ($driver['team'] ?? '')) : '',
            $roster,
        )));

        return $this->render('home.html.twig', [
            'current_year' => (int) date('Y'),
            'stats' => [
                'drivers' => count($roster) ?: $entityManager->getRepository(Pilote::class)->count([]),
                'teams' => count($teamNames) ?: $entityManager->getRepository(Ecurie::class)->count([]),
                'divisions' => $entityManager->getRepository(Division::class)->count([]),
                'seasons' => $entityManager->getRepository(Saison::class)->count([]),
                'races' => $entityManager->getRepository(Course::class)->count([]),
            ],
            'recent_seasons' => $entityManager->getRepository(Saison::class)->findBy([], ['id' => 'DESC'], 4),
            'upcoming_races' => $entityManager->getRepository(Course::class)->createQueryBuilder('race')
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
        ]);
    }
}
