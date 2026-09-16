<?php

declare(strict_types=1);

namespace App\Modules\Platform\Media\Http\Controllers;

use App\Modules\Platform\Audit\Services\AuditRecorder;
use App\Modules\Platform\Media\Models\Media;
use App\Modules\Platform\Media\Services\MediaUploader;
use App\Shared\Contracts\Entitlements;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MediaController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Media::query()->latest('id')->paginate($request->integer('per_page', 30))
        );
    }

    public function store(
        Request $request,
        MediaUploader $uploader,
        Entitlements $entitlements,
        TenantContext $context,
        AuditRecorder $audit,
    ): JsonResponse {
        $request->validate([
            'file' => ['required', 'file', 'max:25600'],
        ]);

        // QuotaExceeded renders itself as 402 with the quota detail attached,
        // so there is nothing to catch here.
        $media = $uploader->store($request->file('file'));

        $audit->record('media.uploaded', $media, null, [
            'filename' => $media->filename,
            'size_bytes' => $media->size_bytes,
        ]);

        return response()->json([
            'media' => $media,
            'url' => $media->url(),
            'quota' => $this->quota($entitlements, $context),
        ], 201);
    }

    public function destroy(
        Media $media,
        MediaUploader $uploader,
        Entitlements $entitlements,
        TenantContext $context,
        AuditRecorder $audit,
    ): JsonResponse {
        $audit->record('media.deleted', $media, ['filename' => $media->filename], null);

        $uploader->delete($media);

        // Returned so the client sees the allowance come back immediately —
        // storage is a stock metric, and a UI that only ever counts up would
        // misrepresent what the tenant is paying for.
        return response()->json(['quota' => $this->quota($entitlements, $context)]);
    }

    /** @return array<string, mixed> */
    private function quota(Entitlements $entitlements, TenantContext $context): array
    {
        $tenant = $context->getOrFail('reporting storage quota');

        return [
            'metric' => 'storage_mb',
            'used' => $entitlements->used($tenant, 'storage_mb'),
            'remaining' => $entitlements->remaining($tenant, 'storage_mb'),
        ];
    }
}
