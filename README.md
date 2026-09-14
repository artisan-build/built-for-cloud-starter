# Built for Cloud Starter Kit

`artisan-build/built-for-cloud-starter` is the Laravel starter kit for Built for Cloud products. It
provides the common application foundation so a new product can focus on its core problem and be
prepared for the Built for Cloud ecosystem from its first commit.

The kit is derived from [`artisan-build/laravel-nodeless`](https://github.com/artisan-build/laravel-nodeless)
and retains its PHP-first constraint: there is no Node, npm, Vite, or frontend build step. Upstream
nodeless fixes are tracked through this repository's `upstream` git remote.

## Create An App

```bash
laravel new {app} --using=artisan-build/built-for-cloud-starter
```

Replace `{app}` with the directory name for the new application. The scaffold includes app-facing
`README.md`, `CLAUDE.md`, and `.solo/workflow.md` documents with `{{FILL}}` markers. Complete those
markers before development or agent delegation so the product, non-goals, repository, and workflow
are unambiguous.

## Nodeless By Design

- No Node runtime requirement.
- No npm install step.
- No Vite dev server.
- No frontend build step in local development, CI, or deployment.
- Livewire and Flux are the primary UI layer.
- Static CSS, JavaScript, and fonts are committed under `public/build` and served directly by Laravel.

This tradeoff is deliberate. Apps give up an editable Tailwind/Vite pipeline in exchange for a
PHP-only workflow with fewer moving parts.

## Included Foundation

- Laravel 13.19 or newer. This floor supports Laravel Cloud managed queues and must not be relaxed.
- Livewire 4 and Flux 2 (free), with Flux Pro available as an explicit per-project upgrade.
- Built for Cloud's package-owned user model, migrations, and standalone authentication foundation.
- Prebuilt Tailwind and Flux assets served from `public/build`.
- S3-compatible object storage support through `league/flysystem-aws-s3-v3`.
- Pint, Rector, PHPStan/Larastan, Pest, Composer audit, and IDE helper generation.

## App Workflow

After scaffolding, prepare the application with:

```bash
composer setup
```

Run the local server with:

```bash
composer dev
```

Before a pull request, run the complete conformance gate:

```bash
composer ready
```

`composer ready` regenerates IDE helpers, applies Rector and Pint, runs PHPStan and Pest, and audits
Composer dependencies.

## Static Assets

The application loads `public/build/assets/app.css` and `public/build/assets/fonts.css` directly.
There is no default source asset pipeline.

When new Tailwind classes are needed, use the opt-in standalone optimizer and commit its output:

```bash
php artisan tailwind:optimize
```

The command downloads a standalone Tailwind CSS binary under `storage/app/tools`; it does not use
Node, npm, or Vite and is not part of setup or CI.

## Flux Pro

The scaffold uses free Flux and needs no Flux credentials. A project can deliberately opt in to
Flux Pro after scaffolding:

```bash
php artisan flux:pro
```

That project must then provide its own Composer authentication and CI secret configuration.

## Object Storage

Laravel Cloud refuses to deploy an application with a bucket attached unless
`league/flysystem-aws-s3-v3` is installed, so the adapter is part of the starter foundation. An app
that will never use object storage may remove it with `composer remove league/flysystem-aws-s3-v3`.

## Laravel Cloud Deployment

Artisan Build agents handling Laravel Cloud work must follow
`brain/skills/laravel-cloud-deploy/SKILL.md`. Start with read-only discovery. Credentials come from
the authenticated Laravel Cloud CLI; discover application and environment identifiers rather than
guessing or committing them. Keep `.cloud/config.json` uncommitted, and verify the application and
its attached resources after every deployment.

## TODO

- P5-UI landing/package UI.
- P6 install scaffold/conformance checks.
- Credential-purpose declarations beyond the empty default.

## Kit Maintenance

Changes to this repository are inherited by every subsequently scaffolded app. Keep the template
free of machine-specific paths, personal configuration, secrets, and frontend build tooling.

The kit's root `README.md`, `CLAUDE.md`, and `.solo/` describe this repository and are excluded from
Composer archives. App-facing replacements live in `stubs/` and are installed without overwriting
existing destination files when Composer creates a project.

This repository lives at `artisan-build/built-for-cloud-starter`.
