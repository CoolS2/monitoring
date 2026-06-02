<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

class SshExecutor
{
    public function __construct(
        #[Autowire(env: 'SSH_PRIVATE_KEY_PATH')]
        private string $privateKeyPath,
        #[Autowire(env: 'int:SSH_TIMEOUT')]
        private int $timeout = 10
    ) {}

    /**
     * Executes a command on a remote host via SSH.
     * Returns [success (bool), output/error (string)].
     */
    public function execute(string $host, string $user, string $command, ?int $port = null): array
    {
        if (!file_exists($this->privateKeyPath)) {
            return [false, sprintf('SSH private key not found at: %s', $this->privateKeyPath)];
        }

        $portOption = $port ? sprintf('-p %d', $port) : '';

        // Build command arguments array
        $cmd = ['ssh'];
        if (!empty($portOption)) {
            $cmd[] = '-p';
            $cmd[] = (string) $port;
        }
        $cmd[] = '-i';
        $cmd[] = $this->privateKeyPath;
        $cmd[] = '-o';
        $cmd[] = 'StrictHostKeyChecking=no';
        $cmd[] = '-o';
        $cmd[] = 'ConnectTimeout=' . $this->timeout;
        $cmd[] = sprintf('%s@%s', $user ?: 'root', $host);
        $cmd[] = $command;

        try {
            $process = new Process($cmd);
            $process->setTimeout((float) $this->timeout);
            $process->run();

            if (!$process->isSuccessful()) {
                $errorMsg = trim($process->getErrorOutput());
                if (empty($errorMsg)) {
                    $errorMsg = trim($process->getOutput());
                }
                return [false, $errorMsg ?: 'Unknown SSH execution failure'];
            }

            return [true, $process->getOutput()];
        } catch (\Throwable $e) {
            return [false, sprintf('SSH execution exception: %s', $e->getMessage())];
        }
    }
}
