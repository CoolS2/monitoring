<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs a command on the machine the monitoring service itself lives on.
 *
 * Useful when the service is installed directly on the monitored server
 * (bare metal / systemd) rather than reaching it over SSH.
 */
class LocalExecutor implements CommandExecutorInterface
{
    public function __construct(
        #[Autowire(env: 'int:SSH_TIMEOUT')]
        private int $timeout = 10,
    ) {}

    public function run(
        string $command,
        ?string $host = null,
        ?string $user = null,
        ?int $port = null,
        ?int $timeout = null,
    ): CommandResult {
        $runTimeout = max(1, $timeout ?? $this->timeout);

        try {
            $process = Process::fromShellCommandline($command);
            $process->setTimeout((float) $runTimeout);
            $process->run();
        } catch (ProcessTimedOutException $e) {
            return CommandResult::transportFailure(
                sprintf('Local command timed out after %ds', $runTimeout)
            );
        } catch (\Throwable $e) {
            return CommandResult::transportFailure(
                sprintf('Local execution exception: %s', $e->getMessage())
            );
        }

        return new CommandResult(
            $process->getExitCode() ?? -1,
            $process->getOutput(),
            $process->getErrorOutput()
        );
    }
}
