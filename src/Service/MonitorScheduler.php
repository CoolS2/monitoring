<?php

namespace App\Service;

use App\Entity\CheckResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

class MonitorScheduler
{
    private string $configPath;

    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%/config/monitors.yaml')]
        string $configPath
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

        $config = Yaml::parseFile($this->configPath);
        return is_array($config) ? $config : ['checks' => []];
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

        $rows = $qb->getQuery()->getResult();
        $lastRuns = [];
        foreach ($rows as $row) {
            $lastRuns[$row['checkKey']] = new \DateTimeImmutable($row['lastRun']);
        }

        $dueChecks = [];
        $now = new \DateTimeImmutable();

        foreach ($checks as $key => $check) {
            $interval = (int) ($check['interval'] ?? 60); // default to 60s

            if (!isset($lastRuns[$key])) {
                // Never run before, run it now
                $dueChecks[$key] = $check;
                continue;
            }

            $lastRun = $lastRuns[$key];
            $diff = $now->getTimestamp() - $lastRun->getTimestamp();

            if ($diff >= $interval) {
                $dueChecks[$key] = $check;
            }
        }

        return $dueChecks;
    }
}
