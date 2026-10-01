<?php

declare(strict_types=1);

namespace EidCloud\VideoPipeline\Stages;

class SpeakerDiarization implements StageInterface
{
    /**
     * @var callable|null
     */
    private $diarizer;

    public function __construct(?callable $diarizer = null)
    {
        $this->diarizer = $diarizer;
    }

    public function getName(): string
    {
        return 'speaker_diarization';
    }

    public function process(PipelineContext $context): PipelineContext
    {
        $segments = $context->get('transcription_segments', []);

        if ($this->diarizer !== null) {
            $diarized = ($this->diarizer)($segments, $context);
        } else {
            $diarized = $this->diarizeSegments($segments);
        }

        $context->set('diarized_segments', $diarized);
        return $context;
    }

    /**
     * Segments speaker turns based on audio energy and pauses.
     * Assigns speaker labels (e.g., SPEAKER_00, SPEAKER_01).
     *
     * @param array<int, array<string, mixed>> $segments
     * @return array<int, array<string, mixed>>
     */
    public function diarizeSegments(array $segments): array
    {
        $currentSpeaker = 'SPEAKER_00';
        $lastEnd = 0.0;
        $speakerIndex = 0;

        foreach ($segments as &$segment) {
            $start = (float)($segment['start'] ?? 0.0);
            $pause = $start - $lastEnd;

            // If pause between speech turns exceeds 1.5s, switch speaker turn
            if ($pause > 1.5 && $lastEnd > 0) {
                $speakerIndex++;
                $currentSpeaker = sprintf('SPEAKER_%02d', $speakerIndex % 2);
            }

            $segment['speaker'] = $currentSpeaker;
            $segment['pause_before'] = round($pause, 3);
            $lastEnd = (float)($segment['end'] ?? $start);
        }

        return $segments;
    }
}
