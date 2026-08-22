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
}
