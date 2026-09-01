<?php

namespace App\Tests\Command;

use App\Alert\TelegramNotifier;
use App\Checker\CheckerInterface;
use App\Checker\CheckOutcome;
use App\Command\MonitorRunCommand;
use App\Entity\CheckError;
use App\Entity\Notification;
use App\Service\LLMAnalyzer;
use App\Service\MonitorScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class MonitorRunCommandTest extends TestCase
{
    /**
     * A newly detected outage must be announced immediately, even when the
     * previous incident for the same check was alerted moments ago. Throttling
     * only applies to reminders about an incident that is still open.
     */
    public function testNewOutageAlertsDespiteRecentNotification(): void
    {
        $notifier = $this->createMock(TelegramNotifier::class);
        $notifier->expects($this->once())->method('sendAlert');

        $this->runCommandWith(
            new CheckOutcome(false, 'HTTP status code 500 (expected 200)'),
            activeError: null,                                  // no incident open -> new outage
            lastErrorNotificationAge: 30,                       // alerted 30s ago
            notifier: $notifier
        );
    }

    /**
     * While an incident stays open, repeated alerts are throttled by the cooldown.
     */
    public function testOngoingOutageIsThrottled(): void
    {
        $notifier = $this->createMock(TelegramNotifier::class);
        $notifier->expects($this->never())->method('sendAlert');

        $this->runCommandWith(
            new CheckOutcome(false, 'HTTP status code 500 (expected 200)'),
            activeError: new CheckError('website', 'HTTP status code 500 (expected 200)'),
            lastErrorNotificationAge: 30,                       // inside the 60 min cooldown
            notifier: $notifier
        );
    }

    /**
     * Once the cooldown has elapsed the still-open incident is re-announced.
     */
    public function testOngoingOutageAlertsAgainAfterCooldown(): void
    {
        $notifier = $this->createMock(TelegramNotifier::class);
        $notifier->expects($this->once())->method('sendAlert');

        $this->runCommandWith(
            new CheckOutcome(false, 'HTTP status code 500 (expected 200)'),
            activeError: new CheckError('website', 'HTTP status code 500 (expected 200)'),
            lastErrorNotificationAge: 3700,                     // older than 60 min
            notifier: $notifier
        );
    }

    /**
     * Recovery is driven by the open incident, not by the previous run.
     */
    public function testRecoveryResolvesTheOpenIncident(): void
    {
        $activeError = new CheckError('website', 'HTTP status code 500 (expected 200)');

        $notifier = $this->createMock(TelegramNotifier::class);
        $notifier->expects($this->once())->method('sendRecovery');

        $this->runCommandWith(
            new CheckOutcome(true, 'OK'),
            activeError: $activeError,
            lastErrorNotificationAge: 30,
            notifier: $notifier
        );

        $this->assertNotNull($activeError->getResolvedAt(), 'The incident must be closed on recovery');
    }

    public function testHealthyCheckSendsNothing(): void
    {
        $notifier = $this->createMock(TelegramNotifier::class);
        $notifier->expects($this->never())->method('sendAlert');
        $notifier->expects($this->never())->method('sendRecovery');

        $this->runCommandWith(
            new CheckOutcome(true, 'OK'),
            activeError: null,
            lastErrorNotificationAge: null,
            notifier: $notifier
        );
    }

    /**
     * A checker that throws is recorded as a failed run rather than skipped.
     */
    public function testCrashingCheckerStillRaisesAnAlert(): void
    {
        $notifier = $this->createMock(TelegramNotifier::class);
        $notifier->expects($this->once())
            ->method('sendAlert')
            ->with('website', 'http', $this->stringContains('Checker crashed: boom'));

        $this->runCommandWith(
            outcome: null,
            activeError: null,
            lastErrorNotificationAge: null,
            notifier: $notifier,
            throw: new \RuntimeException('boom')
        );
    }

    /**
     * Wires the command with doubles and executes a single due check.
     *
     * @param CheckOutcome|null $outcome                  Outcome the checker returns (null when $throw is given)
     * @param CheckError|null   $activeError              Open incident the repository reports, if any
     * @param int|null          $lastErrorNotificationAge Seconds since the last error notification, null for none
     */
    private function runCommandWith(
        ?CheckOutcome $outcome,
        ?CheckError $activeError,
        ?int $lastErrorNotificationAge,
        TelegramNotifier $notifier,
        ?\Throwable $throw = null,
    ): void {
        $checker = new class ($outcome, $throw) implements CheckerInterface {
            public function __construct(private ?CheckOutcome $outcome, private ?\Throwable $throw) {}

            public function supports(string $type): bool
            {
                return $type === 'http';
            }

            public function check(array $config): CheckOutcome
            {
                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return $this->outcome;
            }
        };

        $scheduler = $this->createMock(MonitorScheduler::class);
        $scheduler->method('getDueChecks')->willReturn([
            'website' => ['type' => 'http', 'url' => 'https://example.com'],
        ]);

        $errorRepo = $this->createMock(EntityRepository::class);
        $errorRepo->method('findOneBy')->willReturn($activeError);
        $errorRepo->method('findBy')->willReturn($activeError !== null ? [$activeError] : []);

        $notificationRepo = $this->createMock(EntityRepository::class);
        $notificationRepo->method('findOneBy')->willReturn(
            $lastErrorNotificationAge === null
                ? null
                : $this->notificationSentSecondsAgo($lastErrorNotificationAge)
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            fn (string $class) => $class === CheckError::class ? $errorRepo : $notificationRepo
        );

        $llmAnalyzer = $this->createMock(LLMAnalyzer::class);
        $llmAnalyzer->method('analyze')->willReturn([
            'success'         => false,
            'prompt'          => 'p',
            'raw_response'    => 'r',
            'summary'         => 's',
            'probable_cause'  => 'c',
            'severity'        => 'MEDIUM',
            'recommendations' => [],
        ]);

        $command = new MonitorRunCommand(
            [$checker],
            $scheduler,
            $notifier,
            $llmAnalyzer,
            $entityManager,
            new NullLogger(),
            60
        );

        $command->run(new ArrayInput([]), new NullOutput());
    }

    /**
     * Builds a Notification whose sentAt lies the given number of seconds in the past.
     */
    private function notificationSentSecondsAgo(int $seconds): Notification
    {
        $notification = new Notification('website', 'error', 'previous alert');

        $property = new \ReflectionProperty(Notification::class, 'sentAt');
        $property->setValue($notification, new \DateTimeImmutable(sprintf('-%d seconds', $seconds)));

        return $notification;
    }
}
