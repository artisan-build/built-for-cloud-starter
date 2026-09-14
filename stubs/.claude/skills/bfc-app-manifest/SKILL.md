---
name: bfc-app-manifest
description: Interview for, write, and validate the Built for Cloud app manifest and UI overlay in config/built-for-cloud.php.
---

# Built for Cloud App Manifest

Use this skill when creating or correcting an app's Built for Cloud manifest.

1. Run `php .claude/skills/bfc-app-manifest/scripts/manifest.php --check` to inspect the current shape.
2. Ask the user for every manifest field: app name, slug, description, icon path, and product URL. Do not infer missing answers. The icon must be a root-relative `.svg` path under `public/`, such as `/images/acme.svg`.
3. Confirm the five answers, then make the requested write with all fields:

```bash
php .claude/skills/bfc-app-manifest/scripts/manifest.php --write \
  --name="Acme" --slug="acme" --description="..." \
  --icon="/images/acme.svg" --product-url="https://example.com"
```

4. Run the script again with `--check --json`. Treat any non-zero exit as unresolved.

The writer owns only `config/built-for-cloud.php`. It always writes exactly the frozen `manifest` and `ui` overlay: every UI affordance is `false` and `credential_purposes` is empty. Do not add Cloud IDs, environment/resource declarations, proxy settings, enforcement settings, or non-empty credential purposes.
