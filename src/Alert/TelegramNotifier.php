<?php

namespace App\Alert;

use App\Checker\Status;
use App\Config\TelegramConfig;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramNotifier
{
    /** Telegram API hard limit for message text length */
    private const MAX_MESSAGE_LENGTH = 4096;

    /** Detected issues listed in a digest before the list is summarised. */
    private const MAX_FINDINGS = 8;

    /** Inline tags used by the messages built here, closed on truncation. */
    private const CLOSEABLE_TAGS = ['b', 'i', 'u', 's', 'code', 'pre', 'a'];

    public function __construct(
        private HttpClientInterface $client,
        private TelegramConfig $config,
        private LoggerInterface $telegramLogger
    ) {}

    /**
     * Sends a raw message to the configured Telegram chat.
     * Automatically truncates messages that exceed Telegram's 4096-character limit.
     */
    public function send(string $text): void
    {
        $text = $this->truncate($text);

        $url = sprintf('https://api.telegram.org/bot%s/sendMessage', $this->config->token);

        $this->telegramLogger->info('Sending Telegram notification request.', [
            'chat_id' => $this->config->chatId,
        ]);

        try {
            $response = $this->client->request('POST', $url, [
                'json' => [
                    'chat_id'                  => $this->config->chatId,
                    'text'                     => $text,
                    'parse_mode'               => 'HTML',
                    'disable_web_page_preview' => true,
                ]
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $content = $response->getContent(false);
                throw new \RuntimeException(sprintf('Telegram API returned status code %d: %s', $statusCode, $content));
            }

            $this->telegramLogger->info('Telegram notification sent successfully.', [
                'chat_id' => $this->config->chatId,
            ]);
        } catch (\Throwable $e) {
            $this->telegramLogger->error('Telegram notification delivery failed.', [
                'chat_id' => $this->config->chatId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sends a detailed check failure notification, including optional LLM diagnostics.
     */
    public function sendAlert(
        string $checkKey,
        string $type,
        string $errorMsg,
        ?float $responseTime = null,
        ?array $llmAnalysis = null
    ): void {
        $severity = $llmAnalysis['severity'] ?? 'HIGH';

        $html = sprintf(
            "%s <b>Проверка не прошла: %s</b>\n\n" .
            "<b>Тип:</b> %s\n" .
            "<b>Ошибка:</b> <code>%s</code>\n",
            $this->severityEmoji($severity),
            $this->escape($checkKey),
            $this->escape(strtoupper($type)),
            $this->escape($errorMsg)
        );

        if ($responseTime !== null) {
            $html .= sprintf("<b>Время отклика:</b> %.3f с\n", $responseTime);
        }

        $html .= sprintf("<b>Время:</b> %s\n", $this->now());
        $html .= $this->renderAnalysis($llmAnalysis, $severity);

        $this->send(trim($html));
    }

    /**
     * Sends a service recovery notification.
     */
    public function sendRecovery(string $checkKey, string $type, ?float $downtimeMinutes = null): void
    {
        $html = sprintf(
            "✅ <b>Снова работает: %s</b>\n\n" .
            "<b>Тип:</b> %s\n" .
            "<b>Статус:</b> OK\n" .
            "<b>Время:</b> %s\n",
            $this->escape($checkKey),
            $this->escape(strtoupper($type)),
            $this->now()
        );

        if ($downtimeMinutes !== null) {
            $html .= sprintf("<b>Простой:</b> %.1f мин\n", $downtimeMinutes);
        }

        $this->send($html);
    }

    /**
     * Sends the server health digest: one block per section, carrying both the
     * section's verdict and every number its rules measured.
     *
     * The measurements are the point of the message — a digest that only says
     * "OK" is not worth the notification, so the values are printed whether or
     * not they breached a threshold, and breaches are repeated up top.
     *
     * @param array<string, string> $statuses Section label => reported status
     * @param list<string>          $findings Threshold breaches detected in the output
     * @param list<array{section?: string, name: string, status: string, value: string}> $metrics
     */
    public function sendServerReport(
        string $title,
        string $worstStatus,
        array $statuses,
        array $findings = [],
        array $metrics = [],
        ?array $llmAnalysis = null
    ): void {
        $severity = $llmAnalysis['severity'] ?? ($worstStatus === 'OK' ? 'LOW' : 'MEDIUM');

        $html = sprintf(
            "%s <b>Отчёт по серверу: %s</b>\n<b>Итог:</b> <code>%s</code>\n<b>Время:</b> %s\n",
            $this->statusEmoji($worstStatus),
            $this->escape($title),
            $this->escape($worstStatus),
            $this->now()
        );

        if (!empty($findings)) {
            $html .= "\n⚠️ <b>Требует внимания:</b>\n";
            foreach (array_slice($findings, 0, self::MAX_FINDINGS) as $finding) {
                $html .= sprintf("• %s\n", $this->escape((string) $finding));
            }

            $remaining = count($findings) - self::MAX_FINDINGS;
            if ($remaining > 0) {
                $html .= sprintf("<i>…и ещё %d</i>\n", $remaining);
            }
        }

        $bySection = [];
        foreach ($metrics as $metric) {
            $bySection[(string) ($metric['section'] ?? '')][] = $metric;
        }

        foreach ($statuses as $label => $status) {
            $label = (string) $label;

            $html .= sprintf(
                "\n%s <b>%s</b> — <code>%s</code>\n",
                $this->statusEmoji((string) $status),
                $this->escape($label),
                $this->escape((string) $status)
            );

            foreach ($bySection[$label] ?? [] as $metric) {
                $html .= sprintf(
                    "%s %s: <b>%s</b>\n",
                    $this->statusEmoji((string) ($metric['status'] ?? Status::OK)),
                    $this->escape((string) $metric['name']),
                    $this->escape((string) $metric['value'])
                );
            }
        }

        $html .= $this->renderAnalysis($llmAnalysis, $severity);

        $this->send(trim($html));
    }

    /**
     * Sends the 24-hour recap: how each check behaved, not just how many times
     * something ran.
     *
     * @param array{
     *     total_runs?: int, success_runs?: int, failed_runs?: int, success_rate?: int,
     *     failed_keys?: array<string, int>,
     *     checks?: list<array{key: string, total: int, ok: int, failed: int, uptime: float, avg_time: ?float, max_time: ?float}>
     * } $stats
     */
    public function sendDailySummary(array $stats): void
    {
        $html = "📊 <b>Итоги за 24 часа</b>\n" .
            sprintf("<i>%s</i>\n\n", $this->now()) .
            sprintf(
                "Проверок: <b>%d</b> · сбоев: <b>%d</b> · успешность: <b>%d%%</b>\n",
                $stats['total_runs'] ?? 0,
                $stats['failed_runs'] ?? 0,
                $stats['success_rate'] ?? 0
            );

        foreach ($stats['checks'] ?? [] as $check) {
            $html .= sprintf(
                "\n%s <code>%s</code> — доступность <b>%s%%</b> (%d из %d)\n",
                ($check['failed'] ?? 0) > 0 ? '⚠️' : '✅',
                $this->escape((string) $check['key']),
                $this->formatNumber((float) ($check['uptime'] ?? 0)),
                $check['ok'] ?? 0,
                $check['total'] ?? 0
            );

            if (($check['avg_time'] ?? null) !== null) {
                $html .= sprintf(
                    "отклик: сред. %s с · макс. %s с\n",
                    $this->formatNumber((float) $check['avg_time']),
                    $this->formatNumber((float) ($check['max_time'] ?? $check['avg_time']))
                );
            }
        }

        if (empty($stats['failed_keys'])) {
            $html .= "\n🎉 <b>За сутки ни одного сбоя.</b>\n";
        }

        $this->send($html);
    }

    /**
     * Prints a measurement without trailing zeros: 100 stays 100, 99.8 stays 99.8.
     */
    private function formatNumber(float $value): string
    {
        if (abs($value - round($value)) < 0.0001) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * Renders the shared "LLM diagnostics" block appended to alerts and reports.
     */
    private function renderAnalysis(?array $llmAnalysis, string $severity): string
    {
        if (empty($llmAnalysis) || !isset($llmAnalysis['summary'])) {
            return '';
        }

        $html = "\n🧠 <b>LLM Diagnostic Analysis:</b>\n";
        $html .= sprintf("<b>Summary:</b> %s\n", $this->escape((string) $llmAnalysis['summary']));

        if (!empty($llmAnalysis['probable_cause'])) {
            $html .= sprintf("<b>Probable Cause:</b> %s\n", $this->escape((string) $llmAnalysis['probable_cause']));
        }

        $html .= sprintf("<b>Assigned Severity:</b> <code>%s</code>\n", $this->escape($severity));

        if (!empty($llmAnalysis['recommendations'])) {
            $html .= "<b>Recommendations:</b>\n";
            foreach ($llmAnalysis['recommendations'] as $rec) {
                $html .= sprintf("• %s\n", $this->escape((string) $rec));
            }
        }

        return $html;
    }

    private function severityEmoji(string $severity): string
    {
        return match (strtoupper($severity)) {
            'CRITICAL' => '🔥',
            'LOW'      => '⚠️',
            default    => '🚨',
        };
    }

    private function statusEmoji(string $status): string
    {
        return match (strtoupper($status)) {
            'OK'                          => '✅',
            'WARN', 'WARNING'             => '⚠️',
            'CRIT', 'CRITICAL', 'FAIL'    => '🔥',
            'ERROR'                       => '🚨',
            default                       => 'ℹ️',
        };
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s T');
    }

    /**
     * Truncates a message to fit within Telegram's 4096-character limit.
     *
     * Cutting raw HTML can leave a half-written tag or entity behind, which
     * Telegram rejects with a parse error, so the cut is repaired and any tag
     * still open is closed before the truncation notice is appended.
     */
    private function truncate(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_MESSAGE_LENGTH) {
            return $text;
        }

        $suffix = "\n\n<i>[...truncated, message too long]</i>";

        // Reserve room for the suffix and for any closing tags we may re-add.
        $budget = self::MAX_MESSAGE_LENGTH - mb_strlen($suffix) - 64;
        $cut    = $this->repairFragment(mb_substr($text, 0, max(0, $budget)));

        return $cut . $this->closeOpenTags($cut) . $suffix;
    }

    /**
     * Drops a trailing partial tag ("<b" ) or partial entity ("&amp" ) left by a
     * blind character cut.
     */
    private function repairFragment(string $text): string
    {
        $lastOpen  = mb_strrpos($text, '<');
        $lastClose = mb_strrpos($text, '>');
        if ($lastOpen !== false && ($lastClose === false || $lastClose < $lastOpen)) {
            $text = mb_substr($text, 0, $lastOpen);
        }

        $lastAmp   = mb_strrpos($text, '&');
        $lastSemi  = mb_strrpos($text, ';');
        if ($lastAmp !== false && ($lastSemi === false || $lastSemi < $lastAmp)
            && mb_strlen($text) - $lastAmp <= 10) {
            $text = mb_substr($text, 0, $lastAmp);
        }

        return $text;
    }

    /**
     * Returns the closing tags needed to balance the given HTML fragment.
     */
    private function closeOpenTags(string $html): string
    {
        if (!preg_match_all('#</?([a-zA-Z]+)[^>]*>#', $html, $matches, PREG_SET_ORDER)) {
            return '';
        }

        $stack = [];
        foreach ($matches as $match) {
            $tag = strtolower($match[1]);
            if (!in_array($tag, self::CLOSEABLE_TAGS, true)) {
                continue;
            }

            if (str_starts_with($match[0], '</')) {
                $index = array_search($tag, array_reverse($stack, true), true);
                if ($index !== false) {
                    unset($stack[$index]);
                    $stack = array_values($stack);
                }
                continue;
            }

            $stack[] = $tag;
        }

        $closing = '';
        foreach (array_reverse($stack) as $tag) {
            $closing .= sprintf('</%s>', $tag);
        }

        return $closing;
    }

    /**
     * Escapes the three characters Telegram's HTML parse mode treats as markup.
     *
     * Quotes are deliberately left alone: Telegram does not require them to be
     * escaped and numeric entities such as &#039; render literally in some clients.
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
