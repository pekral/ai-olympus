<?php

declare(strict_types = 1);

namespace Tests\Support;

use Symfony\Component\Process\Process;

final class ReportCheckRun
{

    public static function over(string $script, string $source, string ...$arguments): Process
    {
        $packageDir = dirname(__DIR__, 2);
        $file = sys_get_temp_dir() . '/ai-olympus-report-check-' . bin2hex(random_bytes(6));
        file_put_contents($file, $source);

        $process = new Process(['php', $packageDir . '/' . $script, ...$arguments, $file], $packageDir);

        try {
            $process->run();
        } finally {
            unlink($file);
        }

        return $process;
    }

}
