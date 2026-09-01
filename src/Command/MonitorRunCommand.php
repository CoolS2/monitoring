<?php

namespace App\Command;

use App\Alert\TelegramNotifier;
use App\Checker\CheckerInterface;
use App\Checker\CheckOutcome;
use App\Entity\CheckError;
use App\Entity\CheckResult;
use App\Entity\LLMAnalysis;
use App\Entity\Notification;
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
    public function __construct(
        #[TaggedIterator('app.checker')]
        private iterable $checkers,
        private MonitorScheduler $scheduler,
        private TelegramNotifier $notifier,
        private LLMAnalyzer $llmAnalyzer,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $monitorLogger, // custom monitor log channel
        #[Autowire(env: 'int:NOTIFICATION_COOLDOWN')]
        private int $cooldownMinutes = 60
    ) {
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
            $key  = (string) $key;
            $type = (string) ($checkConfig['type'] ?? '');

            $checker = $this->findChecker($type);

            if (!$checker) {
                $errorMsg = sprintf('Unsupported checker type: "%s"', $type);
                $this->monitorLogger->error($errorMsg, ['check_key' => $key]);
                $output->writeln(sprintf('<error>Error: %s</error>', $errorMsg));
                continue;
            }

            try {
                $outcome = $checker->check($checkConfig);
            } catch (\Throwable $e) {
                // A checker that blows up is itself a monitoring signal: record it
                // as a failed run instead of silently skipping the check.
                $this->monitorLogger->error('Exception occurred during check execution', [
                    'check_key' => $key,
                    'exception' => $e->getMessage(),
                ]);
                $output->writeln(sprintf('<error>Exception for %s: %s</error>', $key, $e->getMessage()));

                $outcome = new CheckOutcome(false, sprintf('Checker crashed: %s', $e->getMessage()));
            }

            try {
                // Handle the check lifecycle and state machine
                $this->processCheckOutcome($key, $type, $outcome);

                // Flush per check so a later failure cannot discard earlier results.
                $this->entityManager->flush();
            } catch (\Throwable $e) {
                $this->monitorLogger->error('Failed to persist check outcome', [
                    'check_key' => $key,
                    'exception' => $e->getMessage(),
                ]);
                $output->writeln(sprintf('<error>Persistence error for %s: %s</error>', $key, $e->getMessage()));
            }
        }

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
        // 1. Persist the new check run result
        $this->entityManager->persist(new CheckResult(
            $key,
            $type,
            $outcome->success,
            $outcome->message,
            $outcome->responseTime,
            $outcome->extra
        ));

        $errorRepo = $this->entityManager->getRepository(CheckError::class);

        // 2. State transition logic, driven by the open incident rather than by
        //    the previous run: an incident stays open until the check recovers.
        if (!$outcome->success) {
            $this->monitorLogger->warning(sprintf('Check failed: %s - %s', $key, $outcome->message), [
                'type'    => $type,
                'latency' => $outcome->responseTime,
            ]);

            /** @var CheckError|null $activeError */
            $activeError = $errorRepo->findOneBy(['checkKey' => $key, 'resolvedAt' => null], ['createdAt' => 'DESC']);

            $isNewOutage = $activeError === null;

            if ($isNewOutage) {
                // TRANSITION: SUCCESS -> FAILURE (new outage)
                $activeError = new CheckError($key, $outcome->message, $this->extractDetails($outcome));
                $this->entityManager->persist($activeError);
            }

            // A brand new outage is always announced immediately; the cooldown
            // only throttles repeated reminders about an incident already open.
            if (!$isNewOutage && !$this->cooldownElapsed($key)) {
                return;
            }

            $llmResult = $this->llmAnalyzer->analyze($key, $type, $outcome->message, $this->extractDetails($outcome));

            if ($llmResult['success']) {
                $this->entityManager->persist(new LLMAnalysis(
                    $activeError,
                    $llmResult['prompt'],
                    $llmResult['raw_response'],
                    $llmResult['summary'],
                    $llmResult['probable_cause'],
                    $llmResult['severity'],
                    $llmResult['recommendations']
                ));
            }

            $this->notifier->sendAlert(
                $key,
                $type,
                $outcome->message,
                $outcome->responseTime,
                $llmResult
            );

            $this->entityManager->persist(new Notification($key, 'error', $outcome->message));

            return;
        }

        // STATE: Success
        /** @var list<CheckError> $openErrors */
        $openErrors = $errorRepo->findBy(['checkKey' => $key, 'resolvedAt' => null], ['createdAt' => 'ASC']);

        if (empty($openErrors)) {
            // TRANSITION: SUCCESS -> SUCCESS (steady state)
            $this->monitorLogger->debug(sprintf('Check healthy: %s', $key));
            return;
        }

        // TRANSITION: FAILURE -> SUCCESS (service recovered)
        $this->monitorLogger->info(sprintf('Check recovered: %s', $key));

        $downtimeMinutes = round((time() - $openErrors[0]->getCreatedAt()->getTimestamp()) / 60, 1);

        foreach ($openErrors as $openError) {
            $openError->resolve();
        }

        $this->notifier->sendRecovery($key, $type, $downtimeMinutes);
        $this->entityManager->persist(new Notification($key, 'recovery', 'OK'));
    }

    /**
     * Extracts the richest context the checker produced, for the LLM and for the
     * incident record.
     */
    private function extractDetails(CheckOutcome $outcome): ?string
    {
        $extra = $outcome->extra;

        if (!empty($extra['output']) && is_string($extra['output'])) {
            return $extra['output'];
        }

        if (!empty($extra['matched_lines']) && is_array($extra['matched_lines'])) {
            return implode("\n", $extra['matched_lines']);
        }

        if (!empty($extra['problematic'])) {
            return json_encode($extra['problematic'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }

        if (!empty($extra['body_preview']) && is_string($extra['body_preview'])) {
            return $extra['body_preview'];
        }

        return null;
    }

    /**
     * True when enough time has passed since the last failure notification for
     * this check to send a reminder.
     */
    private function cooldownElapsed(string $key): bool
    {
        if ($this->cooldownMinutes <= 0) {
            return true;
        }

        $lastNotification = $this->entityManager->getRepository(Notification::class)->findOneBy(
            ['checkKey' => $key, 'type' => 'error'],
            ['sentAt' => 'DESC']
        );

        if (!$lastNotification) {
            return true;
        }

        return (time() - $lastNotification->getSentAt()->getTimestamp()) >= $this->cooldownMinutes * 60;
    }
}
