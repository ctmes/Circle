<?php

namespace App\Services\Evidence;

use App\Models\Circle;
use App\Models\EvidenceVersion;
use App\Models\Export;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * All access to the private evidence bucket goes through here.
 *
 * Originals are never public and never served by the API process. The browser
 * uploads straight to object storage with a short-lived signed URL, and reads
 * the same way — so the binary never transits the application, and every URL
 * is issued only after AccessGate has approved the specific action.
 */
class EvidenceStorage
{
    /** Signed URLs are deliberately short-lived; they are capability tokens. */
    private const UPLOAD_TTL_MINUTES   = 15;
    private const DOWNLOAD_TTL_MINUTES = 5;

    /** Disk used for server-side reads/writes from inside the network. */
    public function disk(): Filesystem
    {
        return Storage::disk('evidence');
    }

    /**
     * Disk used purely to mint browser-facing URLs. S3 signatures cover the
     * host, so these must be signed against the externally reachable endpoint.
     */
    private function presignDisk(): Filesystem
    {
        return Storage::disk('evidence_presign');
    }

    // ------------------------------------------------------------ key layout

    /**
     * Keys embed the Circle and version so an object's provenance is legible
     * from the key alone, and a Circle's objects are contiguous for lifecycle
     * rules at closure.
     */
    public function evidenceKey(string $circleId, string $evidenceItemId, int $versionNumber, string $filename): string
    {
        return sprintf(
            'circles/%s/evidence/%s/v%d/%s-%s',
            $circleId,
            $evidenceItemId,
            $versionNumber,
            Str::lower((string) Str::ulid()),
            $this->sanitiseFilename($filename),
        );
    }

    /**
     * Key for a direct browser upload, minted before the evidence item exists.
     * The Circle prefix is what the register step validates, so a client cannot
     * point its upload at another Circle.
     */
    public function stagedUploadKey(string $circleId, string $filename): string
    {
        return sprintf(
            'circles/%s/evidence/staged/%s-%s',
            $circleId,
            Str::lower((string) Str::ulid()),
            $this->sanitiseFilename($filename),
        );
    }

    public function derivedKey(string $circleId, string $artifactType, string $artifactId, string $extension): string
    {
        return sprintf('circles/%s/derived/%s/%s.%s', $circleId, $artifactType, $artifactId, ltrim($extension, '.'));
    }

    public function exportKey(Circle $circle, Export $export): string
    {
        return sprintf('circles/%s/exports/%s.zip', $circle->id, $export->id);
    }

    /**
     * Strips path components and anything that would be awkward in an S3 key,
     * while keeping the name recognisable to a human reading the manifest.
     */
    public function sanitiseFilename(string $filename): string
    {
        $base = basename(str_replace('\\', '/', $filename));
        $name = pathinfo($base, PATHINFO_FILENAME);
        $ext  = pathinfo($base, PATHINFO_EXTENSION);

        $slug = Str::of($name)->ascii()->replaceMatches('/[^A-Za-z0-9._-]+/', '-')->trim('-')->limit(80, '')->value();

        if ($slug === '') {
            $slug = 'file';
        }

        return $ext !== '' ? $slug . '.' . Str::lower($ext) : $slug;
    }

    // --------------------------------------------------------- signed access

    /**
     * A direct-to-storage upload URL. The API never sees the bytes; it learns
     * the object exists when the client calls back to register the version, and
     * the background job independently verifies size, MIME and hash.
     *
     * @return array{url: string, headers: array<string, string>, key: string, expires_at: string}
     */
    public function signedUploadUrl(string $key, string $contentType): array
    {
        $disk = $this->presignDisk();
        $expiresAt = now()->addMinutes(self::UPLOAD_TTL_MINUTES);

        $signed = $disk->temporaryUploadUrl($key, $expiresAt, [
            'ContentType' => $contentType,
        ]);

        return [
            'url'        => $signed['url'],
            'headers'    => array_merge(['Content-Type' => $contentType], $signed['headers'] ?? []),
            'key'        => $key,
            'expires_at' => $expiresAt->toISOString(),
        ];
    }

    /**
     * A short-lived read URL. Callers must have passed a resource.download or
     * resource.view check before this is issued — the URL itself carries no
     * further authorisation.
     */
    public function signedDownloadUrl(string $key, ?string $downloadFilename = null): string
    {
        $options = [];

        if ($downloadFilename !== null) {
            $options['ResponseContentDisposition'] =
                'attachment; filename="' . $this->sanitiseFilename($downloadFilename) . '"';
        }

        return $this->presignDisk()->temporaryUrl($key, now()->addMinutes(self::DOWNLOAD_TTL_MINUTES), $options);
    }

    // ------------------------------------------------------------- integrity

    public function exists(string $key): bool
    {
        return $this->disk()->exists($key);
    }

    public function size(string $key): ?int
    {
        return $this->exists($key) ? $this->disk()->size($key) : null;
    }

    public function detectedMimeType(string $key): ?string
    {
        return $this->exists($key) ? ($this->disk()->mimeType($key) ?: null) : null;
    }

    /**
     * Streams the object to compute SHA-256 without holding it in memory —
     * site videos are routinely larger than the PHP memory limit.
     */
    public function sha256(string $key): ?string
    {
        $stream = $this->disk()->readStream($key);

        if ($stream === null || $stream === false) {
            return null;
        }

        $context = hash_init('sha256');

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);

                if ($chunk === false) {
                    return null;
                }

                hash_update($context, $chunk);
            }
        } finally {
            fclose($stream);
        }

        return hash_final($context);
    }

    /**
     * Copies an object to a local temp path so command-line tools (ffmpeg,
     * pdftotext, tesseract) can work on it. Callers must delete the file.
     */
    public function pullToTempFile(string $key, ?string $extension = null): ?string
    {
        $stream = $this->disk()->readStream($key);

        if ($stream === null || $stream === false) {
            return null;
        }

        $extension ??= pathinfo($key, PATHINFO_EXTENSION) ?: 'bin';
        $path = sys_get_temp_dir() . '/circle-' . Str::lower((string) Str::ulid()) . '.' . $extension;

        $out = fopen($path, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);

        return $path;
    }

    public function putFile(string $key, string $localPath, string $contentType): void
    {
        $stream = fopen($localPath, 'rb');

        try {
            $this->disk()->put($key, $stream, ['ContentType' => $contentType, 'visibility' => 'private']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function put(string $key, string $contents, string $contentType): void
    {
        $this->disk()->put($key, $contents, ['ContentType' => $contentType, 'visibility' => 'private']);
    }

    public function get(string $key): ?string
    {
        return $this->exists($key) ? $this->disk()->get($key) : null;
    }

    public function keyFor(EvidenceVersion $version): string
    {
        return $version->storage_key;
    }
}
