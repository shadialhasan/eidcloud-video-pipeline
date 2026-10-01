<?php

declare(strict_types=1);

namespace EidCloud\VideoPipeline\Stages;

use EidCloud\VideoPipeline\FFmpegRunner;

class SubtitleMuxer implements StageInterface
{
    private FFmpegRunner $ffmpeg;

    public function __construct(?FFmpegRunner $ffmpeg = null)
    {
        $this->ffmpeg = $ffmpeg ?? new FFmpegRunner();
    }

    public function getName(): string
    {
        return 'subtitle_muxer';
    }

    public function process(PipelineContext $context): PipelineContext
    {
        $segments = $context->get('translated_segments') 
            ?? $context->get('diarized_segments') 
            ?? $context->get('transcription_segments', []);

        $workDir = $context->getWorkingDirectory();
        $targetLang = $context->getTargetLanguage();

        // 1. Generate SRT & VTT subtitles
        $srtPath = $workDir . DIRECTORY_SEPARATOR . "subtitles_{$targetLang}.srt";
        $vttPath = $workDir . DIRECTORY_SEPARATOR . "subtitles_{$targetLang}.vtt";

        $this->generateSrt($segments, $srtPath);
        $this->generateVtt($segments, $vttPath);

        $context->set('subtitles_srt', $srtPath);
        $context->set('subtitles_vtt', $vttPath);

        // 2. Mux or burn into output video if final video is requested
        $inputVideo = $context->getInputVideo();
        $mixedAudio = $context->get('mixed_audio');
        $outputVideo = $context->getOption('output_video') 
            ?? $workDir . DIRECTORY_SEPARATOR . "final_output_{$targetLang}.mp4";

        $burnIn = (bool)$context->getOption('burn_subtitles', false);

        if ($mixedAudio && file_exists($mixedAudio)) {
            $this->muxVideoAudioAndSubtitles($inputVideo, $mixedAudio, $srtPath, $outputVideo, $targetLang, $burnIn);
        } else {
            $this->muxSubtitlesOnly($inputVideo, $srtPath, $outputVideo, $targetLang);
        }

        $context->set('final_video', $outputVideo);
        return $context;
    }

    /**
     * Formats seconds into SRT timestamp format: HH:MM:SS,mmm
     */
    public static function formatSrtTimestamp(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $remainder = $seconds - ($hours * 3600);
        $minutes = floor($remainder / 60);
        $secs = floor($remainder - ($minutes * 60));
        $millis = floor(($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $millis);
    }

    /**
     * Formats seconds into WebVTT timestamp format: HH:MM:SS.mmm
     */
    public static function formatVttTimestamp(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $remainder = $seconds - ($hours * 3600);
        $minutes = floor($remainder / 60);
        $secs = floor($remainder - ($minutes * 60));
        $millis = floor(($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $millis);
    }

    /**
     * Generates an SRT subtitle file from segments.
     *
     * @param array<int, array<string, mixed>> $segments
     */
    public function generateSrt(array $segments, string $outputPath): string
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $content = '';
        foreach ($segments as $index => $segment) {
            $num = $index + 1;
            $start = (float)($segment['start'] ?? 0.0);
            $end = (float)($segment['end'] ?? ($start + 2.0));
            $text = $segment['translated_text'] ?? $segment['text'] ?? '';

            $content .= "{$num}\n";
            $content .= self::formatSrtTimestamp($start) . ' --> ' . self::formatSrtTimestamp($end) . "\n";
            $content .= trim($text) . "\n\n";
        }

        file_put_contents($outputPath, trim($content) . "\n");
        return $outputPath;
    }

    /**
     * Generates a WebVTT subtitle file from segments.
     *
     * @param array<int, array<string, mixed>> $segments
     */
    public function generateVtt(array $segments, string $outputPath): string
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $content = "WEBVTT\n\n";
        foreach ($segments as $index => $segment) {
            $num = $index + 1;
            $start = (float)($segment['start'] ?? 0.0);
            $end = (float)($segment['end'] ?? ($start + 2.0));
            $text = $segment['translated_text'] ?? $segment['text'] ?? '';

            $content .= "{$num}\n";
            $content .= self::formatVttTimestamp($start) . ' --> ' . self::formatVttTimestamp($end) . "\n";
            $content .= trim($text) . "\n\n";
        }

        file_put_contents($outputPath, trim($content) . "\n");
        return $outputPath;
    }

    /**
     * Multiplexes subtitle track into MP4 video container without re-encoding video.
     */
    public function muxSubtitlesOnly(string $videoPath, string $srtPath, string $outputPath, string $language = 'ara'): string
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $args = [
            '-y',
            '-i', $videoPath,
            '-i', $srtPath,
            '-c:v', 'copy',
            '-c:a', 'copy',
            '-c:s', 'mov_text',
            '-metadata:s:s:0', "language={$language}",
            $outputPath
        ];

        $res = $this->ffmpeg->run($args);
        if ($res['exitCode'] !== 0) {
            throw new \RuntimeException("Failed to mux subtitles into video: " . $res['stderr']);
        }

        return $outputPath;
    }

    /**
     * Multiplexes or burns translated subtitles and dubbed audio into final video container.
     */
    public function muxVideoAudioAndSubtitles(
        string $videoPath,
        string $audioPath,
        string $srtPath,
        string $outputPath,
        string $language = 'ara',
        bool $burnIn = false
    ): string {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        if ($burnIn) {
            // Re-encode video burning in subtitles filter
            // Note: escapeshellarg / path formatting for FFmpeg subtitles filter
            $escapedSrt = str_replace(['\\', ':'], ['/', '\\:'], $srtPath);
            $args = [
                '-y',
                '-i', $videoPath,
                '-i', $audioPath,
                '-vf', "subtitles='{$escapedSrt}'",
                '-c:a', 'aac',
                '-b:a', '192k',
                '-shortest',
                $outputPath
            ];
        } else {
            // Multiplex soft subtitles into MP4 container (mov_text)
            $args = [
                '-y',
                '-i', $videoPath,
                '-i', $audioPath,
                '-i', $srtPath,
                '-map', '0:v:0',
                '-map', '1:a:0',
                '-map', '2:0',
                '-c:v', 'copy',
                '-c:a', 'aac',
                '-b:a', '192k',
                '-c:s', 'mov_text',
                '-metadata:s:s:0', "language={$language}",
                '-shortest',
                $outputPath
            ];
        }

        $res = $this->ffmpeg->run($args);
        if ($res['exitCode'] !== 0) {
            // Fallback: multiplex without subtitles stream if format unsupported
            $fallbackArgs = [
                '-y',
                '-i', $videoPath,
                '-i', $audioPath,
                '-c:v', 'copy',
                '-c:a', 'aac',
                '-b:a', '192k',
                '-shortest',
                $outputPath
            ];
            $fbRes = $this->ffmpeg->run($fallbackArgs);
            if ($fbRes['exitCode'] !== 0) {
                throw new \RuntimeException("Failed to mux video and audio: " . $res['stderr']);
            }
        }

        return $outputPath;
    }
}
