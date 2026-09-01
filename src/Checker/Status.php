<?php

namespace App\Checker;

/**
 * The severity vocabulary shared by report scripts and output rules.
 */
final class Status
{
    public const OK      = 'OK';
    public const WARN    = 'WARN';
    public const ERROR   = 'ERROR';
    public const CRIT    = 'CRIT';
    public const UNKNOWN = 'UNKNOWN';

    /** Ordered from healthiest to worst. */
    private const RANK = [
        'OK'       => 0,
        'INFO'     => 1,
        'NOTICE'   => 1,
        'UNKNOWN'  => 2,
        'WARN'     => 3,
        'WARNING'  => 3,
        'ERROR'    => 4,
        'CRIT'     => 5,
        'CRITICAL' => 5,
        'FAIL'     => 5,
    ];

    /**
     * Severity of a status label. Anything unrecognised is ranked at ERROR:
     * an unknown word from a script is more likely to mean trouble than health.
     */
    public static function rank(string $status): int
    {
        return self::RANK[strtoupper(trim($status))] ?? self::RANK['ERROR'];
    }

    /** Returns whichever of the two statuses is more severe. */
    public static function worse(string $a, string $b): string
    {
        return self::rank($b) > self::rank($a) ? strtoupper(trim($b)) : strtoupper(trim($a));
    }

    public static function isKnown(string $status): bool
    {
        return isset(self::RANK[strtoupper(trim($status))]);
    }
}
