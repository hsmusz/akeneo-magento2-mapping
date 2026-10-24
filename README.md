# hsmusz/akeneo-magento2-mapping

Hardening layer for the **Webkul Magento2 connector** (`webkul/magento2bundle`) in Akeneo PIM. Ships two reconciliation commands and two writer overrides that fix the mapping problems that appear when several brand PIMs export into one multi‑brand Magento.

## What it fixes

1. **Cross‑brand category mappings** — category names/codes collide across brands, so Webkul's root‑blind matching binds a PIM's categories to *another brand's* Magento ids and products land in the wrong brand. `ContextAwareCategoryWriter` resolves the correct brand root per channel; `magento2:reconcile-category-mappings` repairs existing mappings by **tree path under the brand root** (never by name).
2. **Attribute‑option mapping drift / "already exists"** — stale or corrupted option ids make the connector POST instead of PUT. `ContextAwareAttributeOptionWriter` recovers at export time: when the POST is rejected because the admin label already exists (e.g. created by another brand PIM sharing the attribute), it PUTs to that option id and stores the mapping. `magento2:reconcile-option-mappings` repairs/creates option mappings by slug against live Magento in bulk.
3. **Product‑export self‑poisoning** — vanilla product export writes junk option mappings (`externalId = code`) and leaks raw codes into attribute values. `ContextAwareProductWriter` skips unmapped option values (and logs them) instead, so it never corrupts the mapping cache.

4. **Media wiped by a `with_media: false` export** — the media steps run whatever the flag says, and with no gallery entries in the payload the reconciliation reads every tracked image as "removed in the PIM" and deletes it from Magento. `ContextAwareProductMediaWriter` skips the step entirely when `with_media` is off; profiles that have no such parameter keep exporting media.

5. **Gallery read that ships every file back** — `GET /V1/products/{sku}/media` returns each gallery file base64-encoded and fails with HTTP 400 for the whole SKU when one file is missing on the Magento disk. The writer and `magento2:reconcile-media-mappings` read the gallery from `GET /V1/products/{sku}?fields=sku,media_gallery_entries[...]` instead: same entries, no file contents.

6. **Media deleted because the PIM could not read its file** — the vanilla processor silently drops an image whose file is unreadable in the PIM file storage, and the reconciliation then deletes it from Magento as "removed in the PIM". `ContextAwareProductMediaProcessor` reports such files as `media_unreadable`; the writer keeps their Magento copy, logs a warning and counts them as `media_unreadable_kept`. With `localized_media` on, such an image gets no per-store markers until its file is readable again, so another image may take its base/small/thumbnail role meanwhile.

7. **A GET before every product PUT** — the vanilla writer fetches the product before each PUT but reads the response only to keep a configurable's existing child links. `ContextAwareProductWriter` fetches configurables only; every other type goes straight to the PUT with the same payload.

8. **The same product saved twice** — a store view with the locale, channel and currency of `allStoreView` gets an identical second PUT, which Magento answers with a full save that leaves only `url_key` copies and empty date rows in the store view. The product writer skips such a store view in the export jobs; category export, the grid quick export (`magento2_quick_export` bypasses the store filter) and any store view with a different locale, channel or currency are unaffected. Store-view rows written by earlier exports are not removed: rows equal to the global value are safe to delete, any other row now masks the global value the export keeps updating.

9. **`url_key` broken by accented letters** - libiconv turns `ó` into `'o` and Magento makes the mark a hyphen (`skórzane` -> `sk-orzane`). `ContextAwareMagento2Connector` strips accents with ICU before iconv, drops `™`, `®` and `©` (iconv would spell them out as `tm`, `r`, `c`) and cleans the key like Magento's `TranslitUrl`. Already exported products get the new key on their next export (Magento keeps a 301); categories only when created.

10. **Product export stalls before its first product** - with `metric_is_active` on, the vanilla writer picks the metric attributes to map through `attributesAxesOptions()`, which hydrates every PIM product whose family has a metric from `custom_fields` (catalog-wide, job filters ignored) only for the attribute codes. `ContextAwareProductWriter` takes the codes straight from `custom_fields`; the mapped values are the same. The metric option export gets its values from item 11.

11. **Metric option export idles for minutes before its first option** - `attributesAxesOptions()` loads every PIM product to collect the values of the metrics in `custom_fields` (460 s on 64 k products). `ContextAwareMagento2Connector` reads the same values from `raw_values` in one query, a variant inheriting from its models (3.5 s, identical values).

12. **Two PIM images bound to one Magento image** - bare-name matching folds `_1` / `_2` and `_` / `__`, so a second image adopted the first one's gallery entry and was never uploaded; a replaced PIM file adopted the old entry and the orphan pass then deleted it. `MediaFileName::match()` pairs names level by level (exact, Magento collision suffixes of any length, one or two stacked, sibling with `_N` kept, bare name), each gallery file at most once; the writer never adopts an image already in the map, and `magento2:reconcile-media-mappings` uses the same matcher. Maps corrupted earlier are repaired with `magento2:reconcile-media-mappings --sku=... --apply` followed by a media export of those SKUs.

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

## Configuration

The localized media layer - one gallery entry per locale plus the marker push to the companion
Magento module `MoveCloser_LocalizedMedia` - can be switched off per PIM. It defaults to **on**, so
an existing install keeps behaving as before without any config file.

```yaml
# config/packages/magento2_connector_override.yml
magento2_connector_override:
    localized_media:
        enabled: false
```

With `enabled: false`:

- the media processor delegates to the vanilla conversion again - one entry per attribute, with the
  native `types` (base/small/thumbnail) left on the entry, since no markers carry the roles;
- the writer collects no markers and never calls `/V1/movecloser/localized-media/markers`, so a
  Magento without the companion module stops answering 404 once per SKU;
- everything else the writer does stays: the `wk_magento2_media_mapping` table, image reuse across
  exports, and the non-destructive reconciliation that never deletes manually added Magento images.

Akeneo loads `config/packages/*.yml` first and `config/packages/<env>/**/*.yml` after, so a local
file under the environment directory overrides the shared value. The switch reaches the services as
a parameter reference, so an env-backed flag works too:

```yaml
magento2_connector_override:
    localized_media:
        enabled: '%env(bool:MAGENTO2_LOCALIZED_MEDIA)%'
```

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

- PHP 8.1 - 8.3
- `ext-intl`
- `akeneo/pim-community-dev` ^7.0
- `webkul/magento2bundle`
