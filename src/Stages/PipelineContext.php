<?php

declare(strict_types=1);

namespace EidCloud\VideoPipeline\Stages;

class PipelineContext
{
    private string $inputVideo;
    private string $sourceLanguage;
    private string $targetLanguage;
    private string $voice;
    private string $workingDirectory;
    private array $data = [];

    /**
     * @var array<string, mixed>
     */
    private array $options = [];

    public function __construct(
        string $inputVideo,
        string $sourceLanguage = 'en',
        string $targetLanguage = 'ar',
        string $voice = 'male',
        ?string $workingDirectory = null,
        array $options = []
    ) {
        $this->inputVideo = $inputVideo;
        $this->sourceLanguage = $sourceLanguage;
        $this->targetLanguage = $targetLanguage;
        $this->voice = $voice;
        $this->workingDirectory = $workingDirectory ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eidcloud_pipeline_' . uniqid();
        $this->options = $options;

        if (!is_dir($this->workingDirectory)) {
            @mkdir($this->workingDirectory, 0777, true);
        }
    }

    public function getInputVideo(): string
    {
        return $this->inputVideo;
    }

    public function getSourceLanguage(): string
    {
        return $this->sourceLanguage;
    }

    public function getTargetLanguage(): string
    {
        return $this->targetLanguage;
    }

    public function getVoice(): string
    {
        return $this->voice;
    }

    public function getWorkingDirectory(): string
    {
        return $this->workingDirectory;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function setOption(string $key, mixed $value): void
    {
        $this->options[$key] = $value;
    }

    public function all(): array
    {
        return $this->data;
    }
}
