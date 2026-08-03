# hsmusz/akeneo-magento2-mapping

Hardening layer for the **Webkul Magento2 connector** (`webkul/magento2bundle`) in Akeneo PIM. Ships two reconciliation commands and two writer overrides that fix the mapping problems that appear when several brand PIMs export into one multi‑brand Magento.

## What it fixes

1. **Cross‑brand category mappings** — category names/codes collide across brands, so Webkul's root‑blind matching binds a PIM's categories to *another brand's* Magento ids and products land in the wrong brand. `ContextAwareCategoryWriter` resolves the correct brand root per channel; `magento2:reconcile-category-mappings` repairs existing mappings by **tree path under the brand root** (never by name).
2. **Attribute‑option mapping drift / "already exists"** — stale or corrupted option ids make the connector POST instead of PUT. `magento2:reconcile-option-mappings` repairs/creates option mappings by slug against live Magento.
3. **Product‑export self‑poisoning** — vanilla product export writes junk option mappings (`externalId = code`) and leaks raw codes into attribute values. `ContextAwareProductWriter` skips unmapped option values (and logs them) instead, so it never corrupts the mapping cache.

## Install

In each PIM's `composer.json`, add the VCS repository (alongside the existing Webkul repo):

```json
"repositories": [
    { "type": "composer", "url": "https://akeneorepo.webkul.com/" },
    { "type": "vcs", "url": "git@github.com:hsmusz/akeneo-magento2-mapping.git" }
]
```

Then:

```bash
composer require hsmusz/akeneo-magento2-mapping:^1.0
```

Register the bundle in `config/bundles.php` (Akeneo has no Flex auto‑registration) — **after** the Webkul bundle:

```php
Webkul\Magento2Bundle\Magento2Bundle::class => ['all' => true],
MoveCloser\Magento2ConnectorOverride\Magento2ConnectorOverrideBundle::class => ['all' => true],
```

Clear the cache:

```bash
php bin/console cache:clear
```

That is all — the bundle registers the commands itself and swaps the Webkul writers through a compiler pass (`OverrideWebkulWritersPass`, which keeps Webkul's argument wiring via `setClass`). **No `config/services.yml` changes are needed.**

### Migrating from the in‑app copy

If these classes were previously copied into the app under `src/MoveCloser/Magento2ConnectorOverride/`, remove that copy and the matching `config/services/services.yml` entries (the command services and the `webkul_magento2.writer.*` overrides) to avoid duplicate class/service definitions. The package now provides them.

## Usage

Run per PIM (each PIM = one brand). Dry‑run first; add `--apply` to persist. Both commands write only the PIM mapping table — they never write to Magento.

```bash
# options
php bin/console magento2:reconcile-option-mappings
php bin/console magento2:reconcile-option-mappings --apply

# categories (path/brand-aware; auto-detects the brand root)
php bin/console magento2:reconcile-category-mappings
php bin/console magento2:reconcile-category-mappings --apply
```

Options:

- `--credential-id=<id>` — target a specific `wk_magento2_credentials_mapping` row (defaults to the active default credential).
- `--locale=<code>` *(category command)* — Akeneo locale whose labels match the Magento admin category names. **Required where the credential default locale differs from the Magento naming locale** (e.g. a credential with `uk_UA` default but Polish Magento names → use `--locale pl_PL`).
- `--root-id=<id>` *(category command)* — force the Magento brand root if auto‑detection is ambiguous.
- `--api-base=<url>` — override the Magento base URL for API calls.

TLS: API calls honor the `CURL_CA_BUNDLE` environment variable, so a custom CA (e.g. mkcert) works without disabling verification.

## Recommended order (full export)

Repair mappings, then export in dependency order:

```
reconcile-option-mappings --apply
reconcile-category-mappings --apply        # PH: add --locale pl_PL
# then: categories → attributes/options → families → products  (or the connector's all-in-one job)
```

## Requirements

- PHP 8.1
- `akeneo/pim-community-dev` ^7.0
- `webkul/magento2bundle`
