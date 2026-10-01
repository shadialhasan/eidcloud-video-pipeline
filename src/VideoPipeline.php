<?php

declare(strict_types=1);

namespace EidCloud\VideoPipeline;

use EidCloud\VideoPipeline\Stages\AudioExtractor;
use EidCloud\VideoPipeline\Stages\SpeechToText;
use EidCloud\VideoPipeline\Stages\SpeakerDiarization;
use EidCloud\VideoPipeline\Stages\DialogueTranslator;
use EidCloud\VideoPipeline\Stages\TTSSynthesizer;
use EidCloud\VideoPipeline\Stages\AudioMixer;
use EidCloud\VideoPipeline\Stages\SubtitleMuxer;
use EidCloud\VideoPipeline\Stages\PipelineContext;
use EidCloud\VideoPipeline\Stages\StageInterface;

class VideoPipeline
{
    /**
     * @var array<int, StageInterface>
     */
    private array $stages = [];

    /**
     * @var array<string, array<int, callable>>
     */
    private array $hooks = [];

    private FFmpegRunner $ffmpeg;

    public function __construct(?FFmpegRunner $ffmpeg = null)
    {
        $this->ffmpeg = $ffmpeg ?? new FFmpegRunner();
    }

    /**
     * Factory to build the standard 7-stage neural dubbing pipeline.
     */
    public static function createDefault(?FFmpegRunner $ffmpeg = null): self
    {
        $runner = $ffmpeg ?? new FFmpegRunner();
        $pipeline = new self($runner);

        $pipeline->addStage(new AudioExtractor($runner));
        $pipeline->addStage(new SpeechToText());
        $pipeline->addStage(new SpeakerDiarization());
        $pipeline->addStage(new DialogueTranslator());
        $pipeline->addStage(new TTSSynthesizer($runner));
        $pipeline->addStage(new AudioMixer($runner));
        $pipeline->addStage(new SubtitleMuxer($runner));

        return $pipeline;
    }

    /**
     * Add a pipeline stage.
     */
    public function addStage(StageInterface $stage): self
    {
        $this->stages[] = $stage;
        return $this;
    }

    /**
     * Get all registered stages.
     *
     * @return array<int, StageInterface>
     */
    public function getStages(): array
    {
        return $this->stages;
    }

    /**
     * Register hook for lifecycle events (e.g. before:stage, after:stage, pipeline:start, pipeline:end).
     */
    public function hook(string $event, callable $callback): self
    {
        $this->hooks[$event][] = $callback;
        return $this;
    }

    /**
     * Trigger registered hooks for an event.
     */
    public function trigger(string $event, PipelineContext $context, ?StageInterface $stage = null): void
    {
        if (isset($this->hooks[$event])) {
            foreach ($this->hooks[$event] as $callback) {
                $callback($context, $stage);
            }
        }
    }

    /**
     * Executes the pipeline on an input video.
     *
     * @param string $videoPath
     * @param string $sourceLang
     * @param string $targetLang
     * @param string $voice
     * @param array<string, mixed> $options
     * @return PipelineContext
     */
    public function run(
        string $videoPath,
        string $sourceLang = 'en',
        string $targetLang = 'ar',
        string $voice = 'male',
        array $options = []
    ): PipelineContext {
        $context = new PipelineContext(
            $videoPath,
            $sourceLang,
            $targetLang,
            $voice,
            null,
            $options
        );

        $this->trigger('pipeline:start', $context);

        foreach ($this->stages as $stage) {
            $stageName = $stage->getName();
            $this->trigger("before:{$stageName}", $context, $stage);

            $context = $stage->process($context);

            $this->trigger("after:{$stageName}", $context, $stage);
        }

        $this->trigger('pipeline:end', $context);

        return $context;
    }

    public function getFFmpegRunner(): FFmpegRunner
    {
        return $this->ffmpeg;
    }
}
