<?php

declare(strict_types=1);

namespace EidCloud\VideoPipeline\Stages;

interface StageInterface
{
    /**
     * Executes the pipeline stage.
     *
     * @param PipelineContext $context
     * @return PipelineContext
     */
    public function process(PipelineContext $context): PipelineContext;

    /**
     * Name of the stage.
     */
    public function getName(): string;
}
