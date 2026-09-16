<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domains\Http\Controllers;

use App\Modules\Platform\Audit\Services\AuditRecorder;
use App\Modules\Platform\Domains\Models\Domain;
use App\Modules\Platform\Domains\Services\DomainVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class DomainController
{
    public function index(): JsonResponse
    {
        return response()->json(['domains' => Domain::query()->latest()->get()]);
    }

    public function store(Request $request, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'hostname' => [
                'required', 'string', 'max:253',
                // Hostnames end up in TLS issuance decisions and in the
                // storefront's tenant lookup, so the shape is constrained here
                // rather than trusted later.
                'regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i',
                'unique:domains,hostname',
            ],
        ]);

        $domain = Domain::create([
            'hostname' => mb_strtolower($validated['hostname']),
            'status' => 'pending',
            'verification_token' => Str::random(32),
        ]);

        $audit->record('domain.added', $domain, null, ['hostname' => $domain->hostname]);

        return response()->json([
            'domain' => $domain,
            'dns_record' => [
                'type' => 'TXT',
                'name' => '_kavo-challenge.'.$domain->hostname,
                'value' => $domain->expectedDnsRecord(),
            ],
        ], 201);
    }

    public function verify(Domain $domain, DomainVerifier $verifier, AuditRecorder $audit): JsonResponse
    {
        $verified = $verifier->verify($domain);

        $audit->record($verified ? 'domain.verified' : 'domain.verification_failed', $domain);

        return response()->json([
            'domain' => $domain->fresh(),
            'verified' => $verified,
        ], $verified ? 200 : 422);
    }

    public function destroy(Domain $domain, AuditRecorder $audit): JsonResponse
    {
        $audit->record('domain.removed', $domain, ['hostname' => $domain->hostname], null);
        $domain->delete();

        return response()->json(['message' => 'Domain removed.']);
    }
}
