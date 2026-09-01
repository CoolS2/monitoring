<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class SshExecutor implements CommandExecutorInterface
{
    /** Exit status ssh(1) uses to report its own failure (connection, auth, …). */
    private const SSH_TRANSPORT_EXIT_CODE = 255;

    public function __construct(
        #[Autowire(env: 'SSH_PRIVATE_KEY_PATH')]
        private string $privateKeyPath,
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
        if ($host === null || $host === '') {
            return CommandResult::transportFailure('No SSH host given');
        }

        if (!is_file($this->privateKeyPath)) {
            return CommandResult::transportFailure(
                sprintf('SSH private key not found at: %s', $this->privateKeyPath)
            );
        }

        // The connect timeout guards the handshake; the process timeout guards
        // the whole run and must therefore be at least as generous.
        $runTimeout     = max(1, $timeout ?? $this->timeout);
        $connectTimeout = min($this->timeout, $runTimeout);

        $cmd = ['ssh'];

        if ($port !== null) {
            $cmd[] = '-p';
            $cmd[] = (string) $port;
        }

        $cmd[] = '-i';
        $cmd[] = $this->privateKeyPath;
        $cmd[] = '-o';
        $cmd[] = 'StrictHostKeyChecking=no';
        $cmd[] = '-o';
        $cmd[] = 'BatchMode=yes';           // Never prompt for a password; fail immediately
        $cmd[] = '-o';
        $cmd[] = 'ConnectTimeout=' . $connectTimeout;
        $cmd[] = '-o';
        $cmd[] = 'ServerAliveInterval=5';   // Detect stale connections early
        $cmd[] = '-o';
        $cmd[] = 'ServerAliveCountMax=2';
        $cmd[] = sprintf('%s@%s', ($user !== null && $user !== '') ? $user : 'root', $host);
        $cmd[] = $command;

        try {
            $process = new Process($cmd);
            $process->setTimeout((float) $runTimeout);
            $process->run();
        } catch (ProcessTimedOutException $e) {
            return CommandResult::transportFailure(
                sprintf('SSH command timed out after %ds on %s', $runTimeout, $host)
            );
        } catch (\Throwable $e) {
            return CommandResult::transportFailure(
                sprintf('SSH execution exception: %s', $e->getMessage())
            );
        }

        $exitCode = $process->getExitCode() ?? self::SSH_TRANSPORT_EXIT_CODE;

        // ssh itself exits with 255 when it cannot reach or authenticate to the
        // host. Anything else is the exit status of the remote command.
        if ($exitCode === self::SSH_TRANSPORT_EXIT_CODE) {
            $reason = trim($process->getErrorOutput());

            return CommandResult::transportFailure(sprintf(
                'SSH connection to %s failed: %s',
                $host,
                $reason !== '' ? $reason : 'unknown error'
            ));
        }

        return new CommandResult($exitCode, $process->getOutput(), $process->getErrorOutput());
    }
}
