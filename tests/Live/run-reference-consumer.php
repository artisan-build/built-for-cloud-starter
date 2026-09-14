#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\ReferenceConsumerInventory;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const FROZEN_STARTER_BASELINE = '11ac35ad3054ca058a5c3c0a1a4f153904fe18e0';

/** @return array<string, string> */
function runnerOptions(): array
{
    $options = getopt('', ['starter-sha:', 'package-repository:', 'package-sha:', 'stamp:']);
    foreach (['starter-sha', 'package-repository', 'package-sha', 'stamp'] as $required) {
        if (! is_string($options[$required] ?? null) || $options[$required] === '') {
            throw new InvalidArgumentException("Missing --{$required}.");
        }
    }

    /** @var array<string, string> $options */
    return $options;
}

/** @param list<string> $command */
function runCommand(array $command, string $cwd, array $environment = [], int $timeout = 600): Process
{
    $process = new Process($command, $cwd, $environment, null, $timeout);
    $process->setInput('');
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException(sprintf(
            "Command failed (%d): %s\n%s%s",
            $process->getExitCode(),
            $process->getCommandLine(),
            $process->getOutput(),
            $process->getErrorOutput(),
        ));
    }

    return $process;
}

/** @return array<string, string> */
function treeSnapshot(string $root): array
{
    $snapshot = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }

        $relative = substr($file->getPathname(), strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
        $snapshot[$relative] = hash_file('sha256', $file->getPathname()) ?: 'unreadable';
    }
    ksort($snapshot);

    return $snapshot;
}

/** @return array<string, mixed> */
function composerPackage(string $repository, string $sha, string $version, string $archive): array
{
    $composer = runCommand(['git', 'show', $sha.':composer.json'], $repository)->getOutput();
    $package = json_decode($composer, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($package)) {
        throw new RuntimeException('Candidate composer.json did not decode to an object.');
    }

    $package['version'] = $version;
    $package['dist'] = [
        'type' => 'zip',
        'url' => 'file://'.$archive,
        'reference' => $sha,
        'shasum' => hash_file('sha256', $archive),
    ];

    return $package;
}

function removeTree(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || $entry->isFile()) {
            unlink($entry->getPathname());
        } else {
            rmdir($entry->getPathname());
        }
    }
    rmdir($root);
}

function writeJson(string $path, mixed $value): void
{
    $parent = dirname($path);
    if (! is_dir($parent) || ! is_writable($parent)) {
        throw new RuntimeException('The stamp parent must be an existing writable directory.');
    }

    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
}

$options = runnerOptions();
$starterRepository = dirname(__DIR__, 2);
$packageRepository = realpath($options['package-repository']);
$stampPath = $options['stamp'];
$runId = 'bfc-p6a2-'.getmypid().'-'.bin2hex(random_bytes(5));
$runRoot = sys_get_temp_dir().'/'.$runId;
$commands = [];
$cases = [];
$cleaned = false;

if (! is_string($packageRepository)
    || ! preg_match('/^[0-9a-f]{40}$/D', $options['starter-sha'])
    || ! preg_match('/^[0-9a-f]{40}$/D', $options['package-sha'])) {
    throw new InvalidArgumentException('Candidate repositories and SHAs must be explicit and valid.');
}

mkdir($runRoot, 0700);

try {
    foreach ([[$starterRepository, $options['starter-sha']], [$packageRepository, $options['package-sha']]] as [$repository, $sha]) {
        $head = trim(runCommand(['git', 'rev-parse', 'HEAD'], $repository)->getOutput());
        $status = runCommand(['git', 'status', '--porcelain'], $repository)->getOutput();
        if ($head !== $sha || $status !== '') {
            throw new RuntimeException("Candidate gate failed for {$repository}.");
        }
    }
    $cases['candidate_gates'] = 'passed';

    $starterArchive = $runRoot.'/starter.zip';
    $packageArchive = $runRoot.'/package.zip';
    runCommand(['git', 'archive', '--format=zip', '--output='.$starterArchive, $options['starter-sha']], $starterRepository);
    runCommand(['git', 'archive', '--format=zip', '--output='.$packageArchive, $options['package-sha']], $packageRepository);
    $starterChecksum = hash_file('sha256', $starterArchive) ?: throw new RuntimeException('Starter checksum failed.');
    $packageChecksum = hash_file('sha256', $packageArchive) ?: throw new RuntimeException('Package checksum failed.');
    $cases['candidate_archives'] = 'passed';

    $composerHome = $runRoot.'/composer-home';
    mkdir($composerHome, 0700);
    writeJson($composerHome.'/config.json', [
        'repositories' => [
            [
                'type' => 'package',
                'canonical' => true,
                'package' => composerPackage($starterRepository, $options['starter-sha'], '1.0.0', $starterArchive),
            ],
            [
                'type' => 'package',
                'canonical' => true,
                'package' => composerPackage($packageRepository, $options['package-sha'], '0.9.99', $packageArchive),
            ],
        ],
    ]);

    $environment = [
        'CI' => '1',
        'COMPOSER_HOME' => $composerHome,
        'COMPOSER_NO_INTERACTION' => '1',
        'GIT_TERMINAL_PROMPT' => '0',
    ];
    $projectName = 'reference-consumer';
    $projectRoot = $runRoot.'/'.$projectName;
    $install = runCommand([
        'laravel', 'new', $projectName, '--using=artisan-build/built-for-cloud-starter',
    ], $runRoot, $environment, 1200);
    $commands[] = [
        'command' => 'laravel new <project> --using=artisan-build/built-for-cloud-starter',
        'exit_code' => $install->getExitCode(),
    ];
    $cases['frozen_install'] = 'passed';

    $lock = json_decode((string) file_get_contents($projectRoot.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    $installed = array_column($lock['packages'] ?? [], null, 'name');
    if (($installed['artisan-build/built-for-cloud']['dist']['reference'] ?? null) !== $options['package-sha']) {
        throw new RuntimeException('The generated app did not resolve the package candidate archive.');
    }
    $cases['candidate_resolution'] = 'passed';

    $spec = [
        'manifest' => [
            'name' => 'Archive Proof Product',
            'slug' => 'archive-proof-product',
            'description' => 'A deterministic reference-consumer archive proof.',
            'icon' => 'https://assets.example.test/archive-proof.svg',
            'product_url' => 'https://scalpels.app/products/archive-proof-product',
        ],
        'credentials' => ['app_purposes' => [
            'archive.consume' => 'consumption',
            'archive.deploy' => 'system_deployment',
        ]],
        'ui' => [
            'landing_page' => true,
            'member_management' => false,
            'personal_credentials' => true,
            'installation_credentials' => false,
            'session_management' => true,
            'managed_transitions' => false,
            'credential_purposes' => ['archive.consume', 'archive.deploy'],
        ],
    ];
    $specPath = $runRoot.'/product.json';
    writeJson($specPath, $spec);

    $invalid = $spec;
    $invalid['unknown'] = true;
    $invalidPath = $runRoot.'/invalid.json';
    writeJson($invalidPath, $invalid);
    $before = treeSnapshot($projectRoot);
    $refusal = new Process([
        PHP_BINARY, 'artisan', 'bfc:starter:configure', '--spec='.$invalidPath, '--no-interaction',
    ], $projectRoot, $environment, null, 120);
    $refusal->run();
    if ($refusal->getExitCode() !== 1 || treeSnapshot($projectRoot) !== $before) {
        throw new RuntimeException('Invalid input changed the generated tree or returned the wrong status.');
    }
    $cases['invalid_input_no_write'] = 'passed';

    $configure = runCommand([
        PHP_BINARY, 'artisan', 'bfc:starter:configure', '--spec='.$specPath, '--no-interaction',
    ], $projectRoot, $environment, 120);
    $commands[] = [
        'command' => 'php artisan bfc:starter:configure --spec=<absolute-json-path> --no-interaction',
        'exit_code' => $configure->getExitCode(),
    ];
    $configureOutput = $configure->getOutput().$configure->getErrorOutput();
    preg_match('/shown once: (\S+)/', $configureOutput, $secretMatch);
    $operatorSecret = $secretMatch[1] ?? '';
    if ($operatorSecret === '' || substr_count($configureOutput, $operatorSecret) !== 1) {
        throw new RuntimeException('The configure command did not reveal one operator secret exactly once.');
    }
    $cases['configure_and_operator_mint'] = 'passed';

    foreach (treeSnapshot($projectRoot) as $relative => $_checksum) {
        $contents = file_get_contents($projectRoot.'/'.$relative);
        if (is_string($contents) && str_contains($contents, $operatorSecret)) {
            throw new RuntimeException("Operator plaintext persisted in {$relative}.");
        }
    }
    $cases['operator_secret_absent_from_files'] = 'passed';

    $rerun = runCommand([
        PHP_BINARY, 'artisan', 'bfc:starter:configure', '--spec='.$specPath, '--no-interaction',
    ], $projectRoot, $environment, 120);
    if (str_contains($rerun->getOutput().$rerun->getErrorOutput(), $operatorSecret)) {
        throw new RuntimeException('The operator secret appeared in subsequent output.');
    }
    $cases['idempotent_rerun'] = 'passed';
    unset($operatorSecret, $configureOutput);

    $inventory = ReferenceConsumerInventory::inspect($projectRoot);
    if ($inventory !== array_fill_keys(ReferenceConsumerInventory::FAMILIES, [])) {
        throw new RuntimeException('The generated app contains an app-owned auth or root artifact.');
    }
    $cases['independent_auth_root_inventory'] = 'passed';

    $conformance = runCommand([
        PHP_BINARY, 'artisan', 'test', 'tests/Feature/BuiltForCloudConformanceTest.php', '--colors=never',
    ], $projectRoot, $environment, 300);
    $commands[] = [
        'command' => 'php artisan test tests/Feature/BuiltForCloudConformanceTest.php --colors=never',
        'exit_code' => $conformance->getExitCode(),
    ];
    $cases['fleet_conformance_v1'] = 'passed';

    $versions = [
        'php' => PHP_VERSION,
        'composer' => trim(runCommand(['composer', '--version', '--no-ansi'], $runRoot, $environment)->getOutput()),
        'laravel_installer' => trim(runCommand(['laravel', '--version', '--no-ansi'], $runRoot, $environment)->getOutput()),
        'framework' => $installed['laravel/framework']['version'] ?? null,
    ];
} finally {
    removeTree($runRoot);
    $cleaned = ! file_exists($runRoot);
}

if (! $cleaned) {
    throw new RuntimeException('The disposable run root was not cleaned.');
}
$cases['clean_teardown'] = 'passed';

writeJson($stampPath, [
    'schema_version' => 1,
    'frozen_starter_baseline_sha' => FROZEN_STARTER_BASELINE,
    'starter_candidate' => ['sha' => $options['starter-sha'], 'archive_sha256' => $starterChecksum],
    'package_candidate' => ['sha' => $options['package-sha'], 'archive_sha256' => $packageChecksum],
    'input_shape' => array_keys($spec),
    'commands' => $commands,
    'versions' => $versions,
    'cases' => $cases,
    'disposable' => ['run_id' => $runId, 'runner_pid' => getmypid(), 'clean_teardown' => true],
]);

fwrite(STDOUT, "Reference-consumer archive proof passed.\nStamp: {$stampPath}\n");
