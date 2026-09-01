<?php

namespace App\Service;

use App\Entity\CheckResult;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class MonitorScheduler
{
    private string $configPath;

    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%/config/monitors.yaml')]
        string $configPath,
        private LoggerInterface $monitorLogger = new NullLogger(),
    ) {
        $this->configPath = $configPath;
    }

    /**
     * Loads the monitors configuration.
     */
    public function getConfiguration(): array
    {
        if (!file_exists($this->configPath)) {
            return ['checks' => []];
        }

        try {
            $config = Yaml::parseFile($this->configPath);
        } catch (ParseException $e) {
            // A broken monitors.yaml must not take the whole cron run down.
            $this->monitorLogger->error('Failed to parse monitors configuration.', [
                'path'  => $this->configPath,
                'error' => $e->getMessage(),
            ]);

            return ['checks' => []];
        }

        if (!is_array($config) || !isset($config['checks']) || !is_array($config['checks'])) {
            return ['checks' => []];
        }

        return $config;
    }

    /**
     * Filters and returns the list of checks that are due to run.
     * Returns an array of [check_key => check_configuration].
     */
    public function getDueChecks(): array
    {
        $config = $this->getConfiguration();
        $checks = $config['checks'] ?? [];
        if (empty($checks)) {
            return [];
        }

        // Query the latest check result timestamp for each check key in a single query
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('r.checkKey, MAX(r.createdAt) as lastRun')
           ->from(CheckResult::class, 'r')
           ->groupBy('r.checkKey');

        $lastRuns = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            $lastRun = $this->toTimestamp($row['lastRun'] ?? null);
            if ($lastRun !== null) {
                $lastRuns[(string) $row['checkKey']] = $lastRun;
            }
        }

        $dueChecks = [];
        $now = time();

        foreach ($checks as $key => $check) {
            $key = (string) $key;

            if (!is_array($check)) {
                continue; // malformed entry in monitors.yaml
            }

            // A non-positive interval would make the check run on every tick;
            // one second is the tightest cadence we honour.
            $interval = max(1, (int) ($check['interval'] ?? 60));

            if (!isset($lastRuns[$key]) || ($now - $lastRuns[$key]) >= $interval) {
                $dueChecks[$key] = $check;
            }
        }

        return $dueChecks;
    }

    /**
     * Normalises the value a MAX(datetime) aggregate returns. Depending on the
     * DBAL driver this is either a formatted string or a DateTime instance.
     */
    private function toTimestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_string($value) && $value !== '') {
            try {
                return (new \DateTimeImmutable($value))->getTimestamp();
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
