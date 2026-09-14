<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function makeStubInstallerFixture(): string
{
    $root = sys_get_temp_dir().'/built-for-cloud-stubs-'.bin2hex(random_bytes(8));

    mkdir($root.'/stubs/.claude/skills', 0755, true);

    foreach (['install-stubs.php', 'CLAUDE.md', 'workflow.md', 'README.md'] as $file) {
        copy(dirname(__DIR__, 2).'/stubs/'.$file, $root.'/stubs/'.$file);
    }

    foreach (glob(dirname(__DIR__, 2).'/stubs/.claude/skills/*', GLOB_ONLYDIR) ?: [] as $skill) {
        $destination = $root.'/stubs/.claude/skills/'.basename($skill);
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($skill, FilesystemIterator::SKIP_DOTS));

        foreach ($items as $item) {
            $target = $destination.'/'.substr($item->getPathname(), strlen($skill) + 1);
            is_dir(dirname($target)) || mkdir(dirname($target), 0755, true);
            copy($item->getPathname(), $target);
        }
    }

    return $root;
}

function runStubInstaller(string $root): void
{
    $workingDirectory = getcwd();

    try {
        chdir($root);
        require $root.'/stubs/install-stubs.php';
    } finally {
        chdir($workingDirectory);
    }
}

function removeStubInstallerFixture(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

test('it installs all Built for Cloud app documents and skills and removes the stubs directory', function (): void {
    $root = makeStubInstallerFixture();

    try {
        runStubInstaller($root);

        foreach (['CLAUDE.md', '.solo/workflow.md', 'README.md'] as $destination) {
            $contents = file_get_contents($root.'/'.$destination);

            expect($contents)
                ->not->toBeFalse()
                ->toContain('Built for Cloud')
                ->toContain('{{FILL:');
        }

        foreach (['bfc-app-manifest', 'bfc-logo', 'bfc-readme'] as $skill) {
            expect($root.'/.claude/skills/'.$skill.'/SKILL.md')->toBeFile();
        }

        expect($root.'/stubs')->not->toBeDirectory();
    } finally {
        removeStubInstallerFixture($root);
    }
});

test('it preserves existing documents while installing eligible stubs', function (): void {
    $root = makeStubInstallerFixture();
    $readme = 'Existing app README';
    $workflow = 'Existing app workflow';

    try {
        mkdir($root.'/.solo', 0755, true);
        file_put_contents($root.'/README.md', $readme);
        file_put_contents($root.'/.solo/workflow.md', $workflow);

        runStubInstaller($root);

        expect(file_get_contents($root.'/README.md'))->toBe($readme)
            ->and(file_get_contents($root.'/.solo/workflow.md'))->toBe($workflow)
            ->and(file_get_contents($root.'/CLAUDE.md'))
            ->toContain('Built for Cloud')
            ->toContain('{{FILL:')
            ->and($root.'/stubs')->not->toBeDirectory();
    } finally {
        removeStubInstallerFixture($root);
    }
});

test('it preserves existing skill files while installing the remaining tree', function (): void {
    $root = makeStubInstallerFixture();
    $existing = 'Existing manifest skill';

    try {
        mkdir($root.'/.claude/skills/bfc-app-manifest', 0755, true);
        file_put_contents($root.'/.claude/skills/bfc-app-manifest/SKILL.md', $existing);

        runStubInstaller($root);

        expect(file_get_contents($root.'/.claude/skills/bfc-app-manifest/SKILL.md'))->toBe($existing)
            ->and($root.'/.claude/skills/bfc-app-manifest/scripts/manifest.php')->toBeFile()
            ->and($root.'/.claude/skills/bfc-logo/SKILL.md')->toBeFile()
            ->and($root.'/stubs')->not->toBeDirectory();
    } finally {
        removeStubInstallerFixture($root);
    }
});

test('the committed archive excludes kit-only files and retains scaffold inputs', function (): void {
    $archivePath = sys_get_temp_dir().'/built-for-cloud-archive-'.bin2hex(random_bytes(8)).'.tar';

    try {
        (new Process(
            ['git', 'archive', '--format=tar', '--output='.$archivePath, 'HEAD'],
            dirname(__DIR__, 2),
        ))->mustRun();

        $archive = new PharData($archivePath);

        expect($archive->offsetExists('README.md'))->toBeFalse()
            ->and($archive->offsetExists('tests/Unit/BuiltForCloudSkillsTest.php'))->toBeFalse()
            ->and($archive->offsetExists('tests/Unit/InstallStubsTest.php'))->toBeFalse()
            ->and($archive->offsetExists('tests/Unit/StarterConventionsTest.php'))->toBeFalse()
            ->and($archive->offsetExists('stubs/README.md'))->toBeTrue()
            ->and($archive->offsetExists('stubs/.claude/skills/bfc-app-manifest/SKILL.md'))->toBeTrue()
            ->and($archive->offsetExists('stubs/.claude/skills/bfc-logo/scripts/create-logo.php'))->toBeTrue()
            ->and($archive->offsetExists('stubs/.claude/skills/bfc-readme/scripts/inspect.php'))->toBeTrue();
    } finally {
        unset($archive);

        if (is_file($archivePath)) {
            unlink($archivePath);
        }
    }
});
