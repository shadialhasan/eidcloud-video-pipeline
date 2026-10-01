<?php

declare(strict_types=1);

namespace EidCloud\VideoPipeline\Stages;

use EidCloud\VideoPipeline\FFmpegRunner;

class AudioMixer implements StageInterface
{
    private FFmpegRunner $ffmpeg;

    public function __construct(?FFmpegRunner $ffmpeg = null)
    {
        $this->ffmpeg = $ffmpeg ?? new FFmpegRunner();
    }

    public function getName(): string
    {
        return 'audio_mixer';
    }

    public function process(PipelineContext $context): PipelineContext
    {
        $backgroundAudio = $context->get('extracted_audio');
        $dubbedAudio = $context->get('dubbed_audio');

        if (!$backgroundAudio || !file_exists($backgroundAudio)) {
            throw new \RuntimeException("Original audio stream missing for ducking & mixing.");
        }
        if (!$dubbedAudio || !file_exists($dubbedAudio)) {
            throw new \RuntimeException("Dubbed audio stream missing for ducking & mixing.");
        }

        $duckingDb = (string)$context->getOption('ducking', '-12dB');
        $workDir = $context->getWorkingDirectory();
        $mixedOutput = $context->getOption('mixed_audio_output') 
            ?? $workDir . DIRECTORY_SEPARATOR . 'mixed_ducked_audio.wav';

        $this->mixWithDucking($backgroundAudio, $dubbedAudio, $mixedOutput, $duckingDb);

        $context->set('mixed_audio', $mixedOutput);
        return $context;
    }

    /**
     * Mixes background audio and dubbed foreground speech using sidechain compression (ducking).
     *
     * In sidechain ducking:
     * - [0:a] is background sound (music/original audio)
     * - [1:a] is dubbed voice
     * Sidechaincompressor on [0:a] is driven by [1:a], ducking volume down by specified dB when voice is present.
     */
    public function mixWithDucking(
        string $backgroundAudio,
        string $dubbedAudio,
        string $outputPath,
        string $duckingDb = '-12dB'
    ): string {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        // Parse dB value (e.g. "-12dB" -> ratio)
        $cleanDb = (float)str_ireplace('db', '', $duckingDb);
        // Calculate ratio: typical ducking threshold ~0.08, ratio derived from ducking amount
        $ratio = max(2.0, min(20.0, abs($cleanDb) / 2.0));

        $filterComplex = sprintf(
            "[0:a][1:a]sidechaincompress=threshold=0.05:ratio=%.1f:attack=20:release=350[bg_ducked];" .
            "[bg_ducked][1:a]amix=inputs=2:duration=first:dropout_transition=2[out]",
            $ratio
        );

        $args = [
            '-y',
            '-i', $backgroundAudio,
            '-i', $dubbedAudio,
            '-filter_complex', $filterComplex,
            '-map', '[out]',
            '-ar', '44100',
            '-ac', '2',
            $outputPath
        ];

        $res = $this->ffmpeg->run($args);
        if ($res['exitCode'] !== 0) {
            // Fallback simpler volume-adjusted mix if sidechain compressor fails on exotic inputs
            $fallbackFilter = "[0:a]volume=0.3[bg];[1:a]volume=1.0[fg];[bg][fg]amix=inputs=2:duration=first[out]";
            $fallbackRes = $this->ffmpeg->run([
                '-y',
                '-i', $backgroundAudio,
                '-i', $dubbedAudio,
                '-filter_complex', $fallbackFilter,
                '-map', '[out]',
                '-ar', '44100',
                '-ac', '2',
                $outputPath
            ]);

            if ($fallbackRes['exitCode'] !== 0) {
                throw new \RuntimeException("Audio ducking & mixing failed: " . $res['stderr']);
            }
        }

        return $outputPath;
    }

    /**
     * Mixes video with dubbed audio track, applying ducking to the video's embedded audio track.
     */
    public function mixVideoWithDubbedAudio(
        string $videoPath,
        string $dubbedAudioPath,
        string $outputPath,
        string $duckingDb = '-12dB'
    ): string {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $cleanDb = (float)str_ireplace('db', '', $duckingDb);
        $ratio = max(2.0, min(20.0, abs($cleanDb) / 2.0));

        $filterComplex = sprintf(
            "[0:a][1:a]sidechaincompress=threshold=0.05:ratio=%.1f:attack=20:release=350[ducked];" .
            "[ducked][1:a]amix=inputs=2:duration=first:dropout_transition=2[audio_out]",
            $ratio
        );

        $args = [
            '-y',
            '-i', $videoPath,
            '-i', $dubbedAudioPath,
            '-filter_complex', $filterComplex,
            '-map', '0:v:0',
            '-map', '[audio_out]',
            '-c:v', 'copy',
            '-c:a', 'aac',
            '-b:a', '192k',
            '-shortest',
            $outputPath
        ];

        $res = $this->ffmpeg->run($args);
        if ($res['exitCode'] !== 0) {
            // If copying video stream fails or no video audio, try re-encoding audio directly with voice
            $argsFallback = [
                '-y',
                '-i', $videoPath,
                '-i', $dubbedAudioPath,
                '-map', '0:v:0',
                '-map', '1:a:0',
                '-c:v', 'copy',
                '-c:a', 'aac',
                '-b:a', '192k',
                '-shortest',
                $outputPath
            ];
            $fbRes = $this->ffmpeg->run($argsFallback);
            if ($fbRes['exitCode'] !== 0) {
                throw new \RuntimeException("Mixing video with audio failed: " . $res['stderr']);
            }
        }

        return $outputPath;
    }
}
