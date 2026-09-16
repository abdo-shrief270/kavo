<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * WhatsApp requires pre-approved templates for business-initiated messages
 * outside the 24-hour customer-service window. Modelling approval explicitly
 * means an unapproved send is refused here rather than silently dropped by
 * the provider.
 */
final class WhatsAppTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'language', 'body', 'variables', 'approval_status', 'provider_template_id', 'approved_at', 'rejection_reason'];

    protected function casts(): array
    {
        return ['variables' => 'array', 'approved_at' => 'datetime'];
    }

    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    /** @param array<string, mixed> $values */
    public function render(array $values): string
    {
        $body = $this->body;

        foreach ($this->variables ?? [] as $variable) {
            $body = str_replace('{{'.$variable.'}}', (string) ($values[$variable] ?? ''), $body);
        }

        return $body;
    }
}
