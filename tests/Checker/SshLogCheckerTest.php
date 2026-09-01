<?php

namespace App\Tests\Checker;

use App\Checker\SshLogChecker;
use App\Service\CommandResult;
use App\Service\SshExecutor;
use PHPUnit\Framework\TestCase;

class SshLogCheckerTest extends TestCase
{
    /**
     * grep exits with status 1 when nothing matched. That is the healthy case
     * and must never be reported as a broken SSH connection.
     */
    public function testGrepWithoutMatchesIsSuccess(): void
    {
        $executor = $this->createMock(SshExecutor::class);
        $executor->expects($this->once())
            ->method('run')
            ->willReturn(new CommandResult(1, ''));

        $checker = new SshLogChecker($executor);

        $outcome = $checker->check([
            'host' => '10.0.0.1',
            'file' => '/var/log/nginx/error.log',
            'grep' => 'error|crit',
        ]);

        $this->assertTrue($outcome->success, $outcome->message);
        $this->assertStringContainsString('No matching log lines', $outcome->message);
        $this->assertSame([], $outcome->extra['matched_lines']);
    }

    public function testGrepWithMatchesFails(): void
    {
        $executor = $this->createMock(SshExecutor::class);
        $executor->method('run')->willReturn(
            new CommandResult(0, "line one error\nline two crit\n")
        );

        $checker = new SshLogChecker($executor);

        $outcome = $checker->check([
            'host' => '10.0.0.1',
            'file' => '/var/log/nginx/error.log',
            'grep' => 'error|crit',
        ]);

        $this->assertFalse($outcome->success);
        $this->assertSame(2, $outcome->extra['count']);
        $this->assertSame(
            ['[10.0.0.1] line one error', '[10.0.0.1] line two crit'],
            $outcome->extra['matched_lines']
        );
    }

    public function testUnreachableHostFails(): void
    {
        $executor = $this->createMock(SshExecutor::class);
        $executor->method('run')->willReturn(
            CommandResult::transportFailure('SSH connection to 10.0.0.1 failed: timed out')
        );

        $checker = new SshLogChecker($executor);

        $outcome = $checker->check([
            'host' => '10.0.0.1',
            'file' => '/var/log/nginx/error.log',
            'grep' => 'error',
        ]);

        $this->assertFalse($outcome->success);
        $this->assertStringContainsString('timed out', $outcome->message);
    }

    public function testMissingLogFileIsReportedAsSuch(): void
    {
        $executor = $this->createMock(SshExecutor::class);
        $executor->method('run')->willReturn(new CommandResult(3, ''));

        $checker = new SshLogChecker($executor);

        $outcome = $checker->check([
            'host' => '10.0.0.1',
            'file' => '/var/log/nginx/missing.log',
            'grep' => 'error',
        ]);

        $this->assertFalse($outcome->success);
        $this->assertStringContainsString('missing or not readable', $outcome->message);
    }

    /**
     * One dead host among several must not hide the healthy ones, but it still
     * has to surface as a failure.
     */
    public function testPartialTargetFailureIsReported(): void
    {
        $executor = $this->createMock(SshExecutor::class);
        $executor->method('run')->willReturnOnConsecutiveCalls(
            new CommandResult(1, ''), // host A: no matches
            CommandResult::transportFailure('SSH connection to b failed: refused')
        );

        $checker = new SshLogChecker($executor);

        $outcome = $checker->check([
            'grep'    => 'error',
            'targets' => [
                ['host' => 'a', 'file' => '/var/log/syslog'],
                ['host' => 'b', 'file' => '/var/log/syslog'],
            ],
        ]);

        $this->assertFalse($outcome->success);
        $this->assertStringContainsString('No matching log lines', $outcome->message);
        $this->assertStringContainsString('refused', $outcome->message);
        $this->assertCount(1, $outcome->extra['failed_targets']);
    }

    public function testMissingConfigurationFails(): void
    {
        $checker = new SshLogChecker($this->createMock(SshExecutor::class));

        $outcome = $checker->check(['grep' => 'error']);

        $this->assertFalse($outcome->success);
        $this->assertStringContainsString('Missing host or file path', $outcome->message);
    }
}
