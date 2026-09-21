<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function skillScript(string $skill, string $script): string
{
    return dirname(__DIR__, 2)."/stubs/.claude/skills/{$skill}/scripts/{$script}";
}

function skillFixture(): string
{
    $root = sys_get_temp_dir().'/built-for-cloud-skill-'.bin2hex(random_bytes(8));
    mkdir($root.'/config', 0755, true);
    mkdir($root.'/routes', 0755, true);
    symlink(dirname(__DIR__, 2).'/vendor', $root.'/vendor');

    return $root;
}

function removeSkillFixture(string $root): void
{
    if (! is_dir($root)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}

function runSkill(string $script, array $arguments): Process
{
    $process = new Process(array_merge([PHP_BINARY, $script], $arguments));
    $process->run();

    return $process;
}

test('manifest writer emits and validates exactly the frozen overlay', function (): void {
    $root = skillFixture();
    $file = $root.'/config/built-for-cloud.php';

    try {
        $write = runSkill(skillScript('bfc-app-manifest', 'manifest.php'), [
            '--write', '--root='.$root, '--name=Fixture App', '--slug=fixture-app',
            '--description=A useful fixture.', '--icon=https://assets.example.test/images/fixture.svg',
            '--product-url=https://scalpels.app/products/fixture?source=test', '--json',
        ]);
        $result = json_decode($write->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $config = require $file;
        $human = runSkill(skillScript('bfc-app-manifest', 'manifest.php'), ['--check', '--root='.$root]);

        expect($write->getExitCode())->toBe(0)
            ->and($result['ok'])->toBeTrue()
            ->and($human->getExitCode())->toBe(0)
            ->and($human->getOutput())->toStartWith('OK: Manifest is valid:')
            ->and(array_keys($config))->toBe(['manifest', 'credentials', 'ui'])
            ->and(array_keys($config['manifest']))->toBe(['name', 'slug', 'description', 'icon', 'product_url'])
            ->and($config['credentials'])->toBe([
                'guard' => 'bfc',
                'declaration' => null,
                'session_guard' => null,
                'app_purposes' => [],
            ])
            ->and($config['ui'])->toBe([
                'landing_page' => false,
                'member_management' => false,
                'personal_credentials' => false,
                'installation_credentials' => false,
                'session_management' => false,
                'managed_transitions' => false,
                'credential_purposes' => [],
            ]);
    } finally {
        removeSkillFixture($root);
    }
});

test('manifest writer and checker reject root-relative icons', function (): void {
    $root = skillFixture();
    $file = $root.'/config/built-for-cloud.php';
    $arguments = [
        '--write', '--root='.$root, '--name=Fixture App', '--slug=fixture-app',
        '--description=A useful fixture.', '--product-url=https://scalpels.app/products/fixture', '--json',
    ];

    try {
        $write = runSkill(skillScript('bfc-app-manifest', 'manifest.php'), [...$arguments, '--icon=/images/fixture.svg']);
        $writeResult = json_decode($write->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($write->getExitCode())->toBe(1)
            ->and($writeResult['errors'])->toContain('icon must be an absolute HTTPS URL')
            ->and($file)->not->toBeFile();

        $absoluteIcon = 'https://assets.example.test/images/fixture.svg';
        $validWrite = runSkill(skillScript('bfc-app-manifest', 'manifest.php'), [...$arguments, '--icon='.$absoluteIcon]);
        expect($validWrite->getExitCode())->toBe(0);

        file_put_contents($file, str_replace($absoluteIcon, '/images/fixture.svg', (string) file_get_contents($file)));
        $check = runSkill(skillScript('bfc-app-manifest', 'manifest.php'), ['--check', '--root='.$root, '--json']);

        expect($check->getExitCode())->toBe(1)
            ->and(json_decode($check->getOutput(), true, flags: JSON_THROW_ON_ERROR)['errors'])
            ->toContain('manifest.icon must be an absolute HTTPS URL');
    } finally {
        removeSkillFixture($root);
    }
});

test('manifest validator runs standalone against the scaffold config', function (): void {
    $root = dirname(__DIR__, 2);
    $process = new Process([
        PHP_BINARY,
        skillScript('bfc-app-manifest', 'manifest.php'),
        '--check',
        '--json',
    ], $root);
    $process->run();
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->toBe(0)
        ->and($result['ok'])->toBeTrue()
        ->and($result['status'])->toBe('unconfigured');
});

test('manifest validator distinguishes invalid shape and misuse', function (): void {
    $root = skillFixture();
    $file = $root.'/config/built-for-cloud.php';
    file_put_contents($file, "<?php return ['manifest' => [], 'enforcement' => []];\n");

    try {
        $invalid = runSkill(skillScript('bfc-app-manifest', 'manifest.php'), ['--check', '--root='.$root, '--json']);
        $misuse = runSkill(skillScript('bfc-app-manifest', 'manifest.php'), ['--wat', '--json']);

        expect($invalid->getExitCode())->toBe(1)
            ->and(json_decode($invalid->getOutput(), true, flags: JSON_THROW_ON_ERROR)['ok'])->toBeFalse()
            ->and($misuse->getExitCode())->toBe(2)
            ->and(substr_count(trim($misuse->getOutput()), "\n"))->toBe(0);
    } finally {
        removeSkillFixture($root);
    }
});

test('manifest validator accepts a configured product with enabled UI and credential purposes', function (): void {
    $root = skillFixture();
    $file = $root.'/config/built-for-cloud.php';
    $manifest = [
        'name' => 'Sink',
        'slug' => 'sink',
        'description' => 'Capture application events for later inspection.',
        'icon' => 'https://scalpels.app/images/products/sink.svg',
        'product_url' => 'https://scalpels.app/products/sink',
    ];
    $credentials = [
        'guard' => 'bfc',
        'declaration' => null,
        'session_guard' => null,
        'app_purposes' => [
            'sink.ingest' => 'consumption',
            'sink.mcp' => 'consumption',
        ],
    ];
    $ui = [
        'landing_page' => true,
        'member_management' => true,
        'personal_credentials' => false,
        'installation_credentials' => true,
        'session_management' => true,
        'managed_transitions' => true,
        'credential_purposes' => ['sink.ingest', 'sink.mcp'],
    ];
    file_put_contents($file, '<?php return '.var_export(['manifest' => $manifest, 'credentials' => $credentials, 'ui' => $ui], true).';');

    try {
        $configured = runSkill(skillScript('bfc-app-manifest', 'manifest.php'), ['--check', '--root='.$root, '--json']);
        $result = json_decode($configured->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($configured->getExitCode())->toBe(0)
            ->and($result['ok'])->toBeTrue()
            ->and($result['status'])->toBe('configured')
            ->and($result['manifest'])->toBe($manifest)
            ->and($result['ui'])->toBe($ui);
    } finally {
        removeSkillFixture($root);
    }
});

test('manifest writer is confined to the app root config target', function (): void {
    $root = skillFixture();
    $arbitrary = $root.'/arbitrary.php';

    try {
        $process = runSkill(skillScript('bfc-app-manifest', 'manifest.php'), [
            '--write', '--file='.$arbitrary, '--name=Fixture App', '--slug=fixture-app',
            '--description=A useful fixture.', '--icon=https://assets.example.test/images/fixture.svg',
            '--product-url=https://scalpels.app/products/fixture', '--json',
        ]);

        expect($process->getExitCode())->toBe(2)
            ->and($arbitrary)->not->toBeFile()
            ->and($root.'/config/built-for-cloud.php')->not->toBeFile();
    } finally {
        removeSkillFixture($root);
    }
});

test('manifest rejects non-canonical Scalpels product URLs', function (string $url): void {
    $root = skillFixture();
    $arguments = [
        '--write', '--root='.$root, '--name=Fixture App', '--slug=fixture-app',
        '--description=A useful fixture.', '--icon=https://assets.example.test/images/fixture.svg',
    ];

    try {
        $process = runSkill(skillScript('bfc-app-manifest', 'manifest.php'), array_merge($arguments, ['--product-url='.$url, '--json']));

        expect($process->getExitCode())->toBe(1)
            ->and(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['errors'])
            ->toContain('product_url must be an absolute HTTPS URL on scalpels.app')
            ->and($root.'/config/built-for-cloud.php')->not->toBeFile();

        $validUrl = 'https://scalpels.app/products/fixture';
        $valid = runSkill(skillScript('bfc-app-manifest', 'manifest.php'), array_merge($arguments, ['--product-url='.$validUrl]));
        expect($valid->getExitCode())->toBe(0);
        $manifest = $root.'/config/built-for-cloud.php';
        file_put_contents($manifest, str_replace($validUrl, $url, (string) file_get_contents($manifest)));
        $check = runSkill(skillScript('bfc-app-manifest', 'manifest.php'), ['--check', '--root='.$root, '--json']);

        expect($check->getExitCode())->toBe(1)
            ->and(json_decode($check->getOutput(), true, flags: JSON_THROW_ON_ERROR)['errors'])
            ->toContain('manifest.product_url must be an absolute HTTPS URL on scalpels.app');
    } finally {
        removeSkillFixture($root);
    }
})->with([
    'http' => 'http://scalpels.app/products/fixture',
    'foreign host' => 'https://example.test/products/fixture',
]);

test('logo creates the linked asset with labelled inference and preserves existing files', function (): void {
    $root = skillFixture();
    file_put_contents($root.'/config/built-for-cloud.php', "<?php return ['manifest' => ['name' => 'Fixture App', 'icon' => '/images/fixture.svg']];\n");

    try {
        $first = runSkill(skillScript('bfc-logo', 'create-logo.php'), ['--root='.$root, '--json']);
        $result = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $contents = file_get_contents($root.'/public/images/fixture.svg');
        $second = runSkill(skillScript('bfc-logo', 'create-logo.php'), ['--root='.$root, '--json']);

        expect($first->getExitCode())->toBe(0)
            ->and($result['attribution'])->toBe(['name' => 'linked', 'icon' => 'linked', 'initials' => 'inferred'])
            ->and($contents)->toContain('<title>Fixture App</title>')->toContain('>FA</text>')
            ->and($second->getExitCode())->toBe(1)
            ->and(file_get_contents($root.'/public/images/fixture.svg'))->toBe($contents);
    } finally {
        removeSkillFixture($root);
    }
});

test('logo rejects a manifest path that could escape public', function (): void {
    $root = skillFixture();
    file_put_contents($root.'/config/built-for-cloud.php', "<?php return ['manifest' => ['name' => 'Fixture App', 'icon' => '/../escape.svg']];\n");

    try {
        $process = runSkill(skillScript('bfc-logo', 'create-logo.php'), ['--root='.$root, '--json']);

        expect($process->getExitCode())->toBe(1)
            ->and($root.'/escape.svg')->not->toBeFile();
    } finally {
        removeSkillFixture($root);
    }
});

test('readme inspector labels linked inferred and unattributed facts', function (): void {
    $root = skillFixture();
    file_put_contents($root.'/config/built-for-cloud.php', "<?php return ['manifest' => ['name' => 'Fixture App', 'slug' => 'fixture-app', 'description' => null, 'icon' => '/fixture.svg', 'product_url' => 'https://scalpels.app/products/fixture']];\n");
    file_put_contents($root.'/composer.json', json_encode(['name' => 'acme/fixture', 'scripts' => ['setup' => [], 'ready' => []]], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/routes/web.php', "<?php\n");

    try {
        $process = runSkill(skillScript('bfc-readme', 'inspect.php'), ['--root='.$root, '--json']);
        $human = runSkill(skillScript('bfc-readme', 'inspect.php'), ['--root='.$root]);
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($process->getExitCode())->toBe(0)
            ->and($human->getExitCode())->toBe(0)
            ->and($human->getOutput())->toContain('INFERRED: repository_url')
            ->and($result['facts']['manifest.name']['attribution'])->toBe('linked')
            ->and($result['facts']['manifest.description']['attribution'])->toBe('unattributed')
            ->and($result['facts']['repository_url'])->toMatchArray([
                'value' => 'https://github.com/acme/fixture',
                'attribution' => 'inferred',
            ])
            ->and($result['facts']['route_files']['value'])->toBe(['web.php']);
    } finally {
        removeSkillFixture($root);
    }
});

test('each skill script reports misuse as one JSON object', function (string $skill, string $script, array $arguments): void {
    $process = runSkill(skillScript($skill, $script), array_merge($arguments, ['--json']));
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->toBe(2)
        ->and($result)->toMatchArray(['ok' => false])
        ->and($result)->toHaveKey('usage')
        ->and(substr_count(trim($process->getOutput()), "\n"))->toBe(0);
})->with([
    'manifest' => ['bfc-app-manifest', 'manifest.php', ['--unknown']],
    'logo' => ['bfc-logo', 'create-logo.php', ['--unknown']],
    'readme' => ['bfc-readme', 'inspect.php', ['--unknown']],
]);
