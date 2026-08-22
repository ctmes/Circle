<?php

namespace App\Enums;

enum ClaimType: string
{
    case Factual              = 'factual';
    case TechnicalAssessment  = 'technical_assessment';
    case CommercialAssessment = 'commercial_assessment';
    case Risk                 = 'risk';
    case Recommendation       = 'recommendation';
}
