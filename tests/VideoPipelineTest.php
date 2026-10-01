<?php

declare(strict_types=1);

namespace EidCloud\VideoPipeline\Tests;

use EidCloud\VideoPipeline\VideoPipeline;
use EidCloud\VideoPipeline\FFmpegRunner;
use EidCloud\VideoPipeline\Stages\PipelineContext;
use EidCloud\VideoPipeline\Stages\AudioExtractor;
use EidCloud\VideoPipeline\Stages\SpeechToText;
use EidCloud\VideoPipeline\Stages\SpeakerDiarization;
use EidCloud\VideoPipeline\Stages\DialogueTranslator;
use EidCloud\VideoPipeline\Stages\TTSSynthesizer;
use EidCloud\VideoPipeline\Stages\AudioMixer;
use EidCloud\VideoPipeline\Stages\SubtitleMuxer;

class VideoPipelineTest
{
    private string $tempDir;
    private FFmpegRunner $ffmpeg;

    public function __construct()
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eidcloud_test_' . uniqid();
        @mkdir($this->tempDir, 0777, true);
        $this->ffmpeg = new FFmpegRunner();
    }

    public function __destruct()
    {
        $this->cleanDirectory($this->tempDir);
    }

    private function cleanDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = scandir($dir);
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->cleanDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \AssertionError("Assertion failed: {$message}");
        }
    }

    /**
     * Helper to generate a small synthetic video with audio for testing.
     */
    private function generateSampleVideo(string $outputPath, float $duration = 3.0): void
    {
        $args = [
            '-y',
            '-f', 'lavfi',
            '-i', sprintf('testsrc=duration=%.1f:size=320x240:rate=24', $duration),
            '-f', 'lavfi',
            '-i', sprintf('sine=frequency=440:duration=%.1f', $duration),
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-b:a', '128k',
            $outputPath
        ];
        $res = $this->ffmpeg->run($args);
        $this->assert($res['exitCode'] === 0, "Generating sample test video failed: " . $res['stderr']);
    }

    public function testFFmpegAvailability(): void
    {
        $this->assert($this->ffmpeg->isAvailable(), "FFmpeg should be installed and reachable in PATH.");
    }

    public function testPipelineContext(): void
    {
        $ctx = new PipelineContext('test.mp4', 'en', 'ar', 'female', null, ['ducking' => '-15dB']);
        $this->assert($ctx->getInputVideo() === 'test.mp4', "Input video matches");
        $this->assert($ctx->getSourceLanguage() === 'en', "Source language matches");
        $this->assert($ctx->getTargetLanguage() === 'ar', "Target language matches");
        $this->assert($ctx->getVoice() === 'female', "Voice matches");
        $this->assert($ctx->getOption('ducking') === '-15dB', "Option matches");

        $ctx->set('status', 'processing');
        $this->assert($ctx->has('status'), "Context has key");
        $this->assert($ctx->get('status') === 'processing', "Context key retrieval matches");
    }

    public function testAudioExtraction(): void
    {
        $video = $this->tempDir . DIRECTORY_SEPARATOR . 'test_extract.mp4';
        $audio = $this->tempDir . DIRECTORY_SEPARATOR . 'test_extract.wav';
        $this->generateSampleVideo($video, 2.0);

        $extractor = new AudioExtractor($this->ffmpeg);
        $out = $extractor->extract($video, $audio, 16000, 1);

        $this->assert(file_exists($out), "Extracted audio file should exist");
        $this->assert(filesize($out) > 1000, "Extracted audio should not be empty");
    }

    public function testSpeechToTextAndDiarization(): void
    {
        $audio = $this->tempDir . DIRECTORY_SEPARATOR . 'sample_stt.wav';
        // Generate 4s sine wave
        $this->ffmpeg->run([
            '-y', '-f', 'lavfi', '-i', 'sine=frequency=300:duration=4', '-ar', '16000', '-ac', '1', $audio
        ]);

        $ctx = new PipelineContext('dummy.mp4', 'en', 'ar');
        $ctx->set('extracted_audio', $audio);

        $stt = new SpeechToText();
        $ctx = $stt->process($ctx);
        $segments = $ctx->get('transcription_segments');

        $this->assert(is_array($segments) && count($segments) > 0, "STT should produce segments");
        $this->assert(isset($segments[0]['text']), "Segment must have text");

        $diarization = new SpeakerDiarization();
        $ctx = $diarization->process($ctx);
        $diarized = $ctx->get('diarized_segments');

        $this->assert(isset($diarized[0]['speaker']), "Diarized segment must have speaker label");
    }

    public function testDialogueTranslation(): void
    {
        $ctx = new PipelineContext('dummy.mp4', 'en', 'ar');
        $ctx->set('diarized_segments', [
            [
                'id' => 1,
                'start' => 0.0,
                'end' => 3.0,
                'text' => 'Welcome to EidCloud intelligent video processing platform.'
            ]
        ]);

        $translator = new DialogueTranslator();
        $ctx = $translator->process($ctx);
        $translated = $ctx->get('translated_segments');

        $this->assert(isset($translated[0]['translated_text']), "Translated text must be present");
        $this->assert(
            $translated[0]['translated_text'] === 'مرحباً بكم في منصة عيد كلاود الذكية لمعالجة الفيديو.',
            "Translated text should match dictionary Arabic entry"
        );
    }

    public function testTTSSynthesisAndAssembly(): void
    {
        $ctx = new PipelineContext('dummy.mp4', 'en', 'ar', 'male', $this->tempDir . DIRECTORY_SEPARATOR . 'tts_work');
        $ctx->set('translated_segments', [
            [
                'id' => 1,
                'start' => 0.5,
                'end' => 2.5,
                'speaker' => 'SPEAKER_00',
                'translated_text' => 'مرحباً بكم في منصة عيد كلاود'
            ]
        ]);

        $tts = new TTSSynthesizer($this->ffmpeg);
        $ctx = $tts->process($ctx);

        $dubbedAudio = $ctx->get('dubbed_audio');
        $this->assert(file_exists($dubbedAudio), "Dubbed audio track should be generated");
        $this->assert(filesize($dubbedAudio) > 2000, "Dubbed audio should contain acoustic waveform");
    }

    public function testAudioDuckingAndMixing(): void
    {
        $bgAudio = $this->tempDir . DIRECTORY_SEPARATOR . 'bg.wav';
        $dubAudio = $this->tempDir . DIRECTORY_SEPARATOR . 'dub.wav';
        $mixed = $this->tempDir . DIRECTORY_SEPARATOR . 'mixed.wav';

        // 3 seconds of background audio
        $this->ffmpeg->run([
            '-y', '-f', 'lavfi', '-i', 'sine=frequency=220:duration=3', '-ar', '44100', '-ac', '2', $bgAudio
        ]);
        // 3 seconds of dubbed speech tone
        $this->ffmpeg->run([
            '-y', '-f', 'lavfi', '-i', 'sine=frequency=880:duration=3', '-ar', '44100', '-ac', '2', $dubAudio
        ]);

        $mixer = new AudioMixer($this->ffmpeg);
        $mixer->mixWithDucking($bgAudio, $dubAudio, $mixed, '-12dB');

        $this->assert(file_exists($mixed), "Mixed audio with ducking should exist");
        $this->assert(filesize($mixed) > 5000, "Mixed audio file should be valid WAV");
    }

    public function testSubtitleMuxing(): void
    {
        $video = $this->tempDir . DIRECTORY_SEPARATOR . 'sub_input.mp4';
        $outVideo = $this->tempDir . DIRECTORY_SEPARATOR . 'sub_output.mp4';
        $this->generateSampleVideo($video, 2.0);

        $segments = [
            [
                'id' => 1,
                'start' => 0.0,
                'end' => 1.8,
                'text' => 'Hello World',
                'translated_text' => 'مرحبا بالعالم'
            ]
        ];

        $muxer = new SubtitleMuxer($this->ffmpeg);
        $srt = $this->tempDir . DIRECTORY_SEPARATOR . 'test.srt';
        $vtt = $this->tempDir . DIRECTORY_SEPARATOR . 'test.vtt';

        $muxer->generateSrt($segments, $srt);
        $muxer->generateVtt($segments, $vtt);

        $this->assert(file_exists($srt) && str_contains(file_get_contents($srt), '00:00:00,000 --> 00:00:01,800'), "SRT content valid");
        $this->assert(file_exists($vtt) && str_contains(file_get_contents($vtt), 'WEBVTT'), "VTT content valid");

        $muxer->muxSubtitlesOnly($video, $srt, $outVideo, 'ara');
        $this->assert(file_exists($outVideo), "Video with multiplexed subtitles should exist");
    }

    public function testFullEndToEndPipeline(): void
    {
        $video = $this->tempDir . DIRECTORY_SEPARATOR . 'e2e_input.mp4';
        $outVideo = $this->tempDir . DIRECTORY_SEPARATOR . 'e2e_final.mp4';
        $this->generateSampleVideo($video, 4.0);

        $pipeline = VideoPipeline::createDefault($this->ffmpeg);

        $executedHooks = [];
        $pipeline->hook('pipeline:start', function () use (&$executedHooks) {
            $executedHooks[] = 'pipeline:start';
        });
        $pipeline->hook('after:dialogue_translator', function (PipelineContext $ctx) use (&$executedHooks) {
            $executedHooks[] = 'after:dialogue_translator';
        });
        $pipeline->hook('pipeline:end', function () use (&$executedHooks) {
            $executedHooks[] = 'pipeline:end';
        });

        $context = $pipeline->run($video, 'en', 'ar', 'male', [
            'output_video' => $outVideo,
            'ducking' => '-14dB',
        ]);

        $this->assert(file_exists($context->get('final_video')), "Final translated video must exist");
        $this->assert(file_exists($context->get('subtitles_srt')), "SRT subtitles must exist");
        $this->assert(file_exists($context->get('subtitles_vtt')), "VTT subtitles must exist");
        $this->assert(in_array('pipeline:start', $executedHooks, true), "Hook pipeline:start fired");
        $this->assert(in_array('after:dialogue_translator', $executedHooks, true), "Hook after:dialogue_translator fired");
        $this->assert(in_array('pipeline:end', $executedHooks, true), "Hook pipeline:end fired");
    }
}
