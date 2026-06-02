<?php

namespace App\Checker;

final class CheckOutcome
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?float $responseTime = null,
        public readonly array $extra = []
    ) {}
}
