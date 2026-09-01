<?php

namespace App\Checker;

use App\Service\SshExecutor;

class DockerChecker implements CheckerInterface
{
    /** Docker CLI is not installed on the target host. */
    private const EXIT_DOCKER_MISSING = 4;

    /** Docker CLI is present but the daemon could not be queried. */
    private const EXIT_DOCKER_UNAVAILABLE = 5;

    public function __construct(private SshExecutor $sshExecutor) {}

    public function supports(string $type): bool
    {
        return $type === 'docker';
    }

    public function check(array $config): CheckOutcome
    {
        $host = (string) ($config['host'] ?? '');
        $user = (string) ($config['user'] ?? 'root');
        $port = isset($config['port']) ? (int) $config['port'] : null;
        $maxRestarts = (int) ($config['max_restarts'] ?? 3);

        if ($host === '') {
            return new CheckOutcome(false, 'Missing host for Docker check');
        }

        $startTime = microtime(true);

        $result = $this->sshExecutor->run($this->buildCommand(), $host, $user, $port);

        $responseTime = round(microtime(true) - $startTime, 3);

        if ($result->isTransportFailure()) {
            return new CheckOutcome(
                false,
                sprintf('Failed to reach Docker host: %s', $result->transportError),
                $responseTime
            );
        }

        if ($result->exitCode === self::EXIT_DOCKER_MISSING) {
            return new CheckOutcome(false, 'Docker CLI is not installed on the target host', $responseTime);
        }

        if ($result->exitCode === self::EXIT_DOCKER_UNAVAILABLE) {
            return new CheckOutcome(
                false,
                sprintf('Docker daemon is not reachable: %s', $result->errorMessage()),
                $responseTime
            );
        }

        if (!$result->isSuccessful()) {
            return new CheckOutcome(
                false,
                sprintf('Failed to retrieve Docker containers: %s', $result->errorMessage()),
                $responseTime
            );
        }

        $output = trim($result->output);
        if ($output === '') {
            return new CheckOutcome(true, 'OK (No containers found)', $responseTime, [
                'total_count'       => 0,
                'problematic_count' => 0,
                'containers'        => [],
                'problematic'       => [],
            ]);
        }

        $problematic   = [];
        $allContainers = [];

        foreach (preg_split('/\R/', $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode('|', $line);
            if (count($parts) < 5) {
                continue;
            }

            $containerInfo = [
                'name'      => ltrim($parts[0], '/'),
                'state'     => $parts[1],
                'restarts'  => (int) $parts[2],
                'health'    => $parts[3],
                'exit_code' => (int) $parts[4],
            ];

            $allContainers[] = $containerInfo;

            $issues = [];
            if ($containerInfo['health'] === 'unhealthy') {
                $issues[] = 'unhealthy';
            }
            if ($containerInfo['state'] === 'restarting') {
                $issues[] = 'continually restarting';
            }
            if ($containerInfo['restarts'] > $maxRestarts) {
                $issues[] = sprintf('high restart count (%d > %d)', $containerInfo['restarts'], $maxRestarts);
            }
            if ($containerInfo['state'] === 'exited' && $containerInfo['exit_code'] !== 0) {
                $issues[] = sprintf('exited with error code %d', $containerInfo['exit_code']);
            }

            if (!empty($issues)) {
                $containerInfo['issues'] = implode(', ', $issues);
                $problematic[] = $containerInfo;
            }
        }

        $extra = [
            'total_count'       => count($allContainers),
            'problematic_count' => count($problematic),
            'containers'        => $allContainers,
            'problematic'       => $problematic,
        ];

        if (!empty($problematic)) {
            $problemNames = array_map(
                static fn (array $c): string => sprintf('%s (%s)', $c['name'], $c['issues']),
                $problematic
            );

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

    /**
     * Remote snippet listing every container with its state, restart count,
     * health status and exit code — one container per line, pipe separated.
     *
     * Distinct exit codes are used so a missing Docker CLI or an unreachable
     * daemon is reported as such instead of looking like "no containers".
     */
    private function buildCommand(): string
    {
        $format = '{{.Name}}|{{.State.Status}}|{{.State.RestartCount}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}|{{.State.ExitCode}}';

        return implode(' ', [
            sprintf('command -v docker >/dev/null 2>&1 || exit %d;', self::EXIT_DOCKER_MISSING),
            sprintf('ids=$(docker ps -aq) || exit %d;', self::EXIT_DOCKER_UNAVAILABLE),
            '[ -z "$ids" ] && exit 0;',
            sprintf("docker inspect --format '%s' \$ids", $format),
        ]);
    }
}
