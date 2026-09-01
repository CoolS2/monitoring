<?php

namespace App\Checker;

use App\Service\CommandResult;
use App\Service\SshExecutor;

class SshLogChecker implements CheckerInterface
{
    /** Exit code our remote snippet uses to report an unreadable log file. */
    private const EXIT_FILE_UNREADABLE = 3;

    /** grep(1) exits with 1 when the pattern simply did not match anything. */
    private const EXIT_GREP_NO_MATCH = 1;

    /** Number of matched lines kept as context for the LLM / dashboard. */
    private const CONTEXT_LINES = 20;

    public function __construct(private SshExecutor $sshExecutor) {}

    public function supports(string $type): bool
    {
        return $type === 'ssh_log';
    }

    public function check(array $config): CheckOutcome
    {
        $targets = $this->resolveTargets($config);

        if (empty($targets)) {
            return new CheckOutcome(false, 'Missing host or file path for SSH Log check');
        }

        $lines = max(1, (int) ($config['lines'] ?? 200));
        $grep  = (string) ($config['grep'] ?? '');

        $startTime = microtime(true);

        $totalMatched  = 0;
        $allLines      = [];
        $failedTargets = [];
        $okTargets     = 0;

        foreach ($targets as $target) {
            if (!is_array($target)) {
                $failedTargets[] = 'invalid target entry (expected a mapping)';
                continue;
            }

            $host = (string) ($target['host'] ?? '');
            $user = (string) ($target['user'] ?? $config['user'] ?? 'root');
            $file = (string) ($target['file'] ?? '');
            $port = isset($target['port']) ? (int) $target['port'] : (isset($config['port']) ? (int) $config['port'] : null);

            if ($host === '' || $file === '') {
                $failedTargets[] = sprintf('invalid target (host=%s, file=%s)', $host, $file);
                continue;
            }

            $result = $this->sshExecutor->run(
                $this->buildCommand($file, $lines, $grep),
                $host,
                $user,
                $port
            );

            $failure = $this->describeFailure($result, $host, $file, $grep);
            if ($failure !== null) {
                $failedTargets[] = $failure;
                continue;
            }

            $okTargets++;

            $output = trim($result->output);
            if ($output === '') {
                continue;
            }

            // Tag each line with its source so multi-host output stays readable
            foreach (preg_split('/\R/', $output) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $totalMatched++;
                $allLines[] = sprintf('[%s] %s', $host, $line);
            }
        }

        $responseTime = round(microtime(true) - $startTime, 3);

        // Not a single target could be read — this is an infrastructure failure,
        // not a statement about the logs.
        if ($okTargets === 0) {
            return new CheckOutcome(
                false,
                sprintf(
                    'SSH connection or command failed for %d target(s): %s',
                    count($failedTargets),
                    implode('; ', $failedTargets)
                ),
                $responseTime,
                ['failed_targets' => $failedTargets, 'matched_lines' => []]
            );
        }

        $extra = [
            'count'          => $totalMatched,
            'matched_lines'  => array_slice($allLines, -self::CONTEXT_LINES),
            'failed_targets' => $failedTargets,
        ];

        // With a grep pattern, any matched line means errors were found.
        if ($grep !== '') {
            if ($totalMatched > 0) {
                $messages = [sprintf('%d matching error log line(s) found', $totalMatched)];
                if (!empty($failedTargets)) {
                    $messages[] = sprintf('%d target(s) unreachable', count($failedTargets));
                }

                return new CheckOutcome(false, implode('; ', $messages), $responseTime, $extra);
            }

            $okMsg = sprintf('OK (No matching log lines across %d target(s))', $okTargets);

            if (!empty($failedTargets)) {
                return new CheckOutcome(
                    false,
                    sprintf(
                        '%s; but %d target(s) failed: %s',
                        $okMsg,
                        count($failedTargets),
                        implode('; ', $failedTargets)
                    ),
                    $responseTime,
                    $extra
                );
            }

            return new CheckOutcome(true, $okMsg, $responseTime, $extra);
        }

        // No grep pattern: the check only reports what it retrieved.
        if (!empty($failedTargets)) {
            return new CheckOutcome(
                false,
                sprintf(
                    '%d target(s) failed: %s',
                    count($failedTargets),
                    implode('; ', $failedTargets)
                ),
                $responseTime,
                $extra
            );
        }

        return new CheckOutcome(
            true,
            sprintf('OK (%d log line(s) retrieved across %d target(s))', $totalMatched, $okTargets),
            $responseTime,
            $extra
        );
    }

    /**
     * Translates a command result into a target failure description,
     * or null when the target was read successfully.
     *
     * A `grep` exit status of 1 means "no lines matched" — a perfectly healthy
     * outcome that must not be mistaken for a broken SSH connection.
     */
    private function describeFailure(CommandResult $result, string $host, string $file, string $grep): ?string
    {
        if ($result->isTransportFailure()) {
            return sprintf('%s:%s — %s', $host, $file, $result->transportError);
        }

        if ($result->exitCode === self::EXIT_FILE_UNREADABLE) {
            return sprintf('%s:%s — log file missing or not readable', $host, $file);
        }

        if ($result->isSuccessful()) {
            return null;
        }

        if ($grep !== '' && $result->exitCode === self::EXIT_GREP_NO_MATCH) {
            return null; // no matches, all clear
        }

        return sprintf('%s:%s — %s', $host, $file, $result->errorMessage());
    }

    /**
     * Builds the remote shell command to tail and optionally grep the log file.
     *
     * The readability of the file is asserted up front so that a missing log is
     * reported as such instead of silently looking like "no errors found".
     */
    private function buildCommand(string $file, int $lines, string $grep): string
    {
        $quotedFile = escapeshellarg($file);

        $prelude = sprintf('test -r %s || exit %d; ', $quotedFile, self::EXIT_FILE_UNREADABLE);

        if ($grep !== '') {
            return $prelude . sprintf(
                'tail -n %d %s | grep -E -i -- %s',
                $lines,
                $quotedFile,
                escapeshellarg($grep)
            );
        }

        return $prelude . sprintf('tail -n %d %s', $lines, $quotedFile);
    }

    /**
     * Resolves the list of targets from either the `targets` array format
     * or the legacy single host/file format (backward compatible).
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveTargets(array $config): array
    {
        if (!empty($config['targets']) && is_array($config['targets'])) {
            return array_values($config['targets']);
        }

        // Legacy single-target format
        $host = $config['host'] ?? '';
        $file = $config['file'] ?? '';

        if (empty($host) || empty($file)) {
            return [];
        }

        return [[
            'host' => $host,
            'user' => $config['user'] ?? 'root',
            'file' => $file,
            'port' => isset($config['port']) ? (int) $config['port'] : null,
        ]];
    }
}
