<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Discovers every model that declares itself tenant-scoped.
 *
 * Discovery rather than a hand-maintained list is the point: a new model that
 * uses BelongsToTenant is enrolled in the isolation suite automatically, and
 * one that should be scoped but has no coverage fails the build instead of
 * shipping untested.
 */
final class TenantScopedModels
{
    /**
     * Resolved from this file rather than app_path(), because PHPUnit
     * evaluates data providers before the framework boots — a helper that
     * needed the container here would silently return nothing, and the
     * isolation suite would pass with zero cases.
     */
    private static function appPath(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app';
    }

    /** @return array<int, class-string<Model>> */
    public static function all(): array
    {
        $models = [];

        foreach (self::phpFilesIn(self::appPath()) as $file) {
            $class = self::classFor($file);

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if (! $reflection->isSubclassOf(Model::class) || $reflection->isAbstract()) {
                continue;
            }

            if (in_array(BelongsToTenant::class, self::traitsOf($reflection), true)) {
                $models[] = $class;
            }
        }

        sort($models);

        return $models;
    }

    /** @return array<int, SplFileInfo> */
    private static function phpFilesIn(string $path): array
    {
        return iterator_to_array(Finder::create()->files()->in($path)->name('*.php'), false);
    }

    /** @return class-string|null */
    private static function classFor(SplFileInfo $file): ?string
    {
        $relative = str_replace([self::appPath().DIRECTORY_SEPARATOR, '.php'], '', $file->getRealPath());
        $class = 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

        return class_exists($class) ? $class : null;
    }

    /** @return array<int, string> */
    private static function traitsOf(ReflectionClass $reflection): array
    {
        $traits = [];

        while ($reflection !== false) {
            $traits = [...$traits, ...array_keys($reflection->getTraits())];
            $reflection = $reflection->getParentClass();
        }

        return $traits;
    }
}
