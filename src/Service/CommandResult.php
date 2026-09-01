<?php

namespace App\Service;

/**
 * Result of a command executed locally or on a remote host.
 *
 * A distinction is deliberately made between a *transport* failure (we never
 * managed to run the command: SSH could not connect, the process timed out,
 * the binary is missing) and a non-zero *exit code* returned by the command
 * itself. Many legitimate commands report status through their exit code —
 * `grep`, for example, exits with 1 when it simply finds nothing — so the two
 * cases must never be collapsed into a single boolean.
 */
final class CommandResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output = '',
        public readonly string $errorOutput = '',
        public readonly ?string $transportError = null,
    ) {}

    public static function transportFailure(string $reason): self
    {
        return new self(-1, '', '', $reason);
    }

    /** The command could not be run at all (connection, timeout, missing key…). */
    public function isTransportFailure(): bool
    {
        return $this->transportError !== null;
    }

    /** The command ran and reported success. */
    public function isSuccessful(): bool
    {
        return $this->transportError === null && $this->exitCode === 0;
    }

    /** Human readable reason, suitable for a check message. */
    public function errorMessage(): string
    {
        if ($this->transportError !== null) {
            return $this->transportError;
        }

        $stderr = trim($this->errorOutput);
        if ($stderr !== '') {
            return sprintf('exit code %d: %s', $this->exitCode, $stderr);
        }

        return sprintf('exit code %d', $this->exitCode);
    }
}
