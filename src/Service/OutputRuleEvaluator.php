<?php

namespace App\Service;

use App\Checker\Status;

/**
 * Derives a status from the free-form output of a diagnostic script.
 *
 * Not every report announces its own verdict: `monitor-system` prints `df` and
 * `free` tables, `monitor-pm2-logs` dumps raw log lines, and neither contains a
 * `STATUS:` banner. A rule turns such output into a number and compares it with
 * thresholds, so those reports can raise alerts too.
 *
 * A rule always works the same way — **measure, then compare**:
 *
 *   - `match` is a regular expression applied to the whole output, line by line.
 *   - The measured value is either the first capture group of the match
 *     (`value: capture`) or the number of matching lines (`value: count`).
 *     Without an explicit `value`, a pattern with a capture group measures the
 *     capture and one without counts matches.
 *   - `warn_above` / `crit_above` / `warn_below` / `crit_below` decide the verdict.
 *
 * ```yaml
 * rules:
 *   - name: "Disk usage"          # 26% /  ->  26
 *     match: '(\d+)%\s+/$'
 *     unit: "%"
 *     warn_above: 80
 *     crit_above: 90
 *
 *   - name: "Fatal log lines"     # counts matching lines
 *     match: 'FATAL|ECONNREFUSED'
 *     warn_above: 0
 * ```
 */
class OutputRuleEvaluator
{
    private const AGGREGATES = ['max', 'min', 'sum', 'avg', 'first'];

    /**
     * @param iterable<mixed> $rules Rule definitions straight from monitors.yaml
     *
     * @return array{
     *     status: string,
     *     findings: list<array{name: string, status: string, message: string}>,
     *     measurements: list<array{name: string, status: string, value: string}>
     * }
     *
     * `findings` holds only the rules that breached a threshold — that is what
     * turns into an alert. `measurements` holds what every rule read, breach or
     * not, so a report can print the numbers on a healthy day too.
     */
    public function evaluate(iterable $rules, string $text): array
    {
        $status       = Status::OK;
        $findings     = [];
        $measurements = [];
        $index        = 0;

        foreach ($rules as $rule) {
            $index++;

            if (!is_array($rule)) {
                continue;
            }

            $pattern = trim((string) ($rule['match'] ?? ''));
            if ($pattern === '') {
                continue;
            }

            $name    = trim((string) ($rule['name'] ?? sprintf('rule #%d', $index)));
            $reading = $this->applyRule($name, $pattern, $rule, $text);

            if ($reading['measurement'] !== null) {
                $measurements[] = $reading['measurement'];
            }

            if ($reading['finding'] !== null) {
                $findings[] = $reading['finding'];
                $status     = Status::worse($status, $reading['finding']['status']);
            }
        }

        return ['status' => $status, 'findings' => $findings, 'measurements' => $measurements];
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @return array{
     *     finding: array{name: string, status: string, message: string}|null,
     *     measurement: array{name: string, status: string, value: string}|null
     * }
     */
    private function applyRule(string $name, string $pattern, array $rule, string $text): array
    {
        $regex = $this->compile($pattern, (bool) ($rule['ignore_case'] ?? true));

        // PREG_PATTERN_ORDER fills one bucket per capturing group even when
        // nothing matched, so the pattern's shape can be read off the result
        // rather than guessed from the matches.
        $count = @preg_match_all($regex, $text, $matches);

        if ($count === false) {
            return $this->reading(
                $this->finding($name, Status::ERROR, sprintf('%s: invalid pattern %s', $name, $pattern)),
                null
            );
        }

        $mode = strtolower(trim((string) ($rule['value'] ?? '')));
        if ($mode !== 'capture' && $mode !== 'count') {
            $mode = count($matches) > 1 ? 'capture' : 'count';
        }

        $unit = (string) ($rule['unit'] ?? '');

        if ($mode === 'count') {
            $rendered = $count . $unit;
            $finding  = $this->judge($name, (float) $count, $rule, $rendered);

            return $this->reading($finding, $this->measurement($name, $finding, $rendered));
        }

        $values = [];
        foreach ($matches[1] ?? [] as $candidate) {
            $candidate = trim((string) $candidate);
            if (is_numeric($candidate)) {
                $values[] = (float) $candidate;
            }
        }

        if ($values === []) {
            // The pattern found nothing to measure. That is usually harmless
            // (an absent optional line), so it is only reported when the rule
            // explicitly says what a missing measurement means.
            $onMissing = strtoupper(trim((string) ($rule['on_missing'] ?? '')));
            if ($onMissing === '') {
                return $this->reading(null, null);
            }

            return $this->reading(
                $this->finding($name, $onMissing, sprintf('%s: nothing matched %s', $name, $pattern)),
                null
            );
        }

        $value    = $this->aggregate($values, $rule);
        $rendered = $this->format($value) . $unit;
        $finding  = $this->judge($name, $value, $rule, $rendered);

        return $this->reading($finding, $this->measurement($name, $finding, $rendered));
    }

    /**
     * @param array{name: string, status: string, message: string}|null    $finding
     * @param array{name: string, status: string, value: string}|null      $measurement
     *
     * @return array{finding: array|null, measurement: array|null}
     */
    private function reading(?array $finding, ?array $measurement): array
    {
        return ['finding' => $finding, 'measurement' => $measurement];
    }

    /**
     * A rule that stayed inside its thresholds still measured something; the
     * measurement inherits the verdict so a report can colour it.
     *
     * @param array{name: string, status: string, message: string}|null $finding
     *
     * @return array{name: string, status: string, value: string}
     */
    private function measurement(string $name, ?array $finding, string $rendered): array
    {
        return [
            'name'   => $name,
            'status' => $finding['status'] ?? Status::OK,
            'value'  => $rendered,
        ];
    }

    /**
     * Compares the measured value with the rule's thresholds.
     *
     * @param array<string, mixed> $rule
     *
     * @return array{name: string, status: string, message: string}|null
     */
    private function judge(string $name, float $value, array $rule, string $rendered): ?array
    {
        $unit = (string) ($rule['unit'] ?? '');

        // Critical thresholds are evaluated first so they win over warnings.
        foreach ([Status::CRIT => 'crit', Status::WARN => 'warn'] as $status => $prefix) {
            $above = $rule[$prefix . '_above'] ?? null;
            if (is_numeric($above) && $value > (float) $above) {
                return $this->finding($name, $status, sprintf(
                    '%s: %s (> %s%s)',
                    $name,
                    $rendered,
                    $this->format((float) $above),
                    $unit
                ));
            }

            $below = $rule[$prefix . '_below'] ?? null;
            if (is_numeric($below) && $value < (float) $below) {
                return $this->finding($name, $status, sprintf(
                    '%s: %s (< %s%s)',
                    $name,
                    $rendered,
                    $this->format((float) $below),
                    $unit
                ));
            }
        }

        return null;
    }

    /**
     * @return array{name: string, status: string, message: string}
     */
    private function finding(string $name, string $status, string $message): array
    {
        return ['name' => $name, 'status' => strtoupper($status), 'message' => $message];
    }

    /**
     * Turns a user-supplied pattern into a PCRE expression.
     *
     * Patterns are written without delimiters (as in `grep -E`), matching is
     * multiline so `^`/`$` anchor to a line, and case is ignored by default —
     * the same convention the `ssh_log` checker uses.
     */
    private function compile(string $pattern, bool $ignoreCase): string
    {
        return '/' . str_replace('/', '\/', $pattern) . '/m' . ($ignoreCase ? 'i' : '');
    }

    /**
     * Reduces several matches to one number.
     *
     * The default depends on the thresholds: a rule that only guards a lower
     * bound (free disk, free memory) cares about the smallest value, everything
     * else about the largest.
     *
     * @param list<float>          $values
     * @param array<string, mixed> $rule
     */
    private function aggregate(array $values, array $rule): float
    {
        $mode = strtolower(trim((string) ($rule['aggregate'] ?? '')));

        if (!in_array($mode, self::AGGREGATES, true)) {
            $onlyLowerBounds = !isset($rule['warn_above']) && !isset($rule['crit_above'])
                && (isset($rule['warn_below']) || isset($rule['crit_below']));

            $mode = $onlyLowerBounds ? 'min' : 'max';
        }

        return match ($mode) {
            'min'   => min($values),
            'sum'   => array_sum($values),
            'avg'   => array_sum($values) / count($values),
            'first' => $values[0],
            default => max($values),
        };
    }

    /**
     * Prints a measurement without trailing zeros: 96 stays 96, 0.27 stays 0.27.
     */
    private function format(float $value): string
    {
        if (abs($value - round($value)) < 0.0001) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
