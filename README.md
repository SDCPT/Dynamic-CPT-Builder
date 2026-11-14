# Dynamic CPT Builder

Create custom post types, taxonomies and fields in the WordPress backend, without code.

- Definitions live in **one** option, content is stored as normal posts with post meta (no custom tables, no PDO, only the WordPress API).
- On first activation an example CPT **AI Bots** (with a "Vendor" taxonomy) is created.
- Field types: `text`, `textarea`, `url`, `email`, `number`, `date`, `select`, `checkbox`.

## Usage

1. Activate the plugin and open **CPT Builder** in the admin menu.
2. Create a CPT. Fields, one per line: `key|Label|type|options|flag`
   Example: `license|License|select|MIT,Apache,AGPL`
3. Taxonomies, one per line: `key|Label`
4. Entries get their own menu item; fields appear in the "Additional fields" meta box.
5. Frontend: `[dcb_list cpt="ki_bot" per_page="10"]`. Fields are appended automatically on single pages. Theme templates such as `archive-ki_bot.php` and `single-ki_bot.php` work like for any CPT.

Meta keys are named `dcb_<fieldkey>` and are exposed in the REST API.

## Multilingual (Polylang, WPML prepared)

- **Language-neutral vs. translated fields:** the last column of a field line decides.
  `neutral` = same value in every language and kept in sync (e.g. SHA256, parameter count, links);
  `translate` = translated per language. Default: only `textarea` is translated.
  Example: `sha256|SHA256|text|neutral`, `notes|Notes|textarea|translate`, `license|License|select|MIT,AGPL|neutral`
- With Polylang active, all CPTs and taxonomies become translatable automatically. Neutral fields are copied and synced between translations; translatable fields are copied once when a translation is created.
- CPT names, field labels, select options and taxonomy labels are registered as strings (Polylang: *Languages → Translations*, group "Dynamic CPT Builder").
- Plugin UI: English source, German translation included (`languages/`). Other languages: translate `languages/dcb.pot`.

## Removing a definition

"Remove" deletes only the definition. Posts stay in the database; if you later create the CPT again with the same key, they are back.

## License

AGPL-3.0-or-later, see `LICENSE`.


