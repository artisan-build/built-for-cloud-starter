<?php

declare(strict_types=1);

function makeStubInstallerFixture(): string
{
    $root = sys_get_temp_dir().'/built-for-cloud-stubs-'.bin2hex(random_bytes(8));

    mkdir($root.'/stubs', 0755, true);

    foreach (['install-stubs.php', 'CLAUDE.md', 'workflow.md', 'README.md'] as $file) {
        copy(dirname(__DIR__, 2).'/stubs/'.$file, $root.'/stubs/'.$file);
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

test('it installs all Built for Cloud app documents and removes the stubs directory', function (): void {
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
