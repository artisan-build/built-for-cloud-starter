# {{FILL: app name}}

> **Built for Cloud app scaffold.** Replace every `{{FILL: ...}}` marker with product-specific
> information, then remove this blockquote.

{{FILL: One-sentence description of the app and the problem it solves.}}

## Who It Is For

{{FILL: Describe the primary users and the outcome they need.}}

## What It Does

{{FILL: List the app's current capabilities. Do not describe planned features as shipped.}}

## Built For Cloud

{{FILL: Explain how this app participates in the Built for Cloud ecosystem and link to its product
surface when one exists.}}

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

## Local Development

```bash
composer setup
composer dev
```

This app is nodeless by design. Do not add Node, npm, Vite, or a frontend build step. Static assets
are committed under `public/build`.

## Quality Gate

```bash
composer ready
```

{{FILL: Document any additional focused or integration test commands required by this app.}}

## Deployment

Artisan Build agents handling Laravel Cloud work must follow
`brain/skills/laravel-cloud-deploy/SKILL.md`. Start with read-only discovery. Credentials come from
the authenticated Laravel Cloud CLI; discover application and environment identifiers rather than
guessing or committing them. Keep `.cloud/config.json` uncommitted, and verify the application and
its attached resources after every deployment.

{{FILL: Document the app-specific Laravel Cloud deployment workflow and environments without
committing secrets, identifiers, or machine-specific configuration.}}

## Repository

{{FILL: owner/repo URL}}
