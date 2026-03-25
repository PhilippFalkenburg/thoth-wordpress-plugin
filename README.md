# Thoth WordPress Plugin

This directory contains the Thoth WordPress plugin source code, including bundled PHP dependencies (such as `thoth-client-php`) in `vendor/`, so no separate sibling repository is required.

## Structure

- `thoth-wordpress-plugin.php`: WordPress plugin bootstrap
- `src/Plugin.php`: central plugin initialization
- `src/Admin/SettingsPage.php`: admin settings page under **Settings > Thoth Data**, including manual sync
- `src/PostType/PublicationPostType.php`: registration of `thoth_publication`
- `src/Import/WorkImporter.php`: import service (upsert for Thoth works)
- `src/Sync/SyncManager.php`: scheduled sync (WP-Cron), cursor offset handling, and sync log
- `src/Metadata/NestedMetadataMapper.php`: normalization of nested metadata
- `src/Admin/PublicationMetaBox.php`: editable metabox with repeatable fields for nested metadata
- `composer.json`: dependency setup using the official Thoth client repository
- `bin/smoke.php`: small CLI smoke test for autoload/class availability

## Local Setup

```bash
cd /Users/phil/code/THOTH_Plugin/thoth-wordpress-plugin
composer install
composer run smoke
```

## Using the Plugin in WordPress

1. Place the `thoth-wordpress-plugin` folder in `wp-content/plugins/`.
2. Activate the **Thoth Data Connector** plugin.
3. Configure the basic settings under **Settings > Thoth Data**.
4. Optionally start a manual import via **Sync now**.

## REST Exposure

- The `thoth_publication` custom post type is available through the WordPress REST API:
  - `/wp-json/wp/v2/thoth_publication`
- Registered `_thoth_*` meta fields are publicly readable (REST read), so frontend applications can consume metadata directly.
- Write operations remain restricted to users with edit permissions in WordPress.

For `thoth_publication`, the default block editor is disabled. Manual editing is handled via metaboxes (basic fields + nested metadata).
The native top title field (`Add title`) is also disabled for this post type; title editing is handled through `Full Title` in the **Basic Metadata** section.

Under **Settings > Thoth Data**, fields and sections can be shown/hidden in the editor UI.
Important: this only affects visibility; hidden fields keep their existing data.
Required fields always remain enabled, and dependent fields are toggled only as a group (for example, `First Page` + `Last Page` as `Page Range`).

The basic field set now covers an extended Thoth work core (including work type/status, imprint, DOI, publication date, license, landing page, counts, and notes).
Field labels and inputs include English hover tooltips (`title`) to make Thoth semantics visible directly in the UI.

The **Related Works** section includes:

- `relatedWorkId` (UUID)
- `relationType` (Thoth relation type)
- `relationOrdinal` (ordering)

The **Related Works** section also provides a local lookup against existing `thoth_publication` records:

- Search by title or work ID directly in the row.
- Selection automatically writes `relatedWorkId`.
- Direct **Open related work** link opens the linked record in edit mode.
- Optional checkbox **Automatically maintain inverse relations** applies inverse relations on save (for example, `is_part_of` ↔ `has_part`).
- Duplicate protection prevents duplicate inverse relations on target records.
- After save, an info notice shows counters (`added`/`removed`) for applied inverse relations.

Additional expanded sections/fields:

- **Languages** (`languageCode`, `languageRelation`, `mainLanguage`)
- **Fundings** extended with `jurisdiction`
- **Publications** (`publicationType`, `isbn`, `width`, `height`, `depth`, `weight`)
- **Issues** (`issueId`, `issueNumber`, `issueOrdinal`)
- **Series** (`seriesId`, `seriesName`, `seriesOrdinal`)
- **Contributor Affiliations** (`contributionId`, `contributorId`, `institutionId`, `institutionName`, `position`, `affiliationOrdinal`)

## Features (Current)

- Registers the `thoth_publication` custom post type.
- Imports Thoth records via the bundled client (`thoth-client-php`) and performs upserts (new/updated/skipped).
- Stores the full Thoth payload as JSON in `_thoth_payload` and technical metadata in `_thoth_work_id`, `_thoth_doi`, `_thoth_checksum`, `_thoth_last_synced_at`.
- Runs scheduled sync via WP-Cron with admin visibility for last sync and next run.
- Supports cursor/windowed sync (`limit` + `offset`) with a sync log for previous runs.
- Generates structured, reusable fields for nested metadata (for example, `_thoth_nested_contributors`, `_thoth_nested_subjects`, `_thoth_nested_fundings`, `_thoth_nested_issues`, `_thoth_nested_series`) plus flattened indexes (`_thoth_contributor_names`, `_thoth_subject_codes`, `_thoth_funding_programs`).
- Provides a metabox with an advanced mode for toggling technical fields, visual section grouping, responsive UI, and inline validation for format errors.

## Conflict Mode (Import vs Manual Edits)

Under **Settings > Thoth Data**, the conflict strategy can be configured:

- **Protect manual edits (default):** records with manual changes are not overwritten during import.
- **Import overwrites manual edits:** import can replace local changes.

Technical flags used:

- `_thoth_manual_override`
- `_thoth_manual_updated_at`
- `_thoth_sync_state`
- `_thoth_import_payload`
- `_thoth_import_checksum`

The sync log additionally shows how many records were skipped due to manual protection.

The metabox now shows the current conflict state:

- **Synchronized**
- **Manual edits present**
- **Conflict: remote update available**

If an import snapshot exists, a button allows resetting the record to the last imported state.

The editor also provides a second action:

- **Clear override flag:** removes manual protection without immediately discarding local fields. The next import can update the record again.
- **Reset to last imported state:** discards local changes and restores the most recently imported metadata immediately.

After either action, an admin notice with the result appears at the top of the edit page (`wp-admin/post.php`).

## Conditional Rules (Work Type / Status)

The metabox now uses a central rule matrix for visibility and persistence rules:

- The UI shows/hides specific fields/sections depending on `workType`.
- Matching rules are enforced server-side during save.
- Disallowed values are dropped (for example, `publications` for `book_chapter`).
- Required fields are guarded; where possible, existing meta values are preserved.

Currently implemented:

- First/last page (`firstPage`/`lastPage`) is visible/allowed only for `book_chapter`.
- Page breakdown (`pageBreakdown`) is visible/allowed only for non-`book_chapter` work types.
- Publications (`publications`) only for `monograph`, `edited_book`, `textbook`, `journal_issue`, `book_set`.
- Issues (`issues`) only for `journal_issue`.
- Series (`series`) only for non-`book_chapter` work types.
- When `workStatus = active`, `publicationDate` is treated as required.

Extended nested-section rules:

- When `workStatus = active`, at least one publication row is expected for visible work types.
- For `workType = journal_issue`, at least one issue row is expected.
- For `workType = book_set`, at least one series row is expected.
- If a required section would be saved empty, existing meta values are reused where available.

When rules intervene during save, an informational notice appears in the editor.

## Cover Image via WordPress Media

In the **Basic Metadata** section, the cover can now be selected or uploaded directly via the WordPress media library:

- **Select / Upload Cover** opens the WP media picker (images only).
- The selected image is shown immediately as a preview.
- **Remove Cover** removes the image URL and attachment reference.
- Alternatively, `coverUrl` can still be entered manually.

Technical storage:

- `_thoth_cover_url` remains the primary URL used for Thoth payload/export.
- `_thoth_cover_attachment_id` optionally stores the local WP attachment ID.

## Next Steps (Phase 2+)

- Delta sync via WP-Cron
- Export logic back to Thoth

## Third-Party Licenses

- Bundled dependency: `thoth-pub/thoth-client-php` (Apache-2.0). License and NOTICE files are included under `vendor/` where present. When redistributing, keep those license/NOTICE files intact to preserve attribution.

Proudly vibe coded!
