<?php

namespace App\Enums;

/**
 * A citation points at a precise evidence location. The locator_json shape is
 * validated per type by App\Support\CitationLocator.
 */
enum CitationType: string
{
    case DocumentPage    = 'document_page';
    case TextRange       = 'text_range';
    case SpreadsheetCell = 'spreadsheet_cell';
    case Image           = 'image';
    case VideoTimestamp  = 'video_timestamp';
    case AudioTimestamp  = 'audio_timestamp';
    case UrlSnapshot     = 'url_snapshot';
    case Generic         = 'generic';
}
