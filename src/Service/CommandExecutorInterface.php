<?php

namespace App\Service;

interface CommandExecutorInterface
{
    /**
     * Runs a shell command and returns its outcome.
     *
     * @param string      $command Shell command line executed by /bin/sh on the target
     * @param string|null $host    Remote host, or null to run on the local machine
     * @param string|null $user    Remote user (ignored for local execution)
     * @param int|null    $port    Remote port (ignored for local execution)
     * @param int|null    $timeout Overrides the executor default timeout, in seconds
     */
    public function run(
        string $command,
        ?string $host = null,
        ?string $user = null,
        ?int $port = null,
        ?int $timeout = null,
    ): CommandResult;
}
