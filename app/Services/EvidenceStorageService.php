<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class EvidenceStorageService
{
    public function cloudDisk(): Filesystem
    {
        return Storage::disk((string) config('filesystems.evidence_disk', 's3'));
    }

    public function localDisk(): Filesystem
    {
        return Storage::disk('evidence_local');
    }

    /**
     * Persist a file locally (always) and replicate to the cloud disk when possible.
     * Cloud failures must not fail the upload — local copy is enough to play back.
     */
    public function put(string $storagePath, string $contents): bool
    {
        try {
            $localOk = $this->localDisk()->put($storagePath, $contents);
        } catch (\Throwable $exception) {
            Log::error('Local evidence persist failed.', [
                'path' => $storagePath,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($localOk !== true) {
            return false;
        }

        try {
            $this->cloudDisk()->put($storagePath, $contents);
        } catch (\Throwable $exception) {
            Log::warning('Cloud evidence replica failed; local copy retained.', [
                'path' => $storagePath,
                'error' => $exception->getMessage(),
            ]);
        }

        return true;
    }

    public function putFromPath(string $storagePath, string $absolutePath): bool
    {
        try {
            $localOk = $this->writeStream($this->localDisk(), $storagePath, $absolutePath);
        } catch (\Throwable $exception) {
            Log::error('Local evidence persist failed.', [
                'path' => $storagePath,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($localOk !== true) {
            return false;
        }

        try {
            $this->writeStream($this->cloudDisk(), $storagePath, $absolutePath);
        } catch (\Throwable $exception) {
            Log::warning('Cloud evidence replica failed; local copy retained.', [
                'path' => $storagePath,
                'error' => $exception->getMessage(),
            ]);
        }

        return true;
    }

    public function exists(string $storagePath): bool
    {
        try {
            if ($this->localDisk()->exists($storagePath)) {
                return true;
            }
        } catch (\Throwable) {
            // continue
        }

        try {
            return $this->cloudDisk()->exists($storagePath);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return resource|false
     */
    public function readStream(string $storagePath)
    {
        try {
            $stream = $this->resolveDisk($storagePath)->readStream($storagePath);

            return $stream === false ? false : $stream;
        } catch (\Throwable) {
            return false;
        }
    }

    public function get(string $storagePath): ?string
    {
        try {
            $contents = $this->resolveDisk($storagePath)->get($storagePath);

            return is_string($contents) ? $contents : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function stream(string $storagePath, string $filename, string $mime): Response
    {
        $disk = $this->resolveDisk($storagePath);

        return $disk->response($storagePath, $filename, [
            'Content-Type' => $mime,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'X-WORM-Policy' => 'read-only; evidence records are immutable',
        ]);
    }

    private function resolveDisk(string $storagePath): Filesystem
    {
        try {
            if ($this->localDisk()->exists($storagePath)) {
                return $this->localDisk();
            }
        } catch (\Throwable) {
            // fall through
        }

        return $this->cloudDisk();
    }

    private function writeStream(Filesystem $disk, string $storagePath, string $absolutePath): bool
    {
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            return $disk->put($storagePath, $handle) === true;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
