<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Guards the boundary config itself.
 *
 * Deptrac enforces the rules, but only over the layers it has been told
 * about. A module added without a layer is not a module with no rules — with
 * --fail-on-uncovered it fails the whole run, which is how the Observability
 * module sat unregistered from the slice that created it until the first time
 * CI ever ran.
 *
 * This runs in the ordinary suite so the mistake is caught locally, by
 * everyone, without needing deptrac installed.
 */
final class ArchitectureTest extends BaseTestCase
{
    /**
     * Read with regexes rather than a YAML parser: no parser ships with the
     * app, and adding a dependency to read one config file we own ourselves
     * is a worse trade than two patterns.
     *
     * @return array{layers: list<string>, ruleset: list<string>}
     */
    private function config(): array
    {
        $yaml = (string) file_get_contents(dirname(__DIR__, 2).'/deptrac.yaml');

        preg_match_all('/^    - name: (\w+)$/m', $yaml, $layers);

        // Ruleset keys are the only four-space-indented `Name:` lines that
        // follow the `ruleset:` heading.
        $ruleset = substr($yaml, (int) strpos($yaml, "\n  ruleset:"));
        preg_match_all('/^    (\w+):/m', $ruleset, $rules);

        return ['layers' => $layers[1], 'ruleset' => $rules[1]];
    }

    #[Test]
    public function every_platform_module_has_a_deptrac_layer(): void
    {
        $config = $this->config();
        $modules = array_map('basename', glob(dirname(__DIR__, 2).'/app/Modules/Platform/*', GLOB_ONLYDIR) ?: []);

        $this->assertNotEmpty($modules, 'No modules found — the path this test walks has moved.');

        foreach ($modules as $module) {
            $this->assertContains(
                $module,
                $config['layers'],
                "The {$module} module has no Deptrac layer. Uncovered classes fail the whole analysis, so this is not a module without rules — it is a broken gate.",
            );
        }
    }

    #[Test]
    public function every_layer_has_a_ruleset_entry(): void
    {
        $config = $this->config();

        foreach ($config['layers'] as $layer) {
            $this->assertContains(
                $layer,
                $config['ruleset'],
                "The {$layer} layer has no ruleset entry, so every dependency it has is a violation.",
            );
        }
    }

    /**
     * A ratchet on what Shared reaches into.
     *
     * Shared is meant to be the bottom of the stack and is not: its contracts
     * are interfaces whose vocabulary lives inside the modules they abstract —
     * PaymentGateway speaks in Billing's value objects, Entitlements in
     * Entitlements', WhatsAppGateway in Notifications'. Arguably right (a
     * contract and its DTOs belong together; only the interface is hoisted)
     * and arguably the DTOs should move up beside the contracts. Either way it
     * is a decision, not an accident, and deptrac.yaml can only say
     * "Shared may reach those modules" — which would let the list grow.
     *
     * So the list is pinned here. Adding to it is a deliberate act with a
     * failing test attached, which is what makes it a decision rather than
     * drift.
     */
    #[Test]
    public function shared_reaches_into_modules_only_where_it_already_does(): void
    {
        $allowed = [
            // Tenancy is Shared's subject, and a tenancy primitive deals in
            // tenants. Named by the context, the scoping trait, the
            // entitlements contract and the quota event.
            'App\Modules\Platform\Identity\Models\Tenant',

            // The payment contract's own vocabulary.
            'App\Modules\Platform\Billing\Payments\PaymentRail',
            'App\Modules\Platform\Billing\Payments\PaymentRequest',
            'App\Modules\Platform\Billing\Payments\PaymentResult',
            'App\Modules\Platform\Billing\Payments\SettlementNotice',
            // Carried by the PaymentSettled event that Commerce consumes.
            'App\Modules\Platform\Billing\Models\PaymentIntent',

            // The entitlements contract's result type, and the exception that
            // reports one.
            'App\Modules\Platform\Entitlements\Results\ConsumeResult',

            'App\Modules\Platform\Notifications\Results\WhatsAppSendResult',
        ];

        $offenders = [];

        foreach ($this->phpFilesIn(dirname(__DIR__, 2).'/app/Shared') as $file) {
            preg_match_all('/^use (App\\\\Modules\\\\[\w\\\\]+);$/m', (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $imported) {
                if (! in_array($imported, $allowed, true)) {
                    $offenders[] = basename($file).' → '.$imported;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Shared reached into a module it does not already depend on. That is a layering decision, '
            ."not a detail — make it deliberately by adding the class to this test's allowlist, or keep "
            ."Shared out of it:\n".implode("\n", $offenders),
        );
    }

    /** @return list<string> */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
