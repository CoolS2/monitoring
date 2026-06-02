namespace App\Checker;

use Symfony\Component\Process\Process;

class SshLogChecker
{
    public function check(array $cfg): array
    {
        $cmd = sprintf(
            "ssh %s@%s 'tail -n 200 %s | grep -i \"%s\" | wc -l'",
            $cfg['user'],
            $cfg['host'],
            $cfg['file'],
            $cfg['grep']
        );

        $process = Process::fromShellCommandline($cmd);
        $process->run();

        if (!$process->isSuccessful()) {
            return [false, $process->getErrorOutput()];
        }

        $count = (int) trim($process->getOutput());

        return $count > 0
            ? [false, "$count matches"]
            : [true, "OK"];
    }
}
