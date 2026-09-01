<?php

namespace App\Checker;

use App\Service\CommandResult;
use App\Service\LocalExecutor;
use App\Service\OutputRuleEvaluator;
use App\Service\SshExecutor;

/**
 * Runs one or more diagnostic shell commands — locally or over SSH — and turns
 * their textual report into a check outcome.
 *
 * It is built for report-style scripts such as the `/usr/local/sbin/monitor-*`
 * family. Those come in two shapes and both are supported:
 *
 *  1. Scripts that grade themselves with a `STATUS: OK | WARN | CRIT` line
 *     (`monitor-logs`). The banner is read directly.
 *  2. Scripts that just print data — `df`/`free` tables, raw log tails
 *     (`monitor-system`, `monitor-pm2-logs`). Those are graded by `rules`,
 *     which measure a number in the output and compare it with thresholds.
 *
 * Whatever the shape, the captured text is handed on as context so the LLM
 * analyzer can summarise it.
 */
class ScriptChecker implements CheckerInterface
{
    private const DEFAULT_FAIL_ON = ['WARN', 'WARNING', 'ERROR', 'CRIT', 'CRITICAL', 'FAIL'];

    /** Hard cap on the text kept per section for the report, in characters. */
    private const MAX_SECTION_CHARS = 8000;

    public function __construct(
        private SshExecutor $sshExecutor,
        private LocalExecutor $localExecutor,
        private OutputRuleEvaluator $ruleEvaluator,
    ) {}

    public function supports(string $type): bool
    {
        return in_array($type, ['script', 'command'], true);
    }

    public function check(array $config): CheckOutcome
    {
        $commands = $this->resolveCommands($config);

        if (empty($commands)) {
            return new CheckOutcome(false, 'Missing "command" or "commands" for script check');
        }

        $host    = isset($config['host']) ? (string) $config['host'] : null;
        $user    = isset($config['user']) ? (string) $config['user'] : null;
        $port    = isset($config['port']) ? (int) $config['port'] : null;
        $timeout = isset($config['timeout']) ? (int) $config['timeout'] : null;
        $failOn  = $this->resolveFailOn($config);

        $executor = ($host !== null && $host !== '') ? $this->sshExecutor : $this->localExecutor;

        $startTime = microtime(true);

        $sections       = [];
        $statuses       = [];
        $failedSections = [];
        $findings       = [];
        $worstStatus    = Status::OK;

        foreach ($commands as $label => $spec) {
            $result = $executor->run($spec['command'], $host, $user, $port, $spec['timeout'] ?? $timeout);

            if ($result->isTransportFailure()) {
                $failedSections[$label] = $result->transportError;
                $statuses[$label]       = Status::UNKNOWN;
                $sections[$label]       = sprintf('(not collected: %s)', $result->transportError);
                $worstStatus            = Status::worse($worstStatus, Status::CRIT);
                continue;
            }

            $text = $this->capture($result);

            // Rules are evaluated against the *complete* output; the trimming
            // below only affects what is stored and shown, never the verdict.
            $evaluation = $this->ruleEvaluator->evaluate($spec['rules'], $text);

            foreach ($evaluation['findings'] as $finding) {
                $findings[] = sprintf('[%s] %s', $label, $finding['message']);
            }

            $status = $this->parseStatus($text);

            // A script that neither grades itself nor matches a rule is judged
            // by its exit code alone.
            if ($status === null) {
                $status = $result->isSuccessful() ? Status::OK : Status::ERROR;
            }

            if (!$result->isSuccessful()) {
                $failedSections[$label] = $result->errorMessage();
                $status = Status::worse($status, Status::ERROR);
            }

            $status           = Status::worse($status, $evaluation['status']);
            $statuses[$label] = $status;
            $worstStatus      = Status::worse($worstStatus, $status);

            $sections[$label] = $this->trimForReport($text, $spec['max_lines']);
        }

        $responseTime = round(microtime(true) - $startTime, 3);

        $extra = [
            'worst_status'    => $worstStatus,
            'statuses'        => $statuses,
            'findings'        => $findings,
            'failed_sections' => $failedSections,
            'target'          => $host ?? 'localhost',
            'output'          => $this->buildReport($sections, $findings),
        ];

        if (in_array($worstStatus, $failOn, true)) {
            return new CheckOutcome(
                false,
                sprintf(
                    'STATUS %s on %s (%s)%s',
                    $worstStatus,
                    $host ?? 'localhost',
                    $this->describeStatuses($statuses),
                    $findings === [] ? '' : ' — ' . implode('; ', array_slice($findings, 0, 5))
                ),
                $responseTime,
                $extra
            );
        }

        return new CheckOutcome(
            true,
            sprintf('OK (%s)', $this->describeStatuses($statuses)),
            $responseTime,
            $extra
        );
    }

    /**
     * Normalises the `command` / `commands` configuration.
     *
     * Every entry becomes a spec: the command line plus its optional rules,
     * line budget and timeout override.
     *
     * @return array<string, array{command: string, rules: list<mixed>, max_lines: ?int, timeout: ?int}>
     */
    private function resolveCommands(array $config): array
    {
        $commands = [];

        if (!empty($config['commands']) && is_array($config['commands'])) {
            foreach ($config['commands'] as $label => $definition) {
                [$label, $spec] = $this->resolveCommandEntry($label, $definition);

                if ($spec !== null) {
                    $commands[$label] = $spec;
                }
            }

            return $commands;
        }

        $single = trim((string) ($config['command'] ?? ''));
        if ($single !== '') {
            $label = (string) ($config['label'] ?? $this->deriveLabel($single));

            $commands[$label] = $this->buildSpec($single, $config);
        }

        return $commands;
    }

    /**
     * @return array{0: string, 1: array{command: string, rules: list<mixed>, max_lines: ?int, timeout: ?int}|null}
     */
    private function resolveCommandEntry(mixed $label, mixed $definition): array
    {
        if (is_array($definition)) {
            // LABEL: { command: "...", rules: [...] }  or  - { name: LABEL, command: "..." }
            $name    = (string) ($definition['name'] ?? $definition['label'] ?? $label);
            $command = trim((string) ($definition['command'] ?? $definition['run'] ?? ''));

            if ($command === '') {
                return [$name, null];
            }

            if (is_int($label) && ($definition['name'] ?? $definition['label'] ?? null) === null) {
                $name = $this->deriveLabel($command);
            }

            return [$name, $this->buildSpec($command, $definition)];
        }

        $command = trim((string) $definition);
        if ($command === '') {
            return [(string) $label, null];
        }

        // Plain list of command strings — derive a label from the binary name
        $name = is_int($label) ? $this->deriveLabel($command) : (string) $label;

        return [$name, $this->buildSpec($command, [])];
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array{command: string, rules: list<mixed>, max_lines: ?int, timeout: ?int}
     */
    private function buildSpec(string $command, array $source): array
    {
        $rules = $source['rules'] ?? [];

        return [
            'command'   => $command,
            'rules'     => is_array($rules) ? array_values($rules) : [],
            'max_lines' => isset($source['max_lines']) ? max(1, (int) $source['max_lines']) : null,
            'timeout'   => isset($source['timeout']) ? (int) $source['timeout'] : null,
        ];
    }

    /**
     * Builds a readable label from a command line, e.g.
     * "sudo -u monitor sudo /usr/local/sbin/monitor-logs" -> "MONITOR-LOGS".
     */
    private function deriveLabel(string $command): string
    {
        $tokens = preg_split('/\s+/', trim($command)) ?: [];
        $last   = (string) end($tokens);
        $base   = basename($last);

        return strtoupper($base !== '' ? $base : 'COMMAND');
    }

    /**
     * @return list<string>
     */
    private function resolveFailOn(array $config): array
    {
        $failOn = $config['fail_on'] ?? self::DEFAULT_FAIL_ON;

        if (is_string($failOn)) {
            $failOn = preg_split('/[\s,]+/', $failOn) ?: [];
        }

        if (!is_array($failOn)) {
            $failOn = self::DEFAULT_FAIL_ON;
        }

        $normalised = [];
        foreach ($failOn as $status) {
            $status = strtoupper(trim((string) $status));
            if ($status !== '') {
                $normalised[] = $status;
            }
        }

        return $normalised ?: self::DEFAULT_FAIL_ON;
    }

    /**
     * Merges stdout and stderr into the raw text a section produced.
     */
    private function capture(CommandResult $result): string
    {
        $text = rtrim($result->output);

        $stderr = trim($result->errorOutput);
        if ($stderr !== '') {
            $text = ($text !== '' ? $text . "\n" : '') . '[stderr] ' . $stderr;
        }

        return $text !== '' ? $text : '(no output)';
    }

    /**
     * Shrinks a section for storage and for the LLM prompt.
     *
     * Raw log dumps are the reason `max_lines` exists: keeping the newest lines
     * is what matters there. The character cap then keeps the head (which holds
     * the status banner of self-grading scripts) and the tail.
     */
    private function trimForReport(string $text, ?int $maxLines): string
    {
        if ($maxLines !== null) {
            $lines = preg_split('/\R/', $text) ?: [];
            if (count($lines) > $maxLines) {
                $dropped = count($lines) - $maxLines;
                $text    = sprintf('… (%d earlier line(s) omitted)', $dropped) . "\n"
                    . implode("\n", array_slice($lines, -$maxLines));
            }
        }

        if (mb_strlen($text) <= self::MAX_SECTION_CHARS) {
            return $text;
        }

        $head = (int) floor(self::MAX_SECTION_CHARS * 0.4);
        $tail = self::MAX_SECTION_CHARS - $head;

        return mb_substr($text, 0, $head) . "\n… (truncated) …\n" . mb_substr($text, -$tail);
    }

    /**
     * Extracts the worst `STATUS: X` line from a script report, or null when
     * the script does not advertise a status at all.
     */
    private function parseStatus(string $text): ?string
    {
        if (!preg_match_all('/^\s*STATUS\s*[:=]\s*([A-Za-z]+)/mi', $text, $matches)) {
            return null;
        }

        $worst = null;
        foreach ($matches[1] as $status) {
            $worst = $worst === null ? strtoupper($status) : Status::worse($worst, $status);
        }

        return $worst;
    }

    /**
     * @param array<string, string> $statuses
     */
    private function describeStatuses(array $statuses): string
    {
        $parts = [];
        foreach ($statuses as $label => $status) {
            $parts[] = sprintf('%s=%s', $label, $status);
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string, string> $sections
     * @param list<string>          $findings
     */
    private function buildReport(array $sections, array $findings): string
    {
        $blocks = [];

        if ($findings !== []) {
            // Put the machine-detected problems first: it is the part of the
            // report the model should weigh most heavily.
            $blocks[] = "=== DETECTED ISSUES ===\n" . implode("\n", $findings);
        }

        foreach ($sections as $label => $text) {
            $blocks[] = sprintf("=== %s ===\n%s", $label, $text);
        }

        return implode("\n\n", $blocks);
    }
}
