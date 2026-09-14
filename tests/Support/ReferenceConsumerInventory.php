<?php

declare(strict_types=1);

namespace Tests\Support;

use ArtisanBuild\BuiltForCloud\User;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ReferenceConsumerInventory
{
    /** @var list<string> */
    public const array FAMILIES = [
        'app_human_identity',
        'auth_migrations',
        'fortify',
        'app_auth_surface',
        'foreign_human_guards',
        'starter_root_collision',
    ];

    /** @return array<string, list<string>> */
    public static function inspect(string $root): array
    {
        $found = array_fill_keys(self::FAMILIES, []);

        foreach (self::files($root) as $relative => $path) {
            $normalized = str_replace('\\', '/', $relative);
            $lower = strtolower($normalized);
            $contents = str_ends_with($lower, '.php') ? (string) file_get_contents($path) : '';

            if (str_starts_with($lower, 'app/') && str_ends_with($lower, '.php') && (
                preg_match('/\b(class\s+User|implements\s+[^\{;]*Authenticatable|extends\s+[^\{;]*Authenticatable)\b/i', $contents) === 1
                || $lower === 'app/models/user.php'
            )) {
                $found['app_human_identity'][] = $normalized;
            }

            if (str_starts_with($lower, 'database/migrations/') && preg_match('/(create|alter|update).*(users?|auth|password|session)/', basename($lower)) === 1) {
                $found['auth_migrations'][] = $normalized;
            }

            if ($lower === 'config/fortify.php'
                || str_starts_with($lower, 'app/actions/fortify/')
                || str_contains($lower, 'fortifyserviceprovider.php')) {
                $found['fortify'][] = $normalized;
            }

            if (str_starts_with($lower, 'app/http/controllers/auth/')
                || (str_starts_with($lower, 'app/http/controllers/') && preg_match('/(login|register|password|reset)/', basename($lower)) === 1)
                || str_starts_with($lower, 'resources/views/auth/')
                || preg_match('#^resources/views/(login|register|forgot-password|reset-password)([./])#', $lower) === 1
                || (str_starts_with($lower, 'routes/') && preg_match("#['\"]/(login|register|forgot-password|reset-password|password)#", $contents) === 1)) {
                $found['app_auth_surface'][] = $normalized;
            }

            if ($lower === 'resources/views/welcome.blade.php'
                || (str_starts_with($lower, 'routes/') && preg_match("#(?:get|view|match|any)\s*\(\s*['\"]/['\"]#i", $contents) === 1)) {
                $found['starter_root_collision'][] = $normalized;
            }
        }

        $authPath = rtrim($root, DIRECTORY_SEPARATOR).'/config/auth.php';
        if (is_file($authPath)) {
            $auth = (static fn (string $path): mixed => require $path)($authPath);
            $expectedGuards = ['web' => ['driver' => 'session', 'provider' => 'users']];
            $expectedProviders = ['users' => ['driver' => 'eloquent', 'model' => User::class]];

            if (! is_array($auth)
                || ($auth['guards'] ?? null) !== $expectedGuards
                || ($auth['providers'] ?? null) !== $expectedProviders) {
                $found['foreign_human_guards'][] = 'config/auth.php';
            }
        } else {
            $found['foreign_human_guards'][] = 'config/auth.php:missing';
        }

        foreach ($found as &$members) {
            $members = array_values(array_unique($members));
            sort($members);
        }

        return $found;
    }

    /** @return array<string, string> */
    private static function files(string $root): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $files[substr($file->getPathname(), strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1)] = $file->getPathname();
        }
        ksort($files);

        return $files;
    }
}
