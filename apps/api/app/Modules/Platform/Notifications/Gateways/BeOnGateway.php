<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications\Gateways;

use App\Modules\Platform\Notifications\Models\WhatsAppTemplate;
use App\Modules\Platform\Notifications\Results\WhatsAppSendResult;
use App\Shared\Contracts\WhatsAppGateway;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;

final readonly class BeOnGateway implements WhatsAppGateway
{
    public function __construct(
        private Http $http,
        private string $baseUrl,
        private string $apiKey,
        private string $webhookSecret,
    ) {}

    public function name(): string
    {
        return 'beon';
    }

    public function sendTemplate(string $to, string $templateCode, array $variables = [], string $language = 'en'): WhatsAppSendResult
    {
        $template = WhatsAppTemplate::query()
            ->where('code', $templateCode)
            ->where('language', $language)
            ->first();

        if ($template === null) {
            return WhatsAppSendResult::rejected("Unknown WhatsApp template [{$templateCode}].");
        }

        // Refusing here rather than at the provider turns a silent delivery
        // failure into a visible, logged rejection.
        if (! $template->isApproved()) {
            return WhatsAppSendResult::rejected("WhatsApp template [{$templateCode}] is not approved (status: {$template->approval_status}).");
        }

        return $this->post('/api/v1/whatsapp/template', [
            'to' => $to,
            'template' => $template->provider_template_id ?? $template->code,
            'language' => $language,
            'components' => $this->componentsFor($template, $variables),
        ]);
    }

    public function sendText(string $to, string $body): WhatsAppSendResult
    {
        return $this->post('/api/v1/whatsapp/message', [
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $body],
        ]);
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        // Computed over the raw body: verifying a re-encoded payload would
        // compare a different byte sequence and fail (or worse, pass on a
        // payload that is not what was signed).
        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);

        return hash_equals($expected, $signature);
    }

    /** @param array<string, mixed> $payload */
    private function post(string $path, array $payload): WhatsAppSendResult
    {
        try {
            $response = $this->http
                ->withToken($this->apiKey)
                ->acceptJson()
                ->timeout(10)
                ->post(rtrim($this->baseUrl, '/').$path, $payload);
        } catch (RequestException|\Throwable $e) {
            return WhatsAppSendResult::failed($e->getMessage());
        }

        if ($response->successful()) {
            $messageId = $response->json('message_id') ?? $response->json('data.id');

            return $messageId === null
                ? WhatsAppSendResult::failed('Provider accepted the send but returned no message id.')
                : WhatsAppSendResult::accepted((string) $messageId);
        }

        $error = $response->json('message') ?? $response->body();

        // 4xx is the caller's fault and will fail identically on retry;
        // 429 and 5xx are worth backing off and trying again.
        return $response->status() >= 500 || $response->status() === 429
            ? WhatsAppSendResult::failed((string) $error)
            : WhatsAppSendResult::rejected((string) $error);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<int, array<string, mixed>>
     */
    private function componentsFor(WhatsAppTemplate $template, array $variables): array
    {
        $ordered = array_map(
            static fn (string $name): array => ['type' => 'text', 'text' => (string) ($variables[$name] ?? '')],
            $template->variables ?? []
        );

        return $ordered === [] ? [] : [['type' => 'body', 'parameters' => $ordered]];
    }
}
