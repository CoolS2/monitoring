<?php

namespace App\Command;

use App\Entity\CheckResult;
use App\Entity\Notification;
use App\Alert\TelegramNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:monitor:daily-summary',
    description: 'Generates and sends the daily monitoring report summary to Telegram'
)]
class DailySummaryCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TelegramNotifier $notifier
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $since = new \DateTimeImmutable('-24 hours');

        // Query total check runs in the last 24 hours
        $totalRuns = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(CheckResult::class, 'r')
            ->where('r.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        if ($totalRuns === 0) {
            $output->writeln('No monitoring runs recorded in the last 24 hours. Skipping summary.');
            return Command::SUCCESS;
        }

        // Query successful runs
        $successRuns = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(CheckResult::class, 'r')
            ->where('r.createdAt >= :since')
            ->andWhere('r.success = true')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        $failedRuns = $totalRuns - $successRuns;
        $successRate = (int) round(($successRuns / $totalRuns) * 100);

        // Query check keys that failed in the last 24 hours and their failure counts
        $failedRows = $this->entityManager->createQueryBuilder()
            ->select('r.checkKey, COUNT(r.id) as failCount')
            ->from(CheckResult::class, 'r')
            ->where('r.createdAt >= :since')
            ->andWhere('r.success = false')
            ->groupBy('r.checkKey')
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();

        $failedKeys = [];
        foreach ($failedRows as $row) {
            $failedKeys[$row['checkKey']] = (int) $row['failCount'];
        }

        $stats = [
            'total_runs' => $totalRuns,
            'success_runs' => $successRuns,
            'failed_runs' => $failedRuns,
            'success_rate' => $successRate,
            'failed_keys' => $failedKeys
        ];

        // Send Daily summary report
        $this->notifier->sendDailySummary($stats);

        // Record notification in the database history
        $notification = new Notification('system', 'daily_summary', sprintf('Total runs: %d, Success rate: %d%%', $totalRuns, $successRate));
        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        $output->writeln('Daily summary report compiled and sent.');
        return Command::SUCCESS;
    }
}
