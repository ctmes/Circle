<?php

namespace App\Support;

/**
 * The MVP's supported upload matrix (spec §7) and the processing lane each
 * format takes. Anything not listed is still preserved as an original — it just
 * gets no extraction, which is the correct default for an evidence vault.
 */
final class MediaType
{
    public const LANE_DOCUMENT    = 'document';
    public const LANE_SPREADSHEET = 'spreadsheet';
    public const LANE_IMAGE       = 'image';
    public const LANE_VIDEO       = 'video';
    public const LANE_AUDIO       = 'audio';
    public const LANE_URL         = 'url';
    public const LANE_OTHER       = 'other';

    /** extension => [lane, canonical mime] */
    private const MAP = [
        'pdf'  => [self::LANE_DOCUMENT, 'application/pdf'],
        'docx' => [self::LANE_DOCUMENT, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'txt'  => [self::LANE_DOCUMENT, 'text/plain'],
        'md'   => [self::LANE_DOCUMENT, 'text/markdown'],

        'csv'  => [self::LANE_SPREADSHEET, 'text/csv'],
        'xlsx' => [self::LANE_SPREADSHEET, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],

        'jpg'  => [self::LANE_IMAGE, 'image/jpeg'],
        'jpeg' => [self::LANE_IMAGE, 'image/jpeg'],
        'png'  => [self::LANE_IMAGE, 'image/png'],
        'webp' => [self::LANE_IMAGE, 'image/webp'],
        'heic' => [self::LANE_IMAGE, 'image/heic'],

        'mp4'  => [self::LANE_VIDEO, 'video/mp4'],
        'mov'  => [self::LANE_VIDEO, 'video/quicktime'],
        'webm' => [self::LANE_VIDEO, 'video/webm'],

        'mp3'  => [self::LANE_AUDIO, 'audio/mpeg'],
        'wav'  => [self::LANE_AUDIO, 'audio/wav'],
        'm4a'  => [self::LANE_AUDIO, 'audio/mp4'],
        'ogg'  => [self::LANE_AUDIO, 'audio/ogg'],

        // Preserved, extraction deferred (spec §7).
        'zip'  => [self::LANE_OTHER, 'application/zip'],
        'pptx' => [self::LANE_OTHER, 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'eml'  => [self::LANE_OTHER, 'message/rfc822'],
    ];

    public static function extensionOf(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    public static function isSupported(string $filename): bool
    {
        return array_key_exists(self::extensionOf($filename), self::MAP);
    }

    /** @return list<string> */
    public static function supportedExtensions(): array
    {
        return array_keys(self::MAP);
    }

    public static function laneFor(string $filename, ?string $mimeType = null): string
    {
        $ext = self::extensionOf($filename);

        if (isset(self::MAP[$ext])) {
            return self::MAP[$ext][0];
        }

        // Fall back to the MIME prefix when the filename carries no useful
        // extension — common for URL captures and camera uploads.
        return match (true) {
            $mimeType === null                   => self::LANE_OTHER,
            str_starts_with($mimeType, 'image/') => self::LANE_IMAGE,
            str_starts_with($mimeType, 'video/') => self::LANE_VIDEO,
            str_starts_with($mimeType, 'audio/') => self::LANE_AUDIO,
            str_starts_with($mimeType, 'text/')  => self::LANE_DOCUMENT,
            default                              => self::LANE_OTHER,
        };
    }

    public static function canonicalMime(string $filename, ?string $fallback = null): ?string
    {
        return self::MAP[self::extensionOf($filename)][1] ?? $fallback;
    }

    /** Lanes whose originals can carry an embedded audio track worth transcribing. */
    public static function hasAudioTrack(string $lane): bool
    {
        return in_array($lane, [self::LANE_VIDEO, self::LANE_AUDIO], true);
    }
}
