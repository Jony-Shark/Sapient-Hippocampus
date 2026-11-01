<?php

namespace Sapient\Hippocampus;

class HippocampusRunner {
    private $pid = null;

    /**
     * Attempts to start the Hippocampus python server in the background.
     * Returns true if successfully started, false otherwise.
     */
    public function start(string $host, int $port): bool {
        $baseDir = dirname(__DIR__); // The 'hippocampus' plugin dir
        $venvPython = $baseDir . '/venv/bin/python'; // Alternatively uvicorn
        $uvicorn = $baseDir . '/venv/bin/uvicorn';

        if (!file_exists($uvicorn)) {
            // Cannot autostart if venv/uvicorn is absent
            return false;
        }

        // We use a PID file to completely bypass PHP's stream waiting issues.
        $logFile = $baseDir . '/log/server.log';
        $pidFile = $baseDir . '/log/server.pid';

        $cmd = sprintf(
            '(cd %s && nohup %s server:app --host %s --port %d > %s 2>&1 < /dev/null & echo $! > %s) > /dev/null 2>&1 &',
            escapeshellarg($baseDir),
            escapeshellarg($uvicorn),
            escapeshellarg($host),
            $port,
            escapeshellarg($logFile),
            escapeshellarg($pidFile)
        );

        exec($cmd);

        // Give it a tiny moment to write the file
        usleep(100000); 

        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            if (!empty($pid) && is_numeric($pid)) {
                $this->pid = (int) $pid;
                return true;
            }
        }

        return false;
    }

    /**
     * Stops the background server if we started it.
     */
    public function stop(): void {
        if ($this->pid) {
            exec("kill " . $this->pid . " >/dev/null 2>&1");
            $this->pid = null;
        }
    }
}
