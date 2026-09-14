<?php

declare(strict_types=1);

use Tests\Support\ReferenceConsumerInventory;

function inventoryControlRoot(string $path, string $contents): string
{
    $root = sys_get_temp_dir().'/bfc-inventory-'.bin2hex(random_bytes(6));
    mkdir($root, 0700);
    mkdir($root.'/config', 0700, true);
    file_put_contents($root.'/config/auth.php', <<<'PHP'
<?php

use ArtisanBuild\BuiltForCloud\User;

return [
    'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']],
    'providers' => ['users' => ['driver' => 'eloquent', 'model' => User::class]],
];
PHP);
    if (! is_dir(dirname($root.'/'.$path))) {
        mkdir(dirname($root.'/'.$path), 0700, true);
    }
    file_put_contents($root.'/'.$path, $contents);

    return $root;
}

it('finds no app-owned auth or root surface in the exported starter tree', function (): void {
    expect(ReferenceConsumerInventory::inspect(dirname(__DIR__, 2)))
        ->toBe(array_fill_keys(ReferenceConsumerInventory::FAMILIES, []));
});

it('observes every independently introduced inventory control red', function (string $family, string $path, string $contents): void {
    $root = inventoryControlRoot($path, $contents);

    try {
        expect(ReferenceConsumerInventory::inspect($root)[$family])->not->toBe([]);
    } finally {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($root);
    }
})->with([
    'app human identity' => ['app_human_identity', 'app/Models/User.php', '<?php class User implements \\Illuminate\\Contracts\\Auth\\Authenticatable {}'],
    'auth migration' => ['auth_migrations', 'database/migrations/2026_01_01_000000_create_users_table.php', '<?php return true;'],
    'Fortify provider' => ['fortify', 'app/Providers/FortifyServiceProvider.php', '<?php final class FortifyServiceProvider {}'],
    'app login controller' => ['app_auth_surface', 'app/Http/Controllers/Auth/LoginController.php', '<?php final class LoginController {}'],
    'foreign human provider' => ['foreign_human_guards', 'config/auth.php', "<?php return ['guards' => [], 'providers' => []];"],
    'starter root route' => ['starter_root_collision', 'routes/web.php', "<?php Route::get('/', fn () => 'collision');"],
]);
