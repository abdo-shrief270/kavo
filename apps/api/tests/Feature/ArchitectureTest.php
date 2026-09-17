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
     * is a worse trade than a few patterns.
     *
     * @return array{layers: array<string, string>, ruleset: array<string, list<string>>}
     */
    private function config(): array
    {
        $yaml = (string) file_get_contents(dirname(__DIR__, 2).'/deptrac.yaml');

        // Line-based rather than one clever pattern: the file is small and
        // predictable, and a regex spanning blocks is the kind of thing that
        // silently matches nothing.
        $layers = [];
        $current = null;

        foreach (explode("\n", $yaml) as $line) {
            if (preg_match('/^    - name: (\w+)$/', $line, $m)) {
                $current = $m[1];
            } elseif ($current !== null && preg_match('/^          value: (\S+)$/', $line, $m)) {
                $layers[$current] = $m[1];
                $current = null;
            }
        }

        $ruleset = [];
        $section = substr($yaml, (int) strpos($yaml, "\n  ruleset:"));

        foreach (preg_split('/^    (?=\w)/m', $section) as $block) {
            if (! preg_match('/^(\w+):/', $block, $name)) {
                continue;
            }

            preg_match_all('/^      - (\w+)$/m', $block, $allowed);
            $ruleset[$name[1]] = $allowed[1];
        }

        return ['layers' => $layers, 'ruleset' => $ruleset];
    }

    /** The layer a file belongs to, or null when nothing claims it. */
    private function layerOf(string $path, array $layers): ?string
    {
        foreach ($layers as $name => $pattern) {
            if (str_starts_with($path, rtrim($pattern, '.*'))) {
                return $name;
            }
        }

        return null;
    }

    #[Test]
    public function every_platform_module_has_a_deptrac_layer(): void
    {
        $config = $this->config();
        $modules = array_map('basename', glob(dirname(__DIR__, 2).'/app/Modules/Platform/*', GLOB_ONLYDIR) ?: []);

        $this->assertNotEmpty($modules, 'No modules found — the path this test walks has moved.');

        foreach ($modules as $module) {
            $this->assertArrayHasKey(
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

        foreach (array_keys($config['layers']) as $layer) {
            $this->assertArrayHasKey(
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

    /**
     * Deptrac's own check, run here so it holds without Deptrac.
     *
     * The tool will not install everywhere — composer cannot always reach
     * github.com — and it took until the first CI run for anyone to discover
     * that the gate had been failing on framework classes rather than on
     * anything in this repository. A rule that only exists in a tool nobody
     * can run is a rule that is not being applied.
     *
     * This looks only at app code, which is the part the ruleset is about.
     */
    #[Test]
    public function no_layer_depends_on_one_its_ruleset_does_not_allow(): void
    {
        $config = $this->config();
        $owners = $this->classesByLayer($config['layers']);
        $violations = [];

        foreach ($this->phpFilesIn(dirname(__DIR__, 2).'/app') as $file) {
            $from = $this->layerOf($this->relative($file), $config['layers']);

            if ($from === null) {
                continue;
            }

            preg_match_all('/^use (App\\\\[\w\\\\]+)(?: as \w+)?;$/m', (string) file_get_contents($file), $imports);

            foreach ($imports[1] as $imported) {
                $to = $owners[$imported] ?? null;

                if ($to === null || $to === $from || in_array($to, $config['ruleset'][$from] ?? [], true)) {
                    continue;
                }

                $violations[] = $from.' → '.$to.': '.basename($file).' uses '.$imported;
            }
        }

        sort($violations);

        $this->assertSame([], $violations, "Module boundaries broken:\n".implode("\n", $violations));
    }

    #[Test]
    public function every_class_under_app_belongs_to_a_layer(): void
    {
        $config = $this->config();
        $unclaimed = [];

        foreach ($this->phpFilesIn(dirname(__DIR__, 2).'/app') as $file) {
            if ($this->layerOf($this->relative($file), $config['layers']) === null) {
                $unclaimed[] = $this->relative($file);
            }
        }

        // Deptrac's --fail-on-uncovered cannot express this: it counts every
        // framework class the app touches as uncovered too, so it can never
        // pass. This is the part that was actually meant.
        $this->assertSame([], $unclaimed, "Not covered by any Deptrac layer:\n".implode("\n", $unclaimed));
    }

    /**
     * Fully-qualified name → layer, for every class the app defines.
     *
     * @param  array<string, string>  $layers
     * @return array<string, string>
     */
    private function classesByLayer(array $layers): array
    {
        $owners = [];

        foreach ($this->phpFilesIn(dirname(__DIR__, 2).'/app') as $file) {
            $source = (string) file_get_contents($file);

            if (! preg_match('/^namespace ([\w\\\\]+);/m', $source, $namespace)) {
                continue;
            }

            if (! preg_match('/^(?:final |abstract |readonly )*(?:class|interface|trait|enum) (\w+)/m', $source, $class)) {
                continue;
            }

            $layer = $this->layerOf($this->relative($file), $layers);

            if ($layer !== null) {
                $owners[$namespace[1].'\\'.$class[1]] = $layer;
            }
        }

        return $owners;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(dirname(__DIR__, 2), '', $path), '/');
    }
}
