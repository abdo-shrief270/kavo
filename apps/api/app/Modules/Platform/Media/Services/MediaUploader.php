<?php

declare(strict_types=1);

namespace App\Modules\Platform\Media\Services;

use App\Modules\Platform\Media\Models\Media;
use App\Shared\Contracts\Entitlements;
use App\Shared\Exceptions\QuotaExceeded;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores a file and charges it against the tenant's storage allowance.
 *
 * storage_mb is a stock metric, so deletion returns the allowance — see
 * Entitlements::release(). Charging on upload and never refunding would bill
 * a tenant forever for bytes they removed.
 */
final readonly class MediaUploader
{
    private const BYTES_PER_MB = 1_048_576;

    public function __construct(
        private Entitlements $entitlements,
        private TenantContext $context,
    ) {}

    public function store(UploadedFile $file, ?Model $owner = null, string $disk = 'public'): Media
    {
        $tenant = $this->context->getOrFail('uploading media');
        $cost = $this->costInMegabytes($file->getSize());

        // Charged before the write, so a tenant at their limit never consumes
        // disk they are not entitled to.
        $result = $this->entitlements->consume($tenant, 'storage_mb', $cost);

        if ($result->blocked()) {
            throw new QuotaExceeded($result);
        }

        $path = sprintf('tenants/%d/media/%s.%s', $tenant->getKey(), Str::uuid(), $file->getClientOriginalExtension() ?: 'bin');

        try {
            Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

            return Media::create([
                'tenant_id' => $tenant->getKey(),
                'disk' => $disk,
                'path' => $path,
                'filename' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'checksum' => hash_file('sha256', $file->getRealPath()),
                'owner_type' => $owner === null ? null : $owner::class,
                'owner_id' => $owner?->getKey(),
                'meta' => [],
            ]);
        } catch (Throwable $e) {
            // The charge happened before the write. If the write failed, the
            // tenant must not be left paying for a file that does not exist.
            $this->entitlements->release($tenant, 'storage_mb', $cost);

            throw $e;
        }
    }

    public function delete(Media $media): void
    {
        $tenant = $this->context->getOrFail('deleting media');
        $cost = $this->costInMegabytes($media->size_bytes);

        DB::transaction(function () use ($media): void {
            Storage::disk($media->disk)->delete($media->path);
            $media->delete();
        });

        $this->entitlements->release($tenant, 'storage_mb', $cost);
    }

    /**
     * Rounded up, never down: a 0.2 MB file costs 1 MB. Rounding down would
     * let many small files consume unlimited real storage for free.
     */
    private function costInMegabytes(?int $bytes): int
    {
        return max(1, (int) ceil(((int) $bytes) / self::BYTES_PER_MB));
    }
}
