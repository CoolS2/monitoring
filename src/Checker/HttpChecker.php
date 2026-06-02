namespace App\Checker;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class HttpChecker
{
    public function __construct(private HttpClientInterface $client) {}

    public function check(array $cfg): array
    {
        try {
            $res = $this->client->request('GET', $cfg['url'], [
                'timeout' => 5
            ]);

            $code = $res->getStatusCode();

            if (isset($cfg['expect_status']) && $code !== $cfg['expect_status']) {
                return [false, "HTTP $code"];
            }

            $body = $res->getContent(false);

            if (isset($cfg['expect_body_contains']) &&
                !str_contains($body, $cfg['expect_body_contains'])) {
                return [false, "Body mismatch"];
            }

            return [true, "OK"];

        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }
}
