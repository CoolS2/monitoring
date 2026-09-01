<?php

namespace App\Alert;

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
            "%s <b>Check Failed: %s</b>\n\n" .
            "<b>Type:</b> %s\n" .
            "<b>Error:</b> <code>%s</code>\n",
            $this->severityEmoji($severity),
            $this->escape($checkKey),
            $this->escape(strtoupper($type)),
            $this->escape($errorMsg)
        );

        if ($responseTime !== null) {
            $html .= sprintf("<b>Response Time:</b> %.3f sec\n", $responseTime);
        }

        $html .= sprintf("<b>Time:</b> %s\n", $this->now());
        $html .= $this->renderAnalysis($llmAnalysis, $severity);

        $this->send(trim($html));
    }

    /**
     * Sends a service recovery notification.
     */
    public function sendRecovery(string $checkKey, string $type, ?float $downtimeMinutes = null): void
    {
        $html = sprintf(
            "✅ <b>Service Restored: %s</b>\n\n" .
            "<b>Type:</b> %s\n" .
            "<b>Status:</b> Success (OK)\n" .
            "<b>Time:</b> %s\n",
            $this->escape($checkKey),
            $this->escape(strtoupper($type)),
            $this->now()
        );

        if ($downtimeMinutes !== null) {
            $html .= sprintf("<b>Downtime Duration:</b> %.1f min\n", $downtimeMinutes);
        }

        $this->send($html);
    }

    /**
     * Sends a compact digest for a server health report: the per-section
     * statuses plus the LLM's short analysis of what they mean.
     *
     * @param array<string, string> $statuses Section label => reported status
     * @param list<string>          $findings Threshold breaches detected in the output
     */
    public function sendServerReport(
        string $title,
        string $worstStatus,
        array $statuses,
        array $findings = [],
        ?array $llmAnalysis = null
    ): void {
        $severity = $llmAnalysis['severity'] ?? ($worstStatus === 'OK' ? 'LOW' : 'MEDIUM');

        $html = sprintf(
            "%s <b>Server Report: %s</b>\n\n<b>Overall:</b> <code>%s</code>\n<b>Time:</b> %s\n",
            $this->statusEmoji($worstStatus),
            $this->escape($title),
            $this->escape($worstStatus),
            $this->now()
        );

        if (!empty($statuses)) {
            $html .= "\n<b>Sections:</b>\n";
            foreach ($statuses as $label => $status) {
                $html .= sprintf(
                    "%s <code>%s</code> — %s\n",
                    $this->statusEmoji((string) $status),
                    $this->escape((string) $label),
                    $this->escape((string) $status)
                );
            }
        }

        if (!empty($findings)) {
            $html .= "\n<b>Detected issues:</b>\n";
            foreach (array_slice($findings, 0, self::MAX_FINDINGS) as $finding) {
                $html .= sprintf("• %s\n", $this->escape((string) $finding));
            }

            $remaining = count($findings) - self::MAX_FINDINGS;
            if ($remaining > 0) {
                $html .= sprintf("<i>…and %d more</i>\n", $remaining);
            }
        }

        $html .= $this->renderAnalysis($llmAnalysis, $severity);

        $this->send(trim($html));
    }

    /**
     * Sends the daily compiled metrics report.
     */
    public function sendDailySummary(array $stats): void
    {
        $html = "📊 <b>Daily Monitoring Report Summary</b>\n" .
            sprintf("<i>Compiled at: %s</i>\n\n", $this->now()) .
            sprintf("• <b>Total check runs:</b> %d\n", $stats['total_runs'] ?? 0) .
            sprintf("• <b>Successful runs:</b> %d (%d%%)\n", $stats['success_runs'] ?? 0, $stats['success_rate'] ?? 0) .
            sprintf("• <b>Total failures logged:</b> %d\n", $stats['failed_runs'] ?? 0);

        if (!empty($stats['failed_keys'])) {
            $html .= "\n⚠️ <b>Incident list (checks that failed at least once):</b>\n";
            foreach ($stats['failed_keys'] as $key => $failCount) {
                $html .= sprintf("• <code>%s</code>: failed %d times\n", $this->escape((string) $key), $failCount);
            }
        } else {
            $html .= "\n🎉 <b>All services were 100% healthy today!</b>\n";
        }

        $this->send($html);
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
