<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function starterRoot(): string
{
    return dirname(__DIR__, 2);
}

test('dependency floors and the local assurance ladder remain intact', function (): void {
    $composer = json_decode(
        (string) file_get_contents(starterRoot().'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['require']['laravel/framework'])->toBe('^13.19')
        ->and($composer['require']['artisan-build/built-for-cloud'])->toBe('^0.9')
        ->and($composer['scripts']['ready'])->toBe([
            '@ide-helper',
            '@rector',
            '@lint',
            '@stan',
            '@test',
            'composer audit',
        ])
        ->and($composer['scripts']['rector:check'])->toBe([
            'rector process app bootstrap/app.php bootstrap/providers.php config public resources routes tests --dry-run',
        ]);
});

test('CI enforces the package-installed assurance ladder', function (): void {
    $tests = (string) file_get_contents(starterRoot().'/.github/workflows/tests.yml');
    $lint = (string) file_get_contents(starterRoot().'/.github/workflows/lint.yml');

    expect($tests)
        ->toContain('run: composer rector:check')
        ->toContain('run: composer stan')
        ->toContain('run: ./vendor/bin/pest')
        ->toContain('run: composer audit')
        ->and($lint)->toContain('run: composer lint');
});

test('Cloud repository bindings and identifiers are not committed', function (): void {
    $tracked = new Process(['git', 'ls-files', '-z'], starterRoot());
    $tracked->mustRun();
    $files = array_filter(explode("\0", $tracked->getOutput()));
    $identifierAssignment = '/(?:application|app|environment)[_-]?id[\"\']?\s*[:=]\s*[\"\'][^\"\']+[\"\']/i';

    expect($files)->not->toContain('.cloud/config.json')
        ->and((string) file_get_contents(starterRoot().'/.gitignore'))->toContain('/.cloud/');

    foreach ($files as $file) {
        $contents = file_get_contents(starterRoot().'/'.$file);

        if ($contents !== false) {
            expect($contents)->not->toMatch($identifierAssignment);
        }
    }
});

test('the documented deployment convention and phase deferrals remain explicit', function (): void {
    $kitReadme = (string) file_get_contents(starterRoot().'/README.md');
    $appReadme = (string) file_get_contents(starterRoot().'/stubs/README.md');
    $skill = 'brain/skills/laravel-cloud-deploy/SKILL.md';

    expect($kitReadme)
        ->toContain($skill)
        ->toContain('P5-UI landing/package UI')
        ->toContain('P6 install scaffold/conformance checks')
        ->toContain('Credential-purpose declarations beyond the empty default')
        ->and($appReadme)->toContain($skill);
});
