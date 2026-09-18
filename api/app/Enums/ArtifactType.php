<?php

namespace App\Enums;

/** Derived artifacts are always separate from the preserved original (spec §5). */
enum ArtifactType: string
{
    case Ocr                = 'ocr';
    case Transcript         = 'transcript';
    case Thumbnail          = 'thumbnail';
    case Frame              = 'frame';
    case Extraction         = 'extraction';
    case AgentSummary       = 'agent_summary';
    case RequirementsMatrix = 'requirements_matrix';

    /**
     * A plan read out of an engagement of terms, awaiting a human (spec 23).
     *
     * It is an artifact rather than a set of goals because nothing the model
     * proposes is part of the record until somebody accepts it. The goals it
     * becomes are created by that person, not by the agent.
     */
    case ConvenedPlan       = 'convened_plan';
}
