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
}
