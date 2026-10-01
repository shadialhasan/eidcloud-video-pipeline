<?php

declare(strict_types=1);

namespace EidCloud\VideoPipeline;

class FFmpegRunner
{
    private string $ffmpegBinary;
    private string $ffprobeBinary;

    public function __construct(?string $ffmpegBinary = null, ?string $ffprobeBinary = null)
    {
        $this->ffmpegBinary = $ffmpegBinary ?? (getenv('FFMPEG_BINARY') ?: 'ffmpeg');
        $this->ffprobeBinary = $ffprobeBinary ?? (getenv('FFPROBE_BINARY') ?: 'ffprobe');
    }

    public function getFFmpegBinary(): string
    {
        return $this->ffmpegBinary;
    }

    public function getFFprobeBinary(): string
    {
        return $this->ffprobeBinary;
    }

    /**
     * Executes an FFmpeg command safely.
     *
     * @param array<int, string> $args
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public function run(array $args): array
    {
        $escapedArgs = array_map('escapeshellarg', $args);
        $cmd = escapeshellarg($this->ffmpegBinary) . ' ' . implode(' ', $escapedArgs);

        return $this->executeCommand($cmd);
    }

    /**
     * Executes an FFprobe command safely.
     *
     * @param array<int, string> $args
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public function runProbe(array $args): array
    {
        $escapedArgs = array_map('escapeshellarg', $args);
        $cmd = escapeshellarg($this->ffprobeBinary) . ' ' . implode(' ', $escapedArgs);

        return $this->executeCommand($cmd);
    }

    /**
     * Internal command execution with stdout & stderr capture.
     */
    protected function executeCommand(string $cmd): array
    {
        $stdoutFile = tmpfile();
        $stderrFile = tmpfile();

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => $stdoutFile,
            2 => $stderrFile,
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to execute process: {$cmd}");
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $exitCode = proc_close($process);

        rewind($stdoutFile);
        $stdout = stream_get_contents($stdoutFile) ?: '';
        fclose($stdoutFile);

        rewind($stderrFile);
        $stderr = stream_get_contents($stderrFile) ?: '';
        fclose($stderrFile);

        return [
            'exitCode' => $exitCode,
            'stdout' => (string)$stdout,
            'stderr' => (string)$stderr,
        ];
    }

    /**
     * Check if FFmpeg binary is available on the system.
     */
    public function isAvailable(): bool
    {
        try {
            $res = $this->run(['-version']);
            return $res['exitCode'] === 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
