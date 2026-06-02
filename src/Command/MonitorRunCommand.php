<?php

namespace App\Command;

use App\Checker\CheckerInterface;
use App\Checker\CheckOutcome;
use App\Entity\CheckError;
use App\Entity\CheckResult;
use App\Entity\LLMAnalysis;
use App\Entity\Notification;
use App\Alert\TelegramNotifier;
use App\Service\LLMAnalyzer;
use App\Service\MonitorScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

#[AsCommand(
    name: 'app:monitor:run',
    description: 'Runs scheduled monitor checks'
)]
class MonitorRunCommand extends Command
{
    private iterable $checkers;

    public function __construct(
        #[TaggedIterator('app.checker')]
        iterable $checkers,
        private MonitorScheduler $scheduler,
        private TelegramNotifier $notifier,
        private LLMAnalyzer $llmAnalyzer,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $monitorLogger, // custom monitor log channel
        #[Autowire(env: 'int:NOTIFICATION_COOLDOWN')]
        private int $cooldownMinutes = 60
    ) {
        $this->checkers = $checkers;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dueChecks = $this->scheduler->getDueChecks();
        if (empty($dueChecks)) {
            $output->writeln('No checks are due.');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Running %d due checks...', count($dueChecks)));

        foreach ($dueChecks as $key => $checkConfig) {
            $type = $checkConfig['type'] ?? '';
            $checker = $this->findChecker($type);

            if (!$checker) {
                $errorMsg = sprintf('Unsupported checker type: "%s"', $type);
                $this->monitorLogger->error($errorMsg, ['check_key' => $key]);
                $output->writeln(sprintf('<error>Error: %s</error>', $errorMsg));
                continue;
            }

            try {
                // Execute the check
                $outcome = $checker->check($checkConfig);

                // Handle the check lifecycle and state machine
                $this->processCheckOutcome($key, $type, $outcome);

            } catch (\Throwable $e) {
                $this->monitorLogger->error('Exception occurred during check execution', [
                    'check_key' => $key,
                    'exception' => $e->getMessage()
                ]);
                $output->writeln(sprintf('<error>Exception for %s: %s</error>', $key, $e->getMessage()));
            }
        }

        // Flush all database changes (results, errors, notifications, analyses) at the end
        $this->entityManager->flush();

        $output->writeln('Monitoring check completed successfully.');
        return Command::SUCCESS;
    }

    /**
     * Locates a checker service supporting the specific type.
     */
    private function findChecker(string $type): ?CheckerInterface
    {
        foreach ($this->checkers as $checker) {
            if ($checker->supports($type)) {
                return $checker;
            }
        }
        return null;
    }

    /**
     * Processes check execution outcome and manages state transitions.
     */
    private function processCheckOutcome(string $key, string $type, CheckOutcome $outcome): void
    {
        // 1. Fetch previous run state from the database
        $resultRepo = $this->entityManager->getRepository(CheckResult::class);
        $lastResult = $resultRepo->findOneBy(
            ['checkKey' => $key],
            ['createdAt' => 'DESC']
        );

        $wasSuccess = $lastResult ? $lastResult->isSuccess() : true;

        // 2. Persist the new check run result
        $newResult = new CheckResult(
            $key,
            $type,
            $outcome->success,
            $outcome->message,
            $outcome->responseTime,
            $outcome->extra
        );
        $this->entityManager->persist($newResult);

        // Extract extra log/details context for LLM if available
        $details = null;
        if (isset($outcome->extra['matched_lines'])) {
            $details = implode("\n", $outcome->extra['matched_lines']);
        } elseif (isset($outcome->extra['problematic'])) {
            $details = json_encode($outcome->extra['problematic'], JSON_PRETTY_PRINT);
        } elseif (isset($outcome->extra['body_preview'])) {
            $details = $outcome->extra['body_preview'];
        }

        // 3. State transition logic
        if (!$outcome->success) {
            // STATE: Failure
            $this->monitorLogger->warning(sprintf('Check failed: %s - %s', $key, $outcome->message), [
                'type' => $type,
                'latency' => $outcome->responseTime
            ]);

            // Find or create active error record
            $errorRepo = $this->entityManager->getRepository(CheckError::class);
            $activeError = $errorRepo->findOneBy([
                'checkKey' => $key,
                'resolvedAt' => null
            ]);

            if (!$activeError) {
                // TRANSITION: SUCCESS -> FAILURE (New Outage)
                $activeError = new CheckError($key, $outcome->message, $details);
                $this->entityManager->persist($activeError);
            }

            // Determine if we should send a Telegram notification (handling cooldown)
            if ($this->shouldSendAlertNotification($key)) {
                // Execute LLM Analysis for this failure
                $llmResult = $this->llmAnalyzer->analyze($key, $type, $outcome->message, $details);

                if ($llmResult['success']) {
                    $analysis = new LLMAnalysis(
                        $activeError,
                        $llmResult['prompt'],
                        $llmResult['raw_response'],
                        $llmResult['summary'],
                        $llmResult['probable_cause'],
                        $llmResult['severity'],
                        $llmResult['recommendations']
                    );
                    $this->entityManager->persist($analysis);
                }

                // Send Alert message on Telegram
                $this->notifier->sendAlert(
                    $key,
                    $type,
                    $outcome->message,
                    $outcome->responseTime,
                    $llmResult['success'] ? $llmResult : null
                );

                // Log the notification in history
                $notification = new Notification($key, 'error', $outcome->message);
                $this->entityManager->persist($notification);
            }

        } else {
            // STATE: Success
            if (!$wasSuccess) {
                // TRANSITION: FAILURE -> SUCCESS (Service Recovered!)
                $this->monitorLogger->info(sprintf('Check recovered: %s', $key));

                $errorRepo = $this->entityManager->getRepository(CheckError::class);
                /** @var CheckError|null $activeError */
                $activeError = $errorRepo->findOneBy([
                    'checkKey' => $key,
                    'resolvedAt' => null
                ]);

                $downtimeMinutes = null;
                if ($activeError) {
                    $activeError->resolve();
                    $downtimeSeconds = time() - $activeError->getCreatedAt()->getTimestamp();
                    $downtimeMinutes = round($downtimeSeconds / 60, 1);
                }

                // Send recovery message on Telegram
                $this->notifier->sendRecovery($key, $type, $downtimeMinutes);

                // Log notification
                $notification = new Notification($key, 'recovery', 'OK');
                $this->entityManager->persist($notification);
            } else {
                // TRANSITION: SUCCESS -> SUCCESS (Steady State)
                $this->monitorLogger->debug(sprintf('Check healthy: %s', $key));
            }
        }
    }

    /**
     * Checks if a failure notification is due to be sent based on cooldown parameters.
     */
    private function shouldSendAlertNotification(string $key): bool
    {
        $notificationRepo = $this->entityManager->getRepository(Notification::class);
        $lastNotification = $notificationRepo->findOneBy(
            ['checkKey' => $key, 'type' => 'error'],
            ['sentAt' => 'DESC']
        );

        if (!$lastNotification) {
            return true;
        }

        $elapsedSeconds = time() - $lastNotification->getSentAt()->getTimestamp();
        $cooldownSeconds = $this->cooldownMinutes * 60;

        return $elapsedSeconds >= $cooldownSeconds;
    }
}
