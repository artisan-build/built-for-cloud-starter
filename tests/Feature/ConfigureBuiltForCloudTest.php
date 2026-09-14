<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Credential;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

function starterConfigurationSpec(array $overrides = []): array
{
    return array_replace_recursive([
        'manifest' => [
            'name' => 'Test Created Product',
            'slug' => 'test-created-product',
            'description' => 'A product created by the configure-command test.',
            'icon' => 'https://assets.example.test/test-created.svg',
            'product_url' => 'https://scalpels.app/products/test-created-product',
        ],
        'credentials' => [
            'app_purposes' => [
                'test.consume' => 'consumption',
                'test.deploy' => 'system_deployment',
            ],
        ],
        'ui' => [
            'landing_page' => true,
            'member_management' => false,
            'personal_credentials' => true,
            'installation_credentials' => false,
            'session_management' => true,
            'managed_transitions' => false,
            'credential_purposes' => ['test.consume', 'test.deploy'],
        ],
    ], $overrides);
}

function writeStarterConfigurationSpec(array $spec): string
{
    $path = tempnam(sys_get_temp_dir(), 'bfc-starter-spec-');
    file_put_contents($path, json_encode($spec, JSON_THROW_ON_ERROR));

    return $path;
}

it('refuses invalid input before changing the generated tree', function (callable $change): void {
    $path = writeStarterConfigurationSpec($change(starterConfigurationSpec()));
    $before = (string) file_get_contents(config_path('built-for-cloud.php'));

    try {
        expect(Artisan::call('bfc:starter:configure', ['--spec' => $path, '--no-interaction' => true]))
            ->toBe(1)
            ->and((string) file_get_contents(config_path('built-for-cloud.php')))->toBe($before)
            ->and(Credential::query()->count())->toBe(0);
    } finally {
        unlink($path);
    }
})->with([
    'unknown top-level key' => fn (array $spec): array => [...$spec, 'unknown' => true],
    'missing manifest key' => function (array $spec): array {
        unset($spec['manifest']['icon']);

        return $spec;
    },
    'non-boolean affordance' => fn (array $spec): array => array_replace_recursive($spec, ['ui' => ['landing_page' => 1]]),
    'reserved signing root' => fn (array $spec): array => array_replace_recursive($spec, ['credentials' => ['app_purposes' => ['test.consume' => 'signing_root']]]),
    'unmapped displayed purpose' => fn (array $spec): array => array_replace_recursive($spec, ['ui' => ['credential_purposes' => ['test.missing']]]),
    'list in place of app-purpose object' => function (array $spec): array {
        $spec['credentials']['app_purposes'] = [];

        return $spec;
    },
]);

it('requires an absolute regular spec path', function (string $path): void {
    $before = (string) file_get_contents(config_path('built-for-cloud.php'));

    expect(Artisan::call('bfc:starter:configure', ['--spec' => $path, '--no-interaction' => true]))
        ->toBe(1)
        ->and((string) file_get_contents(config_path('built-for-cloud.php')))->toBe($before)
        ->and(Credential::query()->count())->toBe(0);
})->with(['relative.json', __DIR__]);

it('writes the exact overlay and reruns without rewriting or reminting', function (): void {
    $path = writeStarterConfigurationSpec(starterConfigurationSpec());
    $configurationPath = config_path('built-for-cloud.php');
    $original = (string) file_get_contents($configurationPath);

    try {
        $output = new BufferedOutput;
        $exitCode = Artisan::call('bfc:starter:configure', ['--spec' => $path, '--no-interaction' => true], $output);
        $firstOutput = $output->fetch();
        expect($exitCode)->toBe(0, $firstOutput);
        $configured = require $configurationPath;
        $mtime = filemtime($configurationPath);

        $expected = starterConfigurationSpec();
        $expected['credentials'] = [
            'guard' => 'bfc',
            'declaration' => null,
            'session_guard' => null,
            ...$expected['credentials'],
        ];

        expect($configured)->toBe($expected)
            ->and($firstOutput)->toContain('configuration: replaced', 'shown once')
            ->and(Credential::query()->count())->toBe(1);

        preg_match('/shown once: (\S+)/', $firstOutput, $secret);
        expect($secret)->toHaveCount(2)
            ->and(substr_count($firstOutput, $secret[1]))->toBe(1)
            ->and(Credential::query()->sole()->secret_hash)->toBe(hash('sha256', $secret[1]));

        $output = new BufferedOutput;
        expect(Artisan::call('bfc:starter:configure', ['--spec' => $path, '--no-interaction' => true], $output))->toBe(0);

        expect($output->fetch())->toContain('configuration: unchanged', 'already exists; skipping')
            ->not->toContain('shown once')
            ->and(filemtime($configurationPath))->toBe($mtime)
            ->and(Credential::query()->count())->toBe(1);
    } finally {
        file_put_contents($configurationPath, $original);
        unlink($path);
    }
});

it('accepts an empty app-purpose JSON object without granting a purpose', function (): void {
    $spec = starterConfigurationSpec();
    $spec['credentials']['app_purposes'] = (object) [];
    $spec['ui']['credential_purposes'] = [];
    $path = writeStarterConfigurationSpec($spec);
    $configurationPath = config_path('built-for-cloud.php');
    $original = (string) file_get_contents($configurationPath);

    try {
        expect(Artisan::call('bfc:starter:configure', ['--spec' => $path, '--no-interaction' => true]))
            ->toBe(0, Artisan::output())
            ->and((require $configurationPath)['credentials']['app_purposes'])->toBe([]);
    } finally {
        file_put_contents($configurationPath, $original);
        unlink($path);
    }
});
