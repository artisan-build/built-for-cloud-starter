<?php

declare(strict_types=1);

/*
 * Installs this starter kit's documentation stubs into a freshly scaffolded project.
 *
 * The kit's own README.md, CLAUDE.md, and .solo/ are export-ignored (see .gitattributes): they
 * describe the KIT, not the app you just scaffolded. The files in stubs/ are app-facing replacements.
 *
 * Composer runs this from post-create-project-cmd, after which stubs/ deletes itself.
 *
 * Every step is guarded, so running this twice — or in a project that already has its own docs — is
 * a no-op. An existing file is never overwritten.
 */
$moves = [
    'stubs/CLAUDE.md' => 'CLAUDE.md',
    'stubs/workflow.md' => '.solo/workflow.md',
    'stubs/README.md' => 'README.md',
];

foreach (glob('stubs/.claude/skills/*', GLOB_ONLYDIR) ?: [] as $skill) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($skill, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->isFile()) {
            $relative = substr($file->getPathname(), strlen('stubs/'));
            $moves[$file->getPathname()] = $relative;
        }
    }
}

foreach ($moves as $from => $to) {
    if (! is_file($from) || file_exists($to)) {
        continue;
    }

    $directory = dirname($to);

    if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
        continue;
    }

    rename($from, $to);
}

if (is_dir('stubs')) {
    $leftovers = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator('stubs', FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($leftovers as $leftover) {
        $leftover->isDir() ? rmdir($leftover->getPathname()) : unlink($leftover->getPathname());
    }
}

if (is_dir('stubs') && (scandir('stubs') ?: []) === ['.', '..']) {
    rmdir('stubs');
}
