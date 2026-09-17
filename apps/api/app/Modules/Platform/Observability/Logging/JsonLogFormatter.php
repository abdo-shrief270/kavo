<?php

declare(strict_types=1);

namespace App\Modules\Platform\Observability\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger as Monolog;

/**
 * Applies JSON output and the context processor to a channel.
 *
 * JSON rather than Laravel's default line format so a log shipper can filter
 * on tenant_id or request_id without parsing prose. Structuring this now is
 * cheap; retrofitting it once there are gigabytes of unstructured lines is
 * not.
 *
 * Laravel hands a tap `Illuminate\Log\Logger`, the framework wrapper — not
 * the Monolog logger. Type-hinting Monolog's here silently prevents the tap
 * from ever running, which presents as logs that simply keep their default
 * format with no error anywhere.
 */
final class JsonLogFormatter
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        // Only Monolog carries processors and handlers. A channel backed by
        // something else is left alone rather than fatally erroring inside a
        // logger, which is the worst place to throw.
        if (! $monolog instanceof Monolog) {
            return;
        }

        $monolog->pushProcessor(new ContextProcessor);

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new JsonFormatter(
                    batchMode: JsonFormatter::BATCH_MODE_NEWLINES,
                    appendNewline: true,
                ));
            }
        }
    }
}
