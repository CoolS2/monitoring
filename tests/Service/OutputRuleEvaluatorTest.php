<?php

namespace App\Tests\Service;

use App\Service\OutputRuleEvaluator;
use PHPUnit\Framework\TestCase;

class OutputRuleEvaluatorTest extends TestCase
{
    /** Real `monitor-system` output. */
    private const SYSTEM_REPORT = <<<TXT
        === UPTIME ===
         21:18:29 up 56 days,  7:14,  3 users,  load average: 0.27, 0.34, 0.35

        === CPU ===
        2
        0.27 0.34 0.35 1/509 3479642

        === MEMORY ===
                       total        used        free      shared  buff/cache   available
        Mem:           7.8Gi       2.6Gi       204Mi       195Mi       4.9Gi       4.6Gi
        Swap:             0B          0B          0B

        === DISK ===
        Filesystem      Size  Used Avail Use% Mounted on
        tmpfs           794M  1.1M  793M   1% /run
        /dev/sda1        97G   25G   73G  26% /
        tmpfs           3.9G  1.1M  3.9G   1% /dev/shm
        tmpfs           5.0M     0  5.0M   0% /run/lock
        /dev/sda15      105M  6.1M   99M   6% /boot/efi
        tmpfs           794M  8.0K  794M   1% /run/user/0
        TXT;

    private const DISK_RULE = [
        'name'       => 'Disk usage /',
        'match'      => '(\d+)%\s+/$',
        'unit'       => '%',
        'warn_above' => 80,
        'crit_above' => 90,
    ];

    private const LOAD_RULE = [
        'name'       => 'Load average (1 min)',
        'match'      => 'load average: ([0-9.]+)',
        'warn_above' => 4,
        'crit_above' => 8,
    ];

    private OutputRuleEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new OutputRuleEvaluator();
    }

    /**
     * The root filesystem sits at 26% and load at 0.27 — nothing to report,
     * and crucially the other mount points must not be mistaken for "/".
     */
    public function testHealthySystemReportProducesNoFindings(): void
    {
        $result = $this->evaluator->evaluate([self::DISK_RULE, self::LOAD_RULE], self::SYSTEM_REPORT);

        $this->assertSame('OK', $result['status']);
        $this->assertSame([], $result['findings']);

        // Staying inside the thresholds is still a measurement worth reporting
        $this->assertSame(
            [
                ['name' => 'Disk usage /',         'status' => 'OK', 'value' => '26%'],
                ['name' => 'Load average (1 min)', 'status' => 'OK', 'value' => '0.27'],
            ],
            $result['measurements']
        );
    }

    public function testDiskThresholdIsCrossed(): void
    {
        $report = str_replace('73G  26% /', '3G  96% /', self::SYSTEM_REPORT);

        $result = $this->evaluator->evaluate([self::DISK_RULE], $report);

        $this->assertSame('CRIT', $result['status']);
        $this->assertCount(1, $result['findings']);
        $this->assertSame('Disk usage /: 96% (> 90%)', $result['findings'][0]['message']);
        $this->assertSame(
            [['name' => 'Disk usage /', 'status' => 'CRIT', 'value' => '96%']],
            $result['measurements']
        );
    }

    public function testFractionalLoadIsCompared(): void
    {
        $report = str_replace('load average: 0.27', 'load average: 5.42', self::SYSTEM_REPORT);

        $result = $this->evaluator->evaluate([self::LOAD_RULE], $report);

        $this->assertSame('WARN', $result['status']);
        $this->assertSame('Load average (1 min): 5.42 (> 4)', $result['findings'][0]['message']);
    }

    /**
     * A pattern without a capture group counts matching lines instead.
     */
    public function testCountingModeFlagsRepeatedErrors(): void
    {
        $logs = implode("\n", array_fill(0, 12, '1|worldvie | ECONNREFUSED connecting to redis'));

        $result = $this->evaluator->evaluate([[
            'name'       => 'Fatal log lines',
            'match'      => 'FATAL|ECONNREFUSED',
            'warn_above' => 0,
            'crit_above' => 10,
        ]], $logs);

        $this->assertSame('CRIT', $result['status']);
        $this->assertSame('Fatal log lines: 12 (> 10)', $result['findings'][0]['message']);
    }

    public function testCountingModeStaysQuietWithoutMatches(): void
    {
        $logs = "1|worldvie |     at createError (server.mjs:707:21)\n1|worldvie |   statusCode: 404,";

        $result = $this->evaluator->evaluate([[
            'name'       => 'Fatal log lines',
            'match'      => 'FATAL|ECONNREFUSED',
            'warn_above' => 0,
        ]], $logs);

        $this->assertSame('OK', $result['status']);
        $this->assertSame([], $result['findings']);
    }

    /**
     * With several matches, an upper-bound rule reports the worst one.
     */
    public function testUpperBoundRuleTakesTheLargestMatch(): void
    {
        $result = $this->evaluator->evaluate([[
            'name'       => 'Any mount',
            'match'      => '(\d+)%',
            'unit'       => '%',
            'warn_above' => 20,
        ]], self::SYSTEM_REPORT);

        $this->assertSame('WARN', $result['status']);
        $this->assertStringContainsString('26%', $result['findings'][0]['message']);
    }

    /**
     * A lower-bound rule cares about the smallest match instead.
     */
    public function testLowerBoundRuleTakesTheSmallestMatch(): void
    {
        $result = $this->evaluator->evaluate([[
            'name'       => 'Free space',
            'match'      => '(\d+)G\s+\d+%',
            'unit'       => 'G',
            'warn_below' => 10,
        ]], self::SYSTEM_REPORT);

        $this->assertSame('WARN', $result['status']);
        $this->assertStringContainsString('< 10G', $result['findings'][0]['message']);
    }

    /**
     * A capture pattern that matches nothing is ignored, unless the rule says
     * what a missing measurement means.
     */
    public function testMissingMeasurementIsIgnoredByDefault(): void
    {
        $result = $this->evaluator->evaluate([[
            'name'       => 'Swap usage',
            'match'      => 'SwapTotal:\s+(\d+)',
            'warn_above' => 0,
        ]], self::SYSTEM_REPORT);

        $this->assertSame('OK', $result['status']);
        $this->assertSame([], $result['findings']);
    }

    public function testMissingMeasurementCanBeEscalated(): void
    {
        $result = $this->evaluator->evaluate([[
            'name'       => 'Swap usage',
            'match'      => 'SwapTotal:\s+(\d+)',
            'warn_above' => 0,
            'on_missing' => 'WARN',
        ]], self::SYSTEM_REPORT);

        $this->assertSame('WARN', $result['status']);
        $this->assertStringContainsString('nothing matched', $result['findings'][0]['message']);
    }

    /**
     * Absence of an expected line is expressed as a count below one.
     */
    public function testExpectedLineMissingIsCritical(): void
    {
        $result = $this->evaluator->evaluate([[
            'name'       => 'Nginx running',
            'match'      => 'active \(running\)',
            'crit_below' => 1,
        ]], "nginx.service - A high performance web server\n   Active: failed");

        $this->assertSame('CRIT', $result['status']);
        $this->assertSame('Nginx running: 0 (< 1)', $result['findings'][0]['message']);
    }

    public function testCaseIsIgnoredByDefaultAndCanBeEnforced(): void
    {
        $insensitive = $this->evaluator->evaluate(
            [['name' => 'x', 'match' => 'not running', 'warn_above' => 0]],
            'VARNISH: NOT RUNNING'
        );
        $this->assertSame('WARN', $insensitive['status']);

        $sensitive = $this->evaluator->evaluate(
            [['name' => 'x', 'match' => 'not running', 'warn_above' => 0, 'ignore_case' => false]],
            'VARNISH: NOT RUNNING'
        );
        $this->assertSame('OK', $sensitive['status']);
    }

    public function testInvalidPatternIsReportedInsteadOfCrashing(): void
    {
        $result = $this->evaluator->evaluate(
            [['name' => 'Broken', 'match' => '(unclosed', 'warn_above' => 0]],
            self::SYSTEM_REPORT
        );

        $this->assertSame('ERROR', $result['status']);
        $this->assertStringContainsString('invalid pattern', $result['findings'][0]['message']);
    }

    public function testMalformedRulesAreSkipped(): void
    {
        $result = $this->evaluator->evaluate(
            ['not-an-array', [], ['name' => 'no pattern'], self::DISK_RULE],
            self::SYSTEM_REPORT
        );

        $this->assertSame('OK', $result['status']);
        $this->assertSame([], $result['findings']);
    }

    /**
     * A pattern containing a slash must not break the compiled expression.
     */
    public function testPatternWithSlashesIsCompiled(): void
    {
        $result = $this->evaluator->evaluate([[
            'name'       => 'Root mount',
            'match'      => '/dev/sda1.*?(\d+)%',
            'unit'       => '%',
            'warn_above' => 10,
        ]], self::SYSTEM_REPORT);

        $this->assertSame('WARN', $result['status']);
        $this->assertStringContainsString('26%', $result['findings'][0]['message']);
    }
}
