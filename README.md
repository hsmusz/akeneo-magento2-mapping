# hsmusz/akeneo-magento2-mapping

Hardening layer for the **Webkul Magento2 connector** (`webkul/magento2bundle`) in Akeneo PIM. Ships two reconciliation commands and a set of writer/processor overrides that fix the mapping and media problems that appear when several brand PIMs export into one multi‑brand Magento.

## What it fixes

1. **Cross‑brand category mappings** — category names/codes collide across brands, so Webkul's root‑blind matching binds a PIM's categories to *another brand's* Magento ids and products land in the wrong brand. `ContextAwareCategoryWriter` resolves the correct brand root per channel; `magento2:reconcile-category-mappings` repairs existing mappings by **tree path under the brand root** (never by name).
2. **Attribute‑option mapping drift / "already exists"** — stale or corrupted option ids make the connector POST instead of PUT. `magento2:reconcile-option-mappings` repairs/creates option mappings by slug against live Magento.
3. **Product‑export self‑poisoning** — vanilla product export writes junk option mappings (`externalId = code`) and leaks raw codes into attribute values. `ContextAwareProductWriter` skips unmapped option values (and logs them) instead, so it never corrupts the mapping cache.
4. **Per‑locale product images** — vanilla media export reads only the first locale of a localizable image attribute (`$value[0]['data']`) and uploads it to the `all` scope only, so a product cannot show a different image per language. `ContextAwareProductMediaProcessor` emits one gallery entry **per locale** (tagged with `meta.locale`), and `ContextAwareProductMediaWriter` toggles the per‑store‑view `disabled` flag so each store view exposes only its language's image. See [Per‑locale images](#per-locale-images-headless--graphql) for the required frontend contract.

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

## Per-locale images (headless / GraphQL)

Enables a **different image per language** on a variant, driven entirely by the export (no manual per‑product work).

**How it works.** In this Magento the `image`/`small_image`/`thumbnail`/`media_gallery` attributes are `is_global = 1` (GLOBAL), so the base‑image *role* cannot differ per store view. Differentiation therefore lives in the media gallery via the per‑store‑view `disabled` flag, which **is** store‑scoped. On every export the overrides:

1. upload each locale's file as its own gallery entry (`all` scope), and
2. set `disabled` per store view — enabled only where the store‑view locale matches the entry's locale.

**Frontend contract (important).** The headless front must read the variant image from GraphQL **`media_gallery`**, filtering `disabled == false`, with the `Store` header set to the language's store view. Do **not** read `image`/`small_image`/`thumbnail` — those roles are global and return the same file for every language.

Example (per store view the enabled entry differs):

```graphql
{ products(filter: { sku: { eq: "MODEL_SKU" } }) {
    items { ... on ConfigurableProduct { variants { product {
      sku
      media_gallery { url disabled }   # pick the entry with disabled == false
    } } } }
} }
```

**Scope of the change.** Only image attributes that are `is_localizable` **and** carry different files across locales are expanded per locale. Non‑localizable images, localizable images with the same file in every locale, and videos fall through to the vanilla behaviour (single entry, `all` scope, `disabled` untouched) — nothing else in the catalog changes. The global image role is never modified.

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
