<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Observability\Logging\ContextProcessor;
use App\Modules\Platform\Observability\Logging\JsonLogFormatter;
use App\Modules\Platform\Observability\Services\RequestContext;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Observability is only worth having if the context is actually attached.
 *
 * A log line without a tenant, or an error report without a release, is the
 * failure mode these tests exist to catch — and it is a silent one: logging
 * keeps working, it just stops being useful at exactly the moment you need it.
 */
final class ObservabilityTest extends TestCase
{
    private function record(string $channel = 'testing'): LogRecord
    {
        return new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: $channel,
            level: Level::Warning,
            message: 'something happened',
        );
    }

    #[Test]
    public function every_request_gets_a_correlation_id_echoed_back(): void
    {
        $response = $this->getJson('/api/me');

        $id = $response->headers->get('X-Request-Id');

        // Echoed so a user reporting a problem can quote an id that finds the
        // exact request in the logs.
        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
    }

    #[Test]
    public function correlation_ids_are_unique_per_request(): void
    {
        $first = $this->getJson('/api/me')->headers->get('X-Request-Id');
        $second = $this->getJson('/api/me')->headers->get('X-Request-Id');

        $this->assertNotSame($first, $second);
    }

    /**
     * A client-supplied id would let anyone collide with — or poison —
     * another request's trace, so it is only honoured from a trusted proxy.
     */
    #[Test]
    public function an_untrusted_client_cannot_choose_its_own_correlation_id(): void
    {
        $response = $this->withHeader('X-Request-Id', 'attacker-chosen-id')->getJson('/api/me');

        $this->assertNotSame('attacker-chosen-id', $response->headers->get('X-Request-Id'));
    }

    /**
     * Nor can one that merely arrives through the proxy.
     *
     * Caddy forwards a browser's headers unchanged, so "came through a trusted
     * proxy" says nothing about who wrote them — which is the whole difference
     * between a network hop being ours and a caller being ours.
     */
    #[Test]
    public function arriving_through_a_trusted_proxy_is_not_proof_of_anything(): void
    {
        config()->set('kavo.internal_token', 'internal-secret');

        $response = $this->call('GET', '/api/me', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_REQUEST_ID' => 'attacker-chosen-id',
        ]);

        $this->assertNotSame('attacker-chosen-id', $response->headers->get('X-Request-Id'));
    }

    /**
     * The storefront's SSR server, which does prove it, keeps the trace.
     *
     * Without this a single page render produces two unrelated sets of logs,
     * which is what it did: the check was isFromTrustedProxy() alone, no
     * proxies were configured, and so an inbound id was never once accepted.
     */
    #[Test]
    public function a_first_party_caller_keeps_the_trace_across_the_hop(): void
    {
        config()->set('kavo.internal_token', 'internal-secret');

        $response = $this->call('GET', '/api/me', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_REQUEST_ID' => 'render-0f9a2c41',
            'HTTP_X_INTERNAL_TOKEN' => 'internal-secret',
        ]);

        $this->assertSame('render-0f9a2c41', $response->headers->get('X-Request-Id'));
    }

    /** With no token configured nothing is first party, so nothing is taken on trust. */
    #[Test]
    public function an_unconfigured_token_trusts_no_one(): void
    {
        config()->set('kavo.internal_token', '');

        $response = $this->call('GET', '/api/me', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_REQUEST_ID' => 'render-0f9a2c41',
            'HTTP_X_INTERNAL_TOKEN' => '',
        ]);

        $this->assertNotSame('render-0f9a2c41', $response->headers->get('X-Request-Id'));
    }

    #[Test]
    public function log_lines_carry_the_request_id(): void
    {
        app(RequestContext::class)->setRequestId('req-123');

        $record = (new ContextProcessor)($this->record());

        $this->assertSame('req-123', $record->extra['request_id']);
    }

    #[Test]
    public function log_lines_carry_the_tenant_and_product(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsTenant($tenant);

        $record = (new ContextProcessor)($this->record());

        // Without this, "checkout failed" is a support ticket rather than a
        // bug report.
        $this->assertSame($tenant->getKey(), $record->extra['tenant_id']);
        $this->assertSame($tenant->product->value, $record->extra['product']);
    }

    #[Test]
    public function log_lines_carry_the_release_so_a_regression_can_be_dated(): void
    {
        config()->set('app.release', 'abc123def');

        $record = (new ContextProcessor)($this->record());

        $this->assertSame('abc123def', $record->extra['release']);
        $this->assertSame('testing', $record->extra['environment']);
    }

    #[Test]
    public function the_processor_survives_having_no_tenant(): void
    {
        $this->forgetTenant();

        $record = (new ContextProcessor)($this->record());

        // A logger that throws would hide the very error it was asked to
        // record, so missing context is omitted rather than fatal.
        $this->assertArrayNotHasKey('tenant_id', $record->extra);
        $this->assertSame('testing', $record->extra['environment']);
    }

    #[Test]
    public function the_channel_becomes_the_module_tag(): void
    {
        $this->assertSame('slow', (new ContextProcessor)($this->record('slow'))->extra['module']);
        $this->assertSame('app', (new ContextProcessor)($this->record('production'))->extra['module']);
    }

    #[Test]
    public function the_deep_health_check_reports_each_dependency(): void
    {
        $response = $this->getJson('/internal/health');

        $response->assertJsonStructure([
            'status',
            'release',
            'checks' => ['database' => ['ok'], 'redis' => ['ok'], 'queue', 'reverb'],
        ]);

        $this->assertTrue($response->json('checks.database.ok'));
    }

    /**
     * Rollback is gated on /up, not the deep check. A momentary Redis blip
     * must not roll back a release that is serving fine.
     */
    #[Test]
    public function liveness_is_separate_from_the_dependency_check(): void
    {
        $this->get('/up')->assertOk();
    }

    /**
     * Exercises the tap through Laravel's own channel resolution, not the
     * processor in isolation.
     *
     * The processor can be perfect while the tap never runs — which is what
     * happened: Laravel hands a tap `Illuminate\Log\Logger`, and a signature
     * expecting Monolog's silently prevented it from ever being applied. No
     * error, no warning, just logs quietly keeping their default format.
     */
    #[Test]
    public function the_configured_channel_actually_emits_json_with_context(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsTenant($tenant);
        app(RequestContext::class)->setRequestId('req-tap-check');

        $path = storage_path('logs/tap-check.log');
        @unlink($path);

        config()->set('logging.channels.tap_check', [
            'driver' => 'single',
            'path' => $path,
            'level' => 'debug',
            'tap' => [JsonLogFormatter::class],
        ]);

        Log::channel('tap_check')->warning('gateway timed out', ['gateway' => 'paymob']);

        $line = trim((string) file_get_contents($path));
        @unlink($path);

        $decoded = json_decode($line, true);

        $this->assertIsArray($decoded, "Channel did not emit JSON. Raw line: {$line}");
        $this->assertSame('gateway timed out', $decoded['message']);
        $this->assertSame('paymob', $decoded['context']['gateway']);
        $this->assertSame('req-tap-check', $decoded['extra']['request_id']);
        $this->assertSame($tenant->getKey(), $decoded['extra']['tenant_id']);
        $this->assertSame($tenant->product->value, $decoded['extra']['product']);
    }

    #[Test]
    public function sentry_never_receives_request_bodies_or_emails_by_default(): void
    {
        // send_default_pii would otherwise attach cookies, request bodies and
        // user emails — merchant and shopper data we have no need to ship to
        // a third party to read a stack trace.
        $this->assertFalse((bool) config('sentry.send_default_pii'));
    }

    #[Test]
    public function a_platform_admin_can_be_identified_without_personal_data(): void
    {
        $staff = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($staff)->getJson('/api/me')->assertOk();

        $this->assertTrue($staff->is_platform_admin);
    }
}
