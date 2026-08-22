<?php

namespace App\Enums;

/**
 * Where an item came from (spec §6). Deliberately NOT a generic "verified"
 * badge — each value states a specific, defensible provenance fact.
 */
enum OriginStatus: string
{
    /** Retrieved from an authenticated connected source. Post-MVP. */
    case VerifiedSource = 'verified_source';
    /** Uploaded by an authenticated Circle participant. */
    case AuthenticatedUpload = 'authenticated_upload';
    /** Uploader/source cannot be reliably identified. */
    case UnverifiedUpload = 'unverified_upload';
    /** A verified identity made a statement about an item. */
    case Attested = 'attested';

    /** @return array<int, self> Values the MVP can actually produce. */
    public static function supportedInMvp(): array
    {
        return [self::AuthenticatedUpload, self::UnverifiedUpload, self::Attested];
    }
}
