<?php

use ArtisanBuild\BuiltForCloud\BuiltForCloudServiceProvider;
use ArtisanBuild\BuiltForCloud\User;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

it('owns only the pending D-UI-3 configuration overlay', function (): void {
    /** @var array<string, mixed> $appConfig */
    $appConfig = require config_path('built-for-cloud.php');

    expect($appConfig)->toBe([
        'manifest' => [
            'name' => null,
            'slug' => null,
            'description' => null,
            'icon' => null,
            'product_url' => null,
        ],
        'ui' => [
            'landing_page' => false,
            'member_management' => false,
            'personal_credentials' => false,
            'installation_credentials' => false,
            'session_management' => false,
            'managed_transitions' => false,
            'credential_purposes' => [],
        ],
    ]);
});

it('merges package defaults and auto-discovers the released provider', function (): void {
    expect(config('built-for-cloud.manifest'))->toBe([
        'name' => null,
        'slug' => null,
        'description' => null,
        'icon' => null,
        'product_url' => null,
    ])->and(config('built-for-cloud.ui'))->toBe([
        'landing_page' => false,
        'member_management' => false,
        'personal_credentials' => false,
        'installation_credentials' => false,
        'session_management' => false,
        'managed_transitions' => false,
        'credential_purposes' => [],
    ])->and(config('built-for-cloud.product'))->toBe(config('app.name'))
        ->and(config('built-for-cloud.credentials.guard'))->toBe('bfc')
        ->and(config('auth.providers.users.model'))->toBe(User::class)
        ->and(app()->getLoadedProviders())->toHaveKey(BuiltForCloudServiceProvider::class, true)
        ->and(InstalledVersions::getPrettyVersion('artisan-build/built-for-cloud'))->toBe('v0.9.2');
});

it('runs fresh package-owned migrations on sqlite', function (): void {
    expect(Artisan::call('migrate:fresh', [
        '--database' => 'sqlite',
        '--force' => true,
    ]))->toBe(0)
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('bfc_authority'))->toBeTrue()
        ->and(Schema::hasTable('credentials'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'normalized_email'))->toBeTrue()
        ->and(glob(database_path('migrations/*users*')) ?: [])->toBe([])
        ->and(class_exists('App\\Models\\User'))->toBeFalse();
});
