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

        // One pass per check key: how often it ran, how often it was healthy,
        // and how slow it was. The totals are derived from these rows so the
        // headline figure and the per-check lines can never disagree.
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'r.checkKey AS checkKey',
                'COUNT(r.id) AS total',
                'SUM(CASE WHEN r.success = true THEN 1 ELSE 0 END) AS okCount',
                'AVG(r.responseTime) AS avgTime',
                'MAX(r.responseTime) AS maxTime'
            )
            ->from(CheckResult::class, 'r')
            ->where('r.createdAt >= :since')
            ->groupBy('r.checkKey')
            ->orderBy('r.checkKey', 'ASC')
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();

        $totalRuns   = 0;
        $successRuns = 0;
        $checks      = [];
        $failedKeys  = [];

        foreach ($rows as $row) {
            $key   = (string) $row['checkKey'];
            $total = (int) $row['total'];
            $ok    = (int) $row['okCount'];

            $totalRuns   += $total;
            $successRuns += $ok;

            if ($ok < $total) {
                $failedKeys[$key] = $total - $ok;
            }

            $checks[] = [
                'key'      => $key,
                'total'    => $total,
                'ok'       => $ok,
                'failed'   => $total - $ok,
                'uptime'   => $total > 0 ? round($ok * 100 / $total, 1) : 0.0,
                'avg_time' => $row['avgTime'] !== null ? round((float) $row['avgTime'], 2) : null,
                'max_time' => $row['maxTime'] !== null ? round((float) $row['maxTime'], 2) : null,
            ];
        }

        if ($totalRuns === 0) {
            $output->writeln('No monitoring runs recorded in the last 24 hours. Skipping summary.');
            return Command::SUCCESS;
        }

        $failedRuns  = $totalRuns - $successRuns;
        $successRate = (int) round(($successRuns / $totalRuns) * 100);

        $stats = [
            'total_runs'   => $totalRuns,
            'success_runs' => $successRuns,
            'failed_runs'  => $failedRuns,
            'success_rate' => $successRate,
            'failed_keys'  => $failedKeys,
            'checks'       => $checks,
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
