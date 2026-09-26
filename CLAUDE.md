# built-for-cloud-starter

This repo is the `artisan-build/built-for-cloud-starter` starter kit for Built for Cloud products.
It gives new Laravel apps the common foundation needed to join the Built for Cloud ecosystem once
their core product problem is solved.

The kit is derived from `artisan-build/laravel-nodeless`, retained as the `upstream` git remote. Its
nodeless constraint remains non-negotiable: no Node, npm, Vite, or frontend build step. Do not
introduce frontend build tooling.

Changes here are inherited by every project spawned from this kit. Keep the template free of
machine-specific paths, personal configuration, and secrets. Create an app with
`laravel new {app} --using=artisan-build/built-for-cloud-starter`.

## Workflow

Feature builds: see `.solo/workflow.md` and the `multi-agent-build` skill.

Hard gate before any PR: `composer ready` (ide-helper + rector + pint + phpstan + pest + audit).

## Setting the manifest

Before the first deploy, replace every placeholder in the `manifest` block of
`config/built-for-cloud.php`. Set `name` to the Scalpels display name, `slug` to the exact
lower-kebab-case Scalpels catalog slug, `description` to the product page's one-sentence
description, `icon` to `https://scalpels.app/img/products/transparent/{slug}.png`, and
`product_url` to `https://scalpels.app/products/{slug}`. The slug must match the catalog because it
selects the product artwork.

## Scaffold Documents

The root `CLAUDE.md` and `.solo/` describe this kit and are export-ignored. App-facing documents live
in `stubs/`; the guarded Composer post-create hook installs them without overwriting existing files.

## IDE Helper Files

`_ide_helper.php` and `_ide_helper_models.php` are committed on purpose (PHPStan scans
`_ide_helper_models.php`). `.phpstorm.meta.php` is gitignored on purpose (it embeds absolute local
paths). This asymmetry is deliberate; do not make them consistent in either direction.
