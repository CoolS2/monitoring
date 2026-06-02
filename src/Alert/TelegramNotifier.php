namespace App\Alert;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramNotifier
{
    public function __construct(private HttpClientInterface $client) {}

    public function send(string $token, string $chatId, string $text): void
    {
        $this->client->request(
            'POST',
            "https://api.telegram.org/bot$token/sendMessage",
            [
                'json' => [
                    'chat_id' => $chatId,
                    'text' => $text,
                ]
            ]
        );
    }
}
