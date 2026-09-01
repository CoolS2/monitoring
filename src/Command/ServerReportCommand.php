<?php

namespace App\Command;

use App\Alert\TelegramNotifier;
use App\Checker\CheckerInterface;
use App\Entity\Notification;
use App\Service\LLMAnalyzer;
use App\Service\MonitorScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Runs the configured server report scripts on demand, has the LLM condense
 * their output, and posts a short digest to Telegram.
 *
 * Unlike `app:monitor:run` this ignores schedules and cooldowns and reports
 * even when everything is healthy — it answers "how is the server doing right
 * now?" rather than "did something just break?".
 */
#[AsCommand(
    name: 'app:monitor:report',
    description: 'Runs server report scripts and sends a short LLM summary to Telegram'
)]
class ServerReportCommand extends Command
{
    /** Check types this command collects reports from. */
    private const REPORT_TYPES = ['script', 'command'];

    public function __construct(
        #[TaggedIterator('app.checker')]
        private iterable $checkers,
        private MonitorScheduler $scheduler,
        private TelegramNotifier $notifier,
        private LLMAnalyzer $llmAnalyzer,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $monitorLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Only run the report for this check key')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the report to the console instead of sending it to Telegram');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $onlyKey = $input->getOption('key');
        $dryRun  = (bool) $input->getOption('dry-run');

        $checks = $this->collectReportChecks(is_string($onlyKey) ? $onlyKey : null);

        if (empty($checks)) {
            $output->writeln($onlyKey !== null
                ? sprintf('<comment>No report check named "%s" found in config/monitors.yaml.</comment>', $onlyKey)
                : '<comment>No checks of type "script" configured — nothing to report.</comment>');

            return Command::SUCCESS;
        }

        foreach ($checks as $key => $config) {
            $this->reportOne((string) $key, $config, $output, $dryRun);
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function reportOne(string $key, array $config, OutputInterface $output, bool $dryRun): void
    {
        $type    = (string) ($config['type'] ?? 'script');
        $checker = $this->findChecker($type);

        if (!$checker) {
            $output->writeln(sprintf('<error>No checker registered for type "%s" (check "%s").</error>', $type, $key));
            return;
        }

        $output->writeln(sprintf('Collecting report for <info>%s</info>...', $key));

        try {
            $outcome = $checker->check($config);
        } catch (\Throwable $e) {
            $this->monitorLogger->error('Server report collection failed', [
                'check_key' => $key,
                'exception' => $e->getMessage(),
            ]);
            $output->writeln(sprintf('<error>Failed to collect %s: %s</error>', $key, $e->getMessage()));

            return;
        }

        $report      = (string) ($outcome->extra['output'] ?? $outcome->message);
        $statuses    = is_array($outcome->extra['statuses'] ?? null) ? $outcome->extra['statuses'] : [];
        $worstStatus = (string) ($outcome->extra['worst_status'] ?? ($outcome->success ? 'OK' : 'WARN'));

        if ($dryRun) {
            $output->writeln(sprintf("\n--- %s (%s) ---\n%s\n", $key, $worstStatus, $report));
            return;
        }


        $llmResult = $this->llmAnalyzer->analyze($key, $type, $outcome->message, $report);

        $findings = is_array($outcome->extra['findings'] ?? null) ? $outcome->extra['findings'] : [];

        $this->notifier->sendServerReport($key, $worstStatus, $statuses, $findings, $llmResult);

        $this->entityManager->persist(new Notification(
            $key,
            'report',
            sprintf('Server report sent (%s)', $worstStatus)
        ));

        $output->writeln(sprintf('Report for <info>%s</info> sent (status: %s).', $key, $worstStatus));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function collectReportChecks(?string $onlyKey): array
    {
        $checks = $this->scheduler->getConfiguration()['checks'] ?? [];

        $selected = [];
        foreach ($checks as $key => $config) {
            if (!is_array($config)) {
                continue;
            }

            if ($onlyKey !== null) {
                if ((string) $key === $onlyKey) {
                    $selected[(string) $key] = $config;
                }
                continue;
            }

            if (in_array((string) ($config['type'] ?? ''), self::REPORT_TYPES, true)) {
                $selected[(string) $key] = $config;
            }
        }

        return $selected;
    }

    private function findChecker(string $type): ?CheckerInterface
    {
        foreach ($this->checkers as $checker) {
            if ($checker->supports($type)) {
                return $checker;
            }
        }

        return null;
    }
}
