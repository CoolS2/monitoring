<?php

namespace App\Controller;

use App\Entity\CheckError;
use App\Entity\CheckResult;
use App\Entity\LLMAnalysis;
use App\Entity\Notification;
use App\Service\MonitorScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MonitorScheduler $scheduler
    ) {}

    #[Route('/api/checks', name: 'api_checks', methods: ['GET'])]
    public function getChecks(): JsonResponse
    {
        $config = $this->scheduler->getConfiguration();
        $checks = $config['checks'] ?? [];

        // Fetch latest result for all checks in a single query
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('r')
           ->from(CheckResult::class, 'r')
           ->where('r.createdAt IN (
               SELECT MAX(r2.createdAt) 
               FROM App\Entity\CheckResult r2 
               GROUP BY r2.checkKey
           )');

        $latestResults = $qb->getQuery()->getResult();
        $resultsMap = [];
        /** @var CheckResult $result */
        foreach ($latestResults as $result) {
            $resultsMap[$result->getCheckKey()] = [
                'success' => $result->isSuccess(),
                'message' => $result->getMessage(),
                'response_time' => $result->getResponseTime(),
                'last_run' => $result->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'extra' => $result->getExtra()
            ];
        }

        $formatted = [];
        foreach ($checks as $key => $check) {
            $formatted[] = [
                'key' => $key,
                'type' => $check['type'] ?? 'unknown',
                'interval' => $check['interval'] ?? 60,
                'url' => $check['url'] ?? null,
                'host' => $check['host'] ?? null,
                'status' => $resultsMap[$key] ?? [
                    'success' => true,
                    'message' => 'Pending check run',
                    'response_time' => null,
                    'last_run' => null,
                    'extra' => []
                ]
            ];
        }

        return new JsonResponse($formatted);
    }

    #[Route('/api/checks/{key}', name: 'api_check_detail', methods: ['GET'])]
    public function getCheckDetail(string $key): JsonResponse
    {
        $config = $this->scheduler->getConfiguration();
        $checks = $config['checks'] ?? [];

        if (!isset($checks[$key])) {
            return new JsonResponse(['error' => 'Check not found in configuration'], 404);
        }

        $checkConfig = $checks[$key];

        // Fetch last 50 check runs
        $resultRepo = $this->entityManager->getRepository(CheckResult::class);
        $results = $resultRepo->findBy(
            ['checkKey' => $key],
            ['createdAt' => 'DESC'],
            50
        );

        $formattedResults = array_map(fn(CheckResult $r) => [
            'id' => $r->getId(),
            'success' => $r->isSuccess(),
            'message' => $r->getMessage(),
            'response_time' => $r->getResponseTime(),
            'created_at' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'extra' => $r->getExtra()
        ], $results);

        // Fetch active outage errors
        $errorRepo = $this->entityManager->getRepository(CheckError::class);
        $activeErrors = $errorRepo->findBy(
            ['checkKey' => $key, 'resolvedAt' => null],
            ['createdAt' => 'DESC']
        );

        $formattedErrors = [];
        foreach ($activeErrors as $error) {
            // Find LLM analysis linked to this error
            $analysisRepo = $this->entityManager->getRepository(LLMAnalysis::class);
            $analysis = $analysisRepo->findOneBy(['checkError' => $error]);

            $formattedErrors[] = [
                'id' => $error->getId(),
                'message' => $error->getMessage(),
                'details' => $error->getDetails(),
                'created_at' => $error->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'llm_analysis' => $analysis ? [
                    'summary' => $analysis->getSummary(),
                    'probable_cause' => $analysis->getProbableCause(),
                    'severity' => $analysis->getSeverity(),
                    'recommendations' => $analysis->getRecommendations(),
                    'created_at' => $analysis->getCreatedAt()->format(\DateTimeInterface::ATOM)
                ] : null
            ];
        }

        return new JsonResponse([
            'key' => $key,
            'config' => $checkConfig,
            'history' => $formattedResults,
            'active_errors' => $formattedErrors
        ]);
    }

    #[Route('/api/errors', name: 'api_errors', methods: ['GET'])]
    public function getErrors(): JsonResponse
    {
        $errorRepo = $this->entityManager->getRepository(CheckError::class);
        
        // Find recent failures (active and last 50 resolved)
        $active = $errorRepo->findBy(['resolvedAt' => null], ['createdAt' => 'DESC']);
        
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('e')
           ->from(CheckError::class, 'e')
           ->where('e.resolvedAt IS NOT NULL')
           ->orderBy('e.resolvedAt', 'DESC')
           ->setMaxResults(50);
        $resolved = $qb->getQuery()->getResult();

        $formatError = function(CheckError $error) {
            $analysis = $this->entityManager->getRepository(LLMAnalysis::class)->findOneBy(['checkError' => $error]);
            return [
                'id' => $error->getId(),
                'check_key' => $error->getCheckKey(),
                'message' => $error->getMessage(),
                'details' => $error->getDetails(),
                'created_at' => $error->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'resolved_at' => $error->getResolvedAt()?->format(\DateTimeInterface::ATOM),
                'llm_analysis' => $analysis ? [
                    'summary' => $analysis->getSummary(),
                    'probable_cause' => $analysis->getProbableCause(),
                    'severity' => $analysis->getSeverity(),
                    'recommendations' => $analysis->getRecommendations()
                ] : null
            ];
        };

        return new JsonResponse([
            'active' => array_map($formatError, $active),
            'resolved' => array_map($formatError, $resolved)
        ]);
    }

    #[Route('/api/alerts', name: 'api_alerts', methods: ['GET'])]
    public function getAlerts(): JsonResponse
    {
        $notificationRepo = $this->entityManager->getRepository(Notification::class);
        $alerts = $notificationRepo->findBy([], ['sentAt' => 'DESC'], 100);

        $formatted = array_map(fn(Notification $n) => [
            'id' => $n->getId(),
            'check_key' => $n->getCheckKey(),
            'type' => $n->getType(),
            'message' => $n->getMessage(),
            'sent_at' => $n->getSentAt()->format(\DateTimeInterface::ATOM)
        ], $alerts);

        return new JsonResponse($formatted);
    }

    #[Route('/api/stats', name: 'api_stats', methods: ['GET'])]
    public function getStats(): JsonResponse
    {
        $since = new \DateTimeImmutable('-24 hours');

        // Total runs and failure count in last 24h
        $totalRuns = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(CheckResult::class, 'r')
            ->where('r.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        $failedRuns = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(CheckResult::class, 'r')
            ->where('r.createdAt >= :since')
            ->andWhere('r.success = false')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        // Average response time by check key in the last 24h
        $avgResponseTimes = $this->entityManager->createQueryBuilder()
            ->select('r.checkKey, AVG(r.responseTime) as avgTime')
            ->from(CheckResult::class, 'r')
            ->where('r.createdAt >= :since')
            ->andWhere('r.responseTime IS NOT NULL')
            ->groupBy('r.checkKey')
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();

        $formattedAvgTime = [];
        foreach ($avgResponseTimes as $row) {
            $formattedAvgTime[$row['checkKey']] = round((float) $row['avgTime'], 3);
        }

        // Check key incident count (how many times each failed)
        $incidents = $this->entityManager->createQueryBuilder()
            ->select('r.checkKey, COUNT(r.id) as count')
            ->from(CheckResult::class, 'r')
            ->where('r.createdAt >= :since')
            ->andWhere('r.success = false')
            ->groupBy('r.checkKey')
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();

        $formattedIncidents = [];
        foreach ($incidents as $row) {
            $formattedIncidents[$row['checkKey']] = (int) $row['count'];
        }

        return new JsonResponse([
            'last_24h' => [
                'total_runs' => $totalRuns,
                'failed_runs' => $failedRuns,
                'success_rate' => $totalRuns > 0 ? round((($totalRuns - $failedRuns) / $totalRuns) * 100, 1) : 100,
                'avg_response_times' => $formattedAvgTime,
                'incidents' => $formattedIncidents
            ]
        ]);
    }
}
