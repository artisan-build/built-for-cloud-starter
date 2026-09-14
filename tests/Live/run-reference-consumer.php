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
        'shasum' => sha1_file($archive),
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

/** @return array{port: int, reservation: resource} */
function reserveLoopbackPort()
{
    $reservation = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($reservation === false) {
        throw new RuntimeException("Unable to reserve a loopback port ({$errorCode}): {$errorMessage}");
    }

    $address = stream_socket_get_name($reservation, false);
    if (! is_string($address) || preg_match('/:(\d+)$/D', $address, $matches) !== 1) {
        fclose($reservation);

        throw new RuntimeException('The OS-allocated loopback port could not be read.');
    }

    return ['port' => (int) $matches[1], 'reservation' => $reservation];
}

/** @return array{status: int, headers: string, body: string} */
function httpRequest(CurlHandle $client, string $method, string $url, array $data = [], array $headers = []): array
{
    curl_setopt_array($client, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $data === [] ? null : http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT_MS => 500,
        CURLOPT_TIMEOUT_MS => 5000,
    ]);
    $captured = curl_exec($client);
    if (! is_string($captured)) {
        throw new RuntimeException('Loopback HTTP failed: '.curl_error($client));
    }

    $headerSize = curl_getinfo($client, CURLINFO_HEADER_SIZE);

    return [
        'status' => curl_getinfo($client, CURLINFO_RESPONSE_CODE),
        'headers' => substr($captured, 0, $headerSize),
        'body' => substr($captured, $headerSize),
    ];
}

/** @return array{tables: int, queue_payloads: int} */
function scanDatabase(string $path, string $secret): array
{
    $database = new PDO('sqlite:'.$path);
    $tables = $database->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")?->fetchAll(PDO::FETCH_COLUMN);
    if (! is_array($tables)) {
        throw new RuntimeException('Unable to inventory the generated database.');
    }

    $queuePayloads = 0;
    foreach ($tables as $table) {
        if (! is_string($table)) {
            continue;
        }

        $quoted = '"'.str_replace('"', '""', $table).'"';
        $rows = $database->query('SELECT * FROM '.$quoted)?->fetchAll(PDO::FETCH_ASSOC);
        if (! is_array($rows)) {
            throw new RuntimeException("Unable to inspect database table {$table}.");
        }

        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                if ($table === 'jobs' && $column === 'payload' && is_string($value)) {
                    $queuePayloads++;
                }

                if (is_string($value) && $value !== hash('sha256', $secret) && str_contains($value, $secret)) {
                    throw new RuntimeException("Operator plaintext persisted in database table {$table}.");
                }
            }
        }
    }

    return ['tables' => count($tables), 'queue_payloads' => $queuePayloads];
}

function credentialCount(string $databasePath): int
{
    return (int) (new PDO('sqlite:'.$databasePath))->query('SELECT COUNT(*) FROM credentials')?->fetchColumn();
}

/** @return array{files: int, logs: int} */
function scanFiles(string $root, string $secret): array
{
    $files = 0;
    $logs = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }

        $files++;
        $relative = substr($file->getPathname(), strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
        $logs += str_starts_with(str_replace('\\', '/', $relative), 'storage/logs/') ? 1 : 0;
        $contents = file_get_contents($file->getPathname());
        if (is_string($contents) && str_contains($contents, $secret)) {
            throw new RuntimeException("Operator plaintext persisted in {$relative}.");
        }
    }

    return ['files' => $files, 'logs' => $logs];
}

/** @return array<string, mixed> */
function createStandaloneUser(string $projectRoot, array $environment): array
{
    $bootstrap = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = ArtisanBuild\BuiltForCloud\User::query()->create([
    'name' => 'Loopback Owner',
    'email' => 'loopback-owner@example.test',
    'password' => Illuminate\Support\Facades\Hash::make((string) getenv('BFC_LOOPBACK_PASSWORD')),
]);
$user->forceFill([
    'role' => ArtisanBuild\BuiltForCloud\UserRole::Owner->value,
    'status' => 'active',
    'email_verified_at' => now(),
    'original_contact_email' => $user->email,
])->save();
echo json_encode([
    'user_class' => $user::class,
    'guard_driver' => config('auth.guards.web.driver'),
    'guard_provider' => config('auth.guards.web.provider'),
    'provider_model' => config('auth.providers.users.model'),
    'login_get' => Illuminate\Support\Facades\Route::has('bfc.login'),
    'login_post' => Illuminate\Support\Facades\Route::has('bfc.login.store'),
], JSON_THROW_ON_ERROR);
PHP;
    $process = runCommand(
        [PHP_BINARY, '-r', $bootstrap],
        $projectRoot,
        [...$environment, 'BFC_LOOPBACK_PASSWORD' => 'loopback-password-created-by-runner'],
        120,
    );
    $observation = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($observation)) {
        throw new RuntimeException('The standalone identity probe returned an invalid observation.');
    }

    return $observation;
}

/** @return array<string, string> */
function runInventoryControls(): array
{
    $controls = [
        'app_human_identity' => ['app/Models/User.php', '<?php class User implements \\Illuminate\\Contracts\\Auth\\Authenticatable {}'],
        'auth_migrations' => ['database/migrations/2026_01_01_000000_create_users_table.php', '<?php return true;'],
        'fortify' => ['app/Providers/FortifyServiceProvider.php', '<?php final class FortifyServiceProvider {}'],
        'app_auth_surface' => ['app/Http/Controllers/Auth/LoginController.php', '<?php final class LoginController {}'],
        'foreign_human_guards' => ['config/auth.php', "<?php return ['guards' => [], 'providers' => []];"],
        'starter_root_collision' => ['routes/web.php', "<?php Route::get('/', fn () => 'collision');"],
    ];
    $verdicts = [];

    foreach ($controls as $family => [$path, $contents]) {
        $root = sys_get_temp_dir().'/bfc-live-inventory-'.bin2hex(random_bytes(6));
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

        try {
            if (ReferenceConsumerInventory::inspect($root)[$family] === []) {
                throw new RuntimeException("The {$family} inventory positive control did not turn red.");
            }
            $verdicts[$family] = 'observed_red';
        } finally {
            removeTree($root);
        }
    }

    return $verdicts;
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
$server = null;

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

    $missingTopLevel = $spec;
    unset($missingTopLevel['ui']);
    $missingManifest = $spec;
    unset($missingManifest['manifest']['icon']);
    $listMapping = $spec;
    $listMapping['credentials']['app_purposes'] = [];
    $invalidDocuments = [
        'unknown_top_level_key' => [...$spec, 'unknown' => true],
        'missing_top_level_key' => $missingTopLevel,
        'missing_manifest_key' => $missingManifest,
        'unknown_manifest_key' => array_replace_recursive($spec, ['manifest' => ['unknown' => 'no']]),
        'invalid_manifest_value' => array_replace_recursive($spec, ['manifest' => ['slug' => 'Not A Slug']]),
        'non_boolean_affordance' => array_replace_recursive($spec, ['ui' => ['landing_page' => 1]]),
        'reserved_signing_root' => array_replace_recursive($spec, ['credentials' => ['app_purposes' => ['archive.consume' => 'signing_root']]]),
        'unmapped_displayed_purpose' => array_replace_recursive($spec, ['ui' => ['credential_purposes' => ['archive.missing']]]),
        'duplicate_displayed_purpose' => array_replace_recursive($spec, ['ui' => ['credential_purposes' => ['archive.consume', 'archive.consume']]]),
        'list_in_place_of_mapping' => $listMapping,
    ];
    $invalidInputVerdicts = [];
    foreach ($invalidDocuments as $name => $invalid) {
        $invalidPath = $runRoot.'/invalid-'.$name.'.json';
        writeJson($invalidPath, $invalid);
        $before = treeSnapshot($projectRoot);
        $refusal = new Process([
            PHP_BINARY, 'artisan', 'bfc:starter:configure', '--spec='.$invalidPath, '--no-interaction',
        ], $projectRoot, $environment, null, 120);
        $refusal->run();
        if ($refusal->getExitCode() !== 1 || treeSnapshot($projectRoot) !== $before || credentialCount($projectRoot.'/database/database.sqlite') !== 0) {
            throw new RuntimeException("Invalid input case {$name} changed the generated tree, minted, or returned the wrong status.");
        }
        $invalidInputVerdicts[$name] = 'refused_without_write';
    }

    $malformedPath = $runRoot.'/invalid-json.json';
    file_put_contents($malformedPath, '{');
    $symlinkPath = $runRoot.'/product.link';
    symlink($specPath, $symlinkPath);
    foreach ([
        'malformed_json' => $malformedPath,
        'relative_path' => 'product.json',
        'directory_path' => $runRoot,
        'symlink_path' => $symlinkPath,
    ] as $name => $path) {
        $before = treeSnapshot($projectRoot);
        $refusal = new Process([
            PHP_BINARY, 'artisan', 'bfc:starter:configure', '--spec='.$path, '--no-interaction',
        ], $projectRoot, $environment, null, 120);
        $refusal->run();
        if ($refusal->getExitCode() !== 1 || treeSnapshot($projectRoot) !== $before || credentialCount($projectRoot.'/database/database.sqlite') !== 0) {
            throw new RuntimeException("Invalid path case {$name} changed the generated tree, minted, or returned the wrong status.");
        }
        $invalidInputVerdicts[$name] = 'refused_without_write';
    }
    $cases['invalid_input_no_write'] = 'passed';

    $lockSabotage = $projectRoot.'/.env.bfc.lock';
    symlink($specPath, $lockSabotage);
    $beforeFileFailure = treeSnapshot($projectRoot);
    $fileFailure = new Process([
        PHP_BINARY, 'artisan', 'bfc:starter:configure', '--spec='.$specPath, '--no-interaction',
    ], $projectRoot, $environment, null, 120);
    $fileFailure->run();
    if ($fileFailure->getExitCode() !== 1
        || treeSnapshot($projectRoot) !== $beforeFileFailure
        || credentialCount($projectRoot.'/database/database.sqlite') !== 0) {
        throw new RuntimeException('A file-stage failure changed the generated tree or minted an operator credential.');
    }
    unlink($lockSabotage);
    $cases['file_stage_failure_mints_nothing'] = 'passed';

    $database = new PDO('sqlite:'.$projectRoot.'/database/database.sqlite');
    $database->exec("CREATE TRIGGER bfc_runner_fail_operator_mint BEFORE INSERT ON credentials BEGIN SELECT RAISE(ABORT, 'runner-forced-mint-failure'); END");
    $mintFailure = new Process([
        PHP_BINARY, 'artisan', 'bfc:starter:configure', '--spec='.$specPath, '--no-interaction',
    ], $projectRoot, $environment, null, 120);
    $mintFailure->run();
    $mintFailureOutput = $mintFailure->getOutput().$mintFailure->getErrorOutput();
    if ($mintFailure->getExitCode() !== 1
        || credentialCount($projectRoot.'/database/database.sqlite') !== 0
        || ! str_contains($mintFailureOutput, 'Install summary:')
        || ! str_contains($mintFailureOutput, 'environment: unchanged')
        || ! str_contains($mintFailureOutput, 'composer: unchanged')
        || ! str_contains($mintFailureOutput, 'configuration: replaced')) {
        throw new RuntimeException('The forced mint failure did not report completed value-free stages with zero mint.');
    }
    $database->exec('DROP TRIGGER bfc_runner_fail_operator_mint');

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
    if (! str_contains($configureOutput, 'configuration: unchanged') || credentialCount($projectRoot.'/database/database.sqlite') !== 1) {
        throw new RuntimeException('The post-failure configure rerun was not recoverable and idempotent for completed file stages.');
    }
    $cases['configure_and_operator_mint'] = 'passed';
    $cases['mint_failure_recoverable_rerun'] = 'passed';

    $rerun = runCommand([
        PHP_BINARY, 'artisan', 'bfc:starter:configure', '--spec='.$specPath, '--no-interaction',
    ], $projectRoot, $environment, 120);
    $subsequentOutput = $rerun->getOutput().$rerun->getErrorOutput();
    if (str_contains($subsequentOutput, $operatorSecret)) {
        throw new RuntimeException('The operator secret appeared in subsequent output.');
    }
    $cases['idempotent_rerun'] = 'passed';

    $identity = createStandaloneUser($projectRoot, $environment);
    if ($identity !== [
        'user_class' => 'ArtisanBuild\\BuiltForCloud\\User',
        'guard_driver' => 'session',
        'guard_provider' => 'users',
        'provider_model' => 'ArtisanBuild\\BuiltForCloud\\User',
        'login_get' => true,
        'login_post' => true,
    ]) {
        throw new RuntimeException('The generated app did not resolve package-owned standalone identity and routes.');
    }

    $portReservation = reserveLoopbackPort();
    $port = $portReservation['port'];
    fclose($portReservation['reservation']);
    $server = new Process([
        PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', '--port='.$port, '--no-reload',
    ], $projectRoot, [...$environment, 'MAIL_MAILER' => 'array'], null, null);
    $server->start();
    $serverPid = $server->getPid();

    $client = curl_init();
    if (! $client instanceof CurlHandle) {
        throw new RuntimeException('Unable to initialize the loopback HTTP client.');
    }
    curl_setopt($client, CURLOPT_COOKIEFILE, '');

    $loginForm = null;
    $deadline = microtime(true) + 20;
    do {
        if (! $server->isRunning()) {
            throw new RuntimeException('The loopback server exited before readiness: '.$server->getErrorOutput());
        }

        try {
            $candidate = httpRequest($client, 'GET', 'http://127.0.0.1:'.$port.'/bfc/login');
            if ($candidate['status'] === 200) {
                $loginForm = $candidate;
                break;
            }
        } catch (RuntimeException) {
            usleep(100_000);
        }
    } while (microtime(true) < $deadline);

    if ($loginForm === null || ! str_contains($loginForm['body'], 'data-testid="login-form"')) {
        throw new RuntimeException('The package login form did not become ready on loopback.');
    }
    if (preg_match('/name="_token" value="([^"]+)"/', $loginForm['body'], $csrfMatch) !== 1) {
        throw new RuntimeException('The package login form did not render a CSRF token.');
    }

    $login = httpRequest($client, 'POST', 'http://127.0.0.1:'.$port.'/bfc/login', [
        '_token' => html_entity_decode($csrfMatch[1], ENT_QUOTES | ENT_HTML5),
        'email' => 'loopback-owner@example.test',
        'password' => 'loopback-password-created-by-runner',
    ], ['Content-Type: application/x-www-form-urlencoded']);
    $memberPage = httpRequest($client, 'GET', 'http://127.0.0.1:'.$port.'/bfc/members');
    if ($login['status'] !== 302
        || ! preg_match('#^Location: https?://127\.0\.0\.1(?::\d+)?/?\r?$#mi', $login['headers'])
        || $memberPage['status'] !== 200
        || ! str_contains($memberPage['body'], 'loopback-owner@example.test')) {
        throw new RuntimeException('Standalone package login did not establish an authenticated web session.');
    }
    $cases['standalone_package_human_auth_loopback'] = 'passed';

    $credentialListing = httpRequest(
        $client,
        'GET',
        'http://127.0.0.1:'.$port.'/bfc/credentials',
        headers: ['Authorization: Bearer '.$operatorSecret, 'Accept: application/json'],
    );
    if ($credentialListing['status'] !== 200) {
        throw new RuntimeException('The configure-minted operator credential was not authorized by the real HTTP route.');
    }
    $cases['minted_operator_http_authority'] = 'passed';

    $processes = runCommand(['ps', '-axo', 'pid=,command='], $runRoot)->getOutput();
    if (str_contains($processes, $operatorSecret)) {
        throw new RuntimeException('Operator plaintext appeared in process arguments.');
    }
    $cases['operator_secret_absent_from_process_argv'] = 'passed';

    $subsequentOutput .= $loginForm['headers'].$loginForm['body']
        .$login['headers'].$login['body']
        .$memberPage['headers'].$memberPage['body']
        .$credentialListing['headers'].$credentialListing['body']
        .$processes;
    curl_close($client);

    $listener = new Process(['lsof', '-nP', '-iTCP:'.$port, '-sTCP:LISTEN', '-t'], $runRoot);
    $listener->run();
    $listenerPid = trim($listener->getOutput());
    if ($listener->getExitCode() !== 0 || preg_match('/^\d+$/D', $listenerPid) !== 1) {
        throw new RuntimeException('The bounded loopback listener identity could not be observed.');
    }

    $server->stop(3, SIGTERM);
    $subsequentOutput .= $server->getOutput().$server->getErrorOutput();
    $server = null;
    $probe = @fsockopen('127.0.0.1', $port, $probeError, $probeMessage, 0.5);
    if (is_resource($probe)) {
        fclose($probe);

        throw new RuntimeException('The loopback listener survived bounded teardown.');
    }
    $cases['loopback_listener_clean_teardown'] = 'passed';

    $inventory = ReferenceConsumerInventory::inspect($projectRoot);
    if ($inventory !== array_fill_keys(ReferenceConsumerInventory::FAMILIES, [])) {
        throw new RuntimeException('The generated app contains an app-owned auth or root artifact.');
    }
    $cases['independent_auth_root_inventory'] = 'passed';
    $inventoryControls = runInventoryControls();
    $cases['inventory_positive_controls'] = 'passed';

    $conformance = runCommand([
        PHP_BINARY, 'artisan', 'test', 'tests/Feature/BuiltForCloudConformanceTest.php', '--colors=never',
    ], $projectRoot, $environment, 300);
    $commands[] = [
        'command' => 'php artisan test tests/Feature/BuiltForCloudConformanceTest.php --colors=never',
        'exit_code' => $conformance->getExitCode(),
    ];
    $cases['fleet_conformance_v1'] = 'passed';
    $subsequentOutput .= $conformance->getOutput().$conformance->getErrorOutput();

    $versions = [
        'php' => PHP_VERSION,
        'composer' => trim(runCommand(['composer', '--version', '--no-ansi'], $runRoot, $environment)->getOutput()),
        'laravel_installer' => trim(runCommand(['laravel', '--version', '--no-ansi'], $runRoot, $environment)->getOutput()),
        'framework' => $installed['laravel/framework']['version'] ?? null,
    ];

    $fileEvidence = scanFiles($runRoot, $operatorSecret);
    $databaseEvidence = scanDatabase($projectRoot.'/database/database.sqlite', $operatorSecret);
    if (str_contains($subsequentOutput, $operatorSecret)) {
        throw new RuntimeException('The operator secret appeared in output after its one-time observation.');
    }
    $cases['operator_secret_absent_from_files'] = 'passed';
    $cases['operator_secret_absent_from_logs'] = 'passed';
    $cases['operator_secret_absent_from_exceptions'] = 'passed';
    $cases['operator_secret_absent_from_queue_payloads'] = 'passed';
    $cases['operator_secret_absent_from_database_plaintext'] = 'passed';
    $cases['operator_secret_absent_from_subsequent_output'] = 'passed';
    $sinkEvidence = [
        'process_argv' => ['processes_scanned' => substr_count(trim($processes), "\n") + 1],
        'files' => $fileEvidence,
        'logs' => ['files_scanned' => $fileEvidence['logs']],
        'exceptions' => ['listener_stderr_scanned' => true, 'captured_throwables' => 0],
        'queue_payloads' => ['database_payloads_scanned' => $databaseEvidence['queue_payloads']],
        'database' => ['tables_scanned' => $databaseEvidence['tables']],
        'subsequent_output' => ['bytes_scanned' => strlen($subsequentOutput)],
    ];
    unset($operatorSecret, $configureOutput, $subsequentOutput);
} finally {
    if ($server instanceof Process && $server->isRunning()) {
        $server->stop(3, SIGTERM);
    }
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
    'invalid_input_matrix' => $invalidInputVerdicts,
    'install_recovery' => [
        'file_stage_failure_exit_code' => $fileFailure->getExitCode(),
        'file_stage_failure_credential_count' => 0,
        'mint_failure_exit_code' => $mintFailure->getExitCode(),
        'mint_failure_completed_stages' => ['environment' => 'unchanged', 'composer' => 'unchanged', 'configuration' => 'replaced'],
        'mint_failure_credential_count' => 0,
        'recovery_configuration_state' => 'unchanged',
        'recovery_credential_count' => 1,
    ],
    'inventory_positive_controls' => $inventoryControls,
    'live_http' => [
        'host' => '127.0.0.1',
        'os_allocated_port' => $port,
        'server_launcher_pid' => $serverPid,
        'listener_pid' => (int) $listenerPid,
        'package_identity' => $identity,
        'standalone_login_status' => $login['status'],
        'authenticated_member_status' => $memberPage['status'],
        'operator_authority_status' => $credentialListing['status'],
        'mail_transport' => 'array',
    ],
    'secret_sinks' => $sinkEvidence,
    'disposable' => ['run_id' => $runId, 'runner_pid' => getmypid(), 'clean_teardown' => true, 'listener_closed' => true],
]);

fwrite(STDOUT, "Reference-consumer archive proof passed.\nStamp: {$stampPath}\n");
