<?php

declare(strict_types=1);

namespace EidCloud\VideoPipeline\Stages;

use EidCloud\VideoPipeline\FFmpegRunner;

class AudioExtractor implements StageInterface
{
    private FFmpegRunner $ffmpeg;

    public function __construct(?FFmpegRunner $ffmpeg = null)
    {
        $this->ffmpeg = $ffmpeg ?? new FFmpegRunner();
    }

    public function getName(): string
    {
        return 'audio_extractor';
    }

    public function process(PipelineContext $context): PipelineContext
    {
        $inputVideo = $context->getInputVideo();
        if (!file_exists($inputVideo)) {
            throw new \InvalidArgumentException("Input video file does not exist: {$inputVideo}");
        }

        $outPath = $context->getOption('audio_output') 
            ?? $context->getWorkingDirectory() . DIRECTORY_SEPARATOR . 'extracted_audio.wav';

        $this->extract($inputVideo, $outPath);

        $context->set('extracted_audio', $outPath);
        return $context;
    }

    /**
     * Extracts high-fidelity audio stream from input video to WAV format.
     */
    public function extract(string $videoPath, string $outputPath, int $sampleRate = 16000, int $channels = 1): string
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $args = [
            '-y',
            '-i', $videoPath,
            '-vn',
            '-acodec', 'pcm_s16le',
            '-ar', (string)$sampleRate,
            '-ac', (string)$channels,
            $outputPath
        ];

        $result = $this->ffmpeg->run($args);
        if ($result['exitCode'] !== 0) {
            throw new \RuntimeException("FFmpeg audio extraction failed: " . $result['stderr']);
        }

        return $outputPath;
    }
}
