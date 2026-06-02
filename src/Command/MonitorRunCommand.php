namespace App\Command;

use App\Checker\HttpChecker;
use App\Checker\SshLogChecker;
use App\Alert\TelegramNotifier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;

class MonitorRunCommand extends Command
{
    protected static $defaultName = 'app:monitor:run';

    public function __construct(
        private HttpChecker $http,
        private SshLogChecker $ssh,
        private TelegramNotifier $tg
    ) {
        parent::__construct();
    }

    protected function execute($input, $output): int
    {
        $cfg = Yaml::parseFile(__DIR__ . '/../../config/monitors.yaml');

        foreach ($cfg['checks'] as $name => $check) {

            if ($check['type'] === 'http') {
                [$ok, $msg] = $this->http->check($check);
            } else {
                [$ok, $msg] = $this->ssh->check($check);
            }

            if (!$ok) {
                $this->tg->send(
                    $cfg['telegram']['token'],
                    $cfg['telegram']['chat_id'],
                    "🚨 $name: $msg"
                );
            }
        }

        return Command::SUCCESS;
    }
}
