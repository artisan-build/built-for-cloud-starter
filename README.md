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

## Setting the manifest

Before the first deploy, replace every placeholder in the `manifest` block of
`config/built-for-cloud.php`:

| Field | Set it to |
| --- | --- |
| `name` | The product's display name as Scalpels lists it. |
| `slug` | The product's lower-kebab-case Scalpels catalog slug. |
| `description` | One sentence saying what the product does, as on its Scalpels product page. |
| `icon` | `https://scalpels.app/img/products/transparent/{slug}.png` |
| `product_url` | `https://scalpels.app/products/{slug}` |

The `slug` must exactly match the product's Scalpels catalog slug; its artwork loads from
`https://scalpels.app/img/products/transparent/{slug}.png`.

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

## Scaffold Proof

Live-verified on 2026-09-14 from candidate
`3f46e4a44e83846a7df5cebb9fe41f69c2ab336e` using a fresh `mktemp -d` directory. The proof used
`git archive --format=zip --output="$PROOF/candidate.zip" HEAD`, inspected the ZIP with
`unzip -Z1`, and installed it with `composer create-project --no-interaction` through a local
Composer `package` repository as synthetic version `dev-proof`.

- The 413,878-byte archive excluded `.env`, `vendor/`, `database/database.sqlite`, root
  `README.md`, root `CLAUDE.md`, root `.solo/`, and the three kit-maintenance tests. It retained
  the app-valid Feature, Integration, and Unit example tests, all app-facing stubs, and all three
  skill trees.
- Create-project installed 156 packages from scratch, discovered
  `ArtisanBuild\BuiltForCloud\BuiltForCloudServiceProvider`, created a new SQLite database, and ran
  all 33 application and package migrations. Every post-create hook completed successfully.
- `composer show artisan-build/built-for-cloud --format=json` reported released version `v0.9.2`.
  A bootstrapped application assertion confirmed the package provider was loaded and the `users`
  auth provider used `ArtisanBuild\BuiltForCloud\User`; `php artisan migrate:status` reported every
  migration as `Ran` in batch 1.
- Installed inventory: `README.md`, `CLAUDE.md`, `.solo/workflow.md`, and each `SKILL.md` plus helper
  under `.claude/skills/{bfc-app-manifest,bfc-logo,bfc-readme}`. All three helpers were executable;
  `stubs/`, `.cloud/config.json`, Cloud identifier assignments, and kit-only document content were
  absent.
- `php .claude/skills/bfc-app-manifest/scripts/manifest.php --check --json` returned `ok: true` and
  `status: unconfigured` for exactly the five null manifest values, six false UI flags, and empty
  `credential_purposes`. Focused regression coverage also rejected a partial manifest.
- The generated app's `composer ready` passed: IDE helpers generated, Rector and Pint passed,
  PHPStan reported zero errors, Pest passed 5 tests with 22 assertions, and Composer reported no
  security advisories.
- Before the live proof, focused starter checks passed: manifest skills, 12 tests / 64 assertions;
  archive boundary, 4 tests / 33 assertions; Pint passed both edited PHP files. Direct file-scoped
  PHPStan was not applicable to the excluded archive test and separately exposed the pre-existing
  untyped `finish(array $result)` signature in the standalone skill script.
- After evidence capture, the resolved bounded temp tree containing the generated app and ZIP was
  deleted; nonexistence was verified. The rejected earlier `composer archive` tree and failed clean
  proof tree were also deleted before this successful run.

## Kit Maintenance

Changes to this repository are inherited by every subsequently scaffolded app. Keep the template
free of machine-specific paths, personal configuration, secrets, and frontend build tooling.

The kit's root `README.md`, `CLAUDE.md`, and `.solo/` describe this repository and are excluded from
Composer archives. App-facing replacements live in `stubs/` and are installed without overwriting
existing destination files when Composer creates a project.

This repository lives at `artisan-build/built-for-cloud-starter`.
