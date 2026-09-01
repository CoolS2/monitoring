<?php

namespace App\Tests\Checker;

use App\Checker\ScriptChecker;
use App\Service\CommandResult;
use App\Service\LocalExecutor;
use App\Service\OutputRuleEvaluator;
use App\Service\SshExecutor;
use PHPUnit\Framework\TestCase;

class ScriptCheckerTest extends TestCase
{
    private const LOG_REPORT_WARN = <<<TXT
        === LOG MONITOR ===
        Last 1 hour: 21:14 -> 22:14
        STATUS: WARN

        --- NGINX ---
        PHP warnings: 1037
        Other errors: 0
        TOP:
            928 WP_Post could not be converted to int

        --- MYSQL ---
        OK
        === END ===
        TXT;

    private const LOG_REPORT_OK = "=== SYSTEM ===\nSTATUS: OK\nLoad: 0.4\n=== END ===";

    public function testWorstStatusAcrossSectionsDrivesTheOutcome(): void
    {
        $ssh = $this->createMock(SshExecutor::class);
        $ssh->expects($this->exactly(2))
            ->method('run')
            ->willReturnOnConsecutiveCalls(
                new CommandResult(0, self::LOG_REPORT_OK),
                new CommandResult(0, self::LOG_REPORT_WARN),
            );

        $checker = $this->checker($ssh, $this->createMock(LocalExecutor::class));

        $outcome = $checker->check([
            'host'     => '10.0.0.1',
            'commands' => [
                'SYSTEM' => '/usr/local/sbin/monitor-system',
                'LOGS'   => '/usr/local/sbin/monitor-logs',
            ],
        ]);

        $this->assertFalse($outcome->success);
        $this->assertSame('WARN', $outcome->extra['worst_status']);
        $this->assertSame(['SYSTEM' => 'OK', 'LOGS' => 'WARN'], $outcome->extra['statuses']);
        $this->assertStringContainsString('STATUS WARN on 10.0.0.1', $outcome->message);

        // The full report is what the LLM gets as context
        $this->assertStringContainsString('=== LOGS ===', $outcome->extra['output']);
        $this->assertStringContainsString('928 WP_Post', $outcome->extra['output']);
    }

    public function testAllSectionsOkIsSuccess(): void
    {
        $local = $this->createMock(LocalExecutor::class);
        $local->method('run')->willReturn(new CommandResult(0, self::LOG_REPORT_OK));

        $checker = $this->checker($this->createMock(SshExecutor::class), $local);

        $outcome = $checker->check(['command' => '/usr/local/sbin/monitor-system']);

        $this->assertTrue($outcome->success, $outcome->message);
        $this->assertSame('OK', $outcome->extra['worst_status']);
        $this->assertSame(['MONITOR-SYSTEM' => 'OK'], $outcome->extra['statuses']);
    }

    public function testNoHostRunsLocally(): void
    {
        $ssh = $this->createMock(SshExecutor::class);
        $ssh->expects($this->never())->method('run');

        $local = $this->createMock(LocalExecutor::class);
        $local->expects($this->once())->method('run')->willReturn(new CommandResult(0, 'STATUS: OK'));

        $checker = $this->checker($ssh, $local);
        $checker->check(['command' => 'uptime']);
    }

    public function testFailOnCanBeNarrowedToCritOnly(): void
    {
        $local = $this->createMock(LocalExecutor::class);
        $local->method('run')->willReturn(new CommandResult(0, self::LOG_REPORT_WARN));

        $checker = $this->checker($this->createMock(SshExecutor::class), $local);

        $outcome = $checker->check([
            'command' => '/usr/local/sbin/monitor-logs',
            'fail_on' => ['CRIT'],
        ]);

        $this->assertTrue($outcome->success, 'WARN must not alert when fail_on is CRIT only');
        $this->assertSame('WARN', $outcome->extra['worst_status']);
    }

    /**
     * A script with no STATUS banner is judged by its exit code alone.
     */
    public function testNonZeroExitWithoutStatusBannerFails(): void
    {
        $local = $this->createMock(LocalExecutor::class);
        $local->method('run')->willReturn(new CommandResult(127, '', 'monitor-pm2: not found'));

        $checker = $this->checker($this->createMock(SshExecutor::class), $local);

        $outcome = $checker->check(['command' => '/usr/local/sbin/monitor-pm2']);

        $this->assertFalse($outcome->success);
        $this->assertSame('ERROR', $outcome->extra['worst_status']);
        $this->assertStringContainsString('not found', $outcome->extra['output']);
    }

    public function testUnreachableHostIsCritical(): void
    {
        $ssh = $this->createMock(SshExecutor::class);
        $ssh->method('run')->willReturn(CommandResult::transportFailure('SSH connection to h failed: timed out'));

        $checker = $this->checker($ssh, $this->createMock(LocalExecutor::class));

        $outcome = $checker->check(['host' => 'h', 'command' => '/usr/local/sbin/monitor-logs']);

        $this->assertFalse($outcome->success);
        $this->assertSame('CRIT', $outcome->extra['worst_status']);
        $this->assertArrayHasKey('MONITOR-LOGS', $outcome->extra['failed_sections']);
    }

    public function testMissingCommandConfigurationFails(): void
    {
        $checker = $this->checker(
            $this->createMock(SshExecutor::class),
            $this->createMock(LocalExecutor::class)
        );

        $outcome = $checker->check(['host' => '10.0.0.1']);

        $this->assertFalse($outcome->success);
        $this->assertStringContainsString('Missing "command" or "commands"', $outcome->message);
    }

    public function testPlainCommandListDerivesLabels(): void
    {
        $local = $this->createMock(LocalExecutor::class);
        $local->method('run')->willReturn(new CommandResult(0, 'STATUS: OK'));

        $checker = $this->checker($this->createMock(SshExecutor::class), $local);

        $outcome = $checker->check([
            'commands' => [
                'sudo -u monitor sudo /usr/local/sbin/monitor-logs',
                'sudo -u monitor sudo /usr/local/sbin/monitor-nginx',
            ],
        ]);

        $this->assertSame(
            ['MONITOR-LOGS' => 'OK', 'MONITOR-NGINX' => 'OK'],
            $outcome->extra['statuses']
        );
    }

    /**
     * `monitor-system` never prints a STATUS line — it is graded by rules.
     */
    public function testRulesGradeAScriptWithoutStatusBanner(): void
    {
        $report = <<<TXT
            === UPTIME ===
             21:18:29 up 56 days,  7:14,  3 users,  load average: 0.27, 0.34, 0.35

            === DISK ===
            Filesystem      Size  Used Avail Use% Mounted on
            tmpfs           794M  1.1M  793M   1% /run
            /dev/sda1        97G   93G    3G  96% /
            TXT;

        $local = $this->createMock(LocalExecutor::class);
        $local->method('run')->willReturn(new CommandResult(0, $report));

        $outcome = $this->checker($this->createMock(SshExecutor::class), $local)->check([
            'commands' => [
                'SYSTEM' => [
                    'command' => '/usr/local/sbin/monitor-system',
                    'rules'   => [
                        [
                            'name'       => 'Disk usage /',
                            'match'      => '(\\d+)%\\s+/$',
                            'unit'       => '%',
                            'warn_above' => 80,
                            'crit_above' => 90,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($outcome->success);
        $this->assertSame('CRIT', $outcome->extra['worst_status']);
        $this->assertSame(['SYSTEM' => 'CRIT'], $outcome->extra['statuses']);
        $this->assertSame(['[SYSTEM] Disk usage /: 96% (above 90%)'], $outcome->extra['findings']);
        $this->assertStringContainsString('Disk usage /', $outcome->message);

        // The findings lead the report so the model weighs them first
        $this->assertStringStartsWith('=== DETECTED ISSUES ===', $outcome->extra['output']);
    }

    /**
     * A rule may only escalate a section, never talk it down.
     */
    public function testRuleEscalatesASelfDeclaredStatus(): void
    {
        $local = $this->createMock(LocalExecutor::class);
        $local->method('run')->willReturn(new CommandResult(0, "STATUS: WARN\nECONNREFUSED redis"));

        $outcome = $this->checker($this->createMock(SshExecutor::class), $local)->check([
            'commands' => [
                'LOGS' => [
                    'command' => '/usr/local/sbin/monitor-logs',
                    'rules'   => [['name' => 'Fatal', 'match' => 'ECONNREFUSED', 'crit_above' => 0]],
                ],
            ],
        ]);

        $this->assertSame('CRIT', $outcome->extra['worst_status']);
    }

    /**
     * `max_lines` shrinks a noisy log dump in the report, but the rules must
     * still be evaluated against the complete output.
     */
    public function testMaxLinesTrimsTheReportButNotTheRules(): void
    {
        $lines = [];
        for ($i = 1; $i <= 60; $i++) {
            $lines[] = sprintf('1|worldvie | line %d ECONNREFUSED', $i);
        }

        $local = $this->createMock(LocalExecutor::class);
        $local->method('run')->willReturn(new CommandResult(0, implode("\n", $lines)));

        $outcome = $this->checker($this->createMock(SshExecutor::class), $local)->check([
            'commands' => [
                'PM2_LOGS' => [
                    'command'   => '/usr/local/sbin/monitor-pm2-logs',
                    'max_lines' => 10,
                    'rules'     => [['name' => 'Fatal', 'match' => 'ECONNREFUSED', 'crit_above' => 50]],
                ],
            ],
        ]);

        // 60 matches counted, even though only 10 lines are kept
        $this->assertSame('CRIT', $outcome->extra['worst_status']);
        $this->assertSame(['[PM2_LOGS] Fatal: 60 matching line(s) (above 50)'], $outcome->extra['findings']);

        $this->assertStringContainsString('50 earlier line(s) omitted', $outcome->extra['output']);
        $this->assertStringNotContainsString('line 1 ECONNREFUSED', $outcome->extra['output']);
        $this->assertStringContainsString('line 60 ECONNREFUSED', $outcome->extra['output']);
    }

    public function testPerCommandTimeoutOverridesTheCheckDefault(): void
    {
        $local = $this->createMock(LocalExecutor::class);
        $local->expects($this->once())
            ->method('run')
            ->with($this->anything(), null, null, null, 120)
            ->willReturn(new CommandResult(0, 'STATUS: OK'));

        $this->checker($this->createMock(SshExecutor::class), $local)->check([
            'timeout'  => 30,
            'commands' => [
                'SLOW' => ['command' => '/usr/local/sbin/monitor-slow', 'timeout' => 120],
            ],
        ]);
    }

    private function checker(SshExecutor $ssh, LocalExecutor $local): ScriptChecker
    {
        return new ScriptChecker($ssh, $local, new OutputRuleEvaluator());
    }
}
