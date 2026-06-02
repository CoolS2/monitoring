<?php

namespace App\Alert;

use App\Config\TelegramConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramNotifier
{
    public function __construct(
        private HttpClientInterface $client,
        private TelegramConfig $config
    ) {}

    /**
     * Sends a raw message to the configured Telegram chat.
     */
    public function send(string $text): void
    {
        $url = sprintf('https://api.telegram.org/bot%s/sendMessage', $this->config->token);

        try {
            $this->client->request('POST', $url, [
                'json' => [
                    'chat_id' => $this->config->chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true
                ]
            ]);
        } catch (\Throwable $e) {
            // Log or ignore alert delivery failures to prevent crashing the monitoring loop
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
        $emoji = '🚨';
        $severity = $llmAnalysis['severity'] ?? 'HIGH';
        if ($severity === 'CRITICAL') {
            $emoji = '🔥';
        } elseif ($severity === 'LOW') {
            $emoji = '⚠️';
        }

        $html = sprintf(
            "%s <b>Check Failed: %s</b>\n\n" .
            "<b>Type:</b> %s\n" .
            "<b>Error:</b> <code>%s</code>\n",
            $emoji,
            $this->escape($checkKey),
            $this->escape(strtoupper($type)),
            $this->escape($errorMsg)
        );

        if ($responseTime !== null) {
            $html .= sprintf("<b>Response Time:</b> %.3f sec\n", $responseTime);
        }

        $html .= sprintf("<b>Time:</b> %s\n\n", date('Y-m-d H:i:s'));

        if ($llmAnalysis && isset($llmAnalysis['summary'])) {
            $html .= "🧠 <b>LLM Diagnostic Analysis:</b>\n";
            $html .= sprintf("<b>Summary:</b> %s\n", $this->escape($llmAnalysis['summary']));
            $html .= sprintf("<b>Probable Cause:</b> %s\n", $this->escape($llmAnalysis['probable_cause']));
            $html .= sprintf("<b>Assigned Severity:</b> <code>%s</code>\n", $this->escape($severity));
            
            if (!empty($llmAnalysis['recommendations'])) {
                $html .= "<b>Recommendations:</b>\n";
                foreach ($llmAnalysis['recommendations'] as $rec) {
                    $html .= sprintf("• %s\n", $this->escape($rec));
                }
            }
        }

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
            date('Y-m-d H:i:s')
        );

        if ($downtimeMinutes !== null) {
            $html .= sprintf("<b>Downtime Duration:</b> %.1f min\n", $downtimeMinutes);
        }

        $this->send($html);
    }

    /**
     * Sends the daily compiled metrics report.
     */
    public function sendDailySummary(array $stats): void
    {
        $html = "📊 <b>Daily Monitoring Report Summary</b>\n" .
            sprintf("<i>Compiled at: %s</i>\n\n", date('Y-m-d H:i:s')) .
            sprintf("• <b>Total check runs:</b> %d\n", $stats['total_runs']) .
            sprintf("• <b>Successful runs:</b> %d (%d%%)\n", $stats['success_runs'], $stats['success_rate']) .
            sprintf("• <b>Total failures logged:</b> %d\n", $stats['failed_runs']);

        if (!empty($stats['failed_keys'])) {
            $html .= "\n⚠️ <b>Incident list (checks that failed at least once):</b>\n";
            foreach ($stats['failed_keys'] as $key => $failCount) {
                $html .= sprintf("• <code>%s</code>: failed %d times\n", $this->escape($key), $failCount);
            }
        } else {
            $html .= "\n🎉 <b>All services were 100%% healthy today!</b>\n";
        }

        $this->send($html);
    }

    /**
     * Helper to escape HTML tags.
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
