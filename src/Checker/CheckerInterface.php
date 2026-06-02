<?php

namespace App\Checker;

interface CheckerInterface
{
    /**
     * Determines whether this checker supports the given check type.
     */
    public function supports(string $type): bool;

    /**
     * Executes the check based on the configuration array.
     */
    public function check(array $config): CheckOutcome;
}
