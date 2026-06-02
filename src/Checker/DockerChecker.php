<?php

namespace App\Checker;

use App\Service\SshExecutor;

class DockerChecker implements CheckerInterface
{
    public function __construct(private SshExecutor $sshExecutor) {}

    public function supports(string $type): bool
    {
        return $type === 'docker';
    }

    public function check(array $config): CheckOutcome
    {
        $host = $config['host'] ?? '';
        $user = $config['user'] ?? 'root';
        $port = isset($config['port']) ? (int) $config['port'] : null;
        $maxRestarts = (int) ($config['max_restarts'] ?? 3);

        if (empty($host)) {
            return new CheckOutcome(false, 'Missing host for Docker check');
        }

        $startTime = microtime(true);

        // Run inspect command to extract status, restart count, and health check state
        // We append || true to prevent failure in case no containers exist.
        $cmd = "docker inspect --format '{{.Name}}|{{.State.Status}}|{{.State.RestartCount}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}|{{.State.ExitCode}}' \$(docker ps -aq) 2>/dev/null || true";

        [$success, $output] = $this->sshExecutor->execute($host, $user, $cmd, $port);
        $responseTime = round(microtime(true) - $startTime, 3);

        if (!$success) {
            return new CheckOutcome(false, sprintf('Failed to retrieve Docker containers: %s', $output), $responseTime);
        }

        $output = trim($output);
        if (empty($output)) {
            return new CheckOutcome(true, 'OK (No containers found)', $responseTime, ['containers' => []]);
        }

        $lines = explode("\n", $output);
        $problematic = [];
        $allContainers = [];

        foreach ($lines as $line) {
            $parts = explode('|', trim($line));
            if (count($parts) < 5) {
                continue;
            }

            $name = ltrim($parts[0], '/');
            $state = $parts[1];
            $restarts = (int) $parts[2];
            $health = $parts[3];
            $exitCode = (int) $parts[4];

            $containerInfo = [
                'name' => $name,
                'state' => $state,
                'restarts' => $restarts,
                'health' => $health,
                'exit_code' => $exitCode
            ];

            $allContainers[] = $containerInfo;

            $issues = [];
            if ($health === 'unhealthy') {
                $issues[] = 'unhealthy';
            }
            if ($state === 'restarting') {
                $issues[] = 'continually restarting';
            }
            if ($restarts > $maxRestarts) {
                $issues[] = sprintf('high restart count (%d > %d)', $restarts, $maxRestarts);
            }
            if ($state === 'exited' && $exitCode !== 0) {
                $issues[] = sprintf('exited with error code %d', $exitCode);
            }

            if (!empty($issues)) {
                $containerInfo['issues'] = implode(', ', $issues);
                $problematic[] = $containerInfo;
            }
        }

        $extra = [
            'total_count' => count($allContainers),
            'problematic_count' => count($problematic),
            'containers' => $allContainers,
            'problematic' => $problematic
        ];

        if (!empty($problematic)) {
            $problemNames = array_map(fn($c) => sprintf('%s (%s)', $c['name'], $c['issues']), $problematic);
            return new CheckOutcome(
                false,
                sprintf('Problematic containers detected: %s', implode('; ', $problemNames)),
                $responseTime,
                $extra
            );
        }

        return new CheckOutcome(
            true,
            sprintf('OK (%d containers healthy/running)', count($allContainers)),
            $responseTime,
            $extra
        );
    }
}
