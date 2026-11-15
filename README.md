# Dynamic CPT Builder
###### Secure dynamic customs post type

Create custom post types, taxonomies and fields in the WordPress backend, without code.

- Definitions live in **one** option, content is stored as normal posts with post meta (no custom tables, no PDO, only the WordPress API).
- On first activation an example CPT **AI Bots** (with a "Vendor" taxonomy) is created.
- Field types: `text`, `textarea`, `url`, `email`, `number`, `date`, `select`, `checkbox`. Empty fields are not shown on the website.

## Usage

1. Activate the plugin and open **CPT Builder** in the admin menu.
2. Create a CPT. Fields, one per line: `key|Label|type|options|flag`
   Example: `license|License|select|MIT,Apache,AGPL`
3. Taxonomies, one per line: `key|Label|kind` (kind: `category` = hierarchical, default; `tags` = flat)
4. Entries get their own menu item; fields appear in the "Additional fields" meta box.
5. Frontend: `[dcb_list cpt="ki_bot" per_page="10"]`. Fields are appended automatically on single pages. Theme templates such as `archive-ki_bot.php` and `single-ki_bot.php` work like for any CPT.

Meta keys are named `dcb_<fieldkey>` and are exposed in the REST API.

## Features per CPT (checkboxes)

Each CPT only gets what it needs: title, editor, featured image, excerpt, author, comments, trackbacks, revisions, page attributes, post formats; the built-in categories and tags; and behaviour switches (public, archive page, hierarchical, block editor/REST). "Hierarchical" automatically switches on page attributes (parent selector). Your own taxonomies are `key|Label|kind` where kind is `category` (hierarchical, default) or `tags` (flat).

## Addresses and archive pages

All CPTs live under a **URL base**, `p` by default: `/p/bots/` and `/p/bots/llama-3/`. Change or empty it on the CPT Builder page. After a plugin update or a change, permalinks are refreshed automatically.

Instead of a shortcode, every CPT can have an **archive page**: choose an existing WordPress page or let the plugin create one. The entries are shown below the page's own text (write an intro, set a featured image, add SEO data as usual) and the page answers at `/p/bots/`, paginated at `/p/bots/2/`. This works with classic and block themes. Without an archive page the theme's own archive is used. Any published page can serve, but one page serves only one CPT.

**Layouts** (Design page default, per CPT, or per shortcode): `list` (plain rows), `cards` (boxes), `mosaic` (image tiles of different sizes; uses the featured image).

## Design and skins

**CPT Builder → Design** sets colours, font, corner radius and spacing of the lists and field boxes, with a live preview. Everything works through CSS variables (`--dcb-accent`, `--dcb-text`, `--dcb-bg`, `--dcb-border`, `--dcb-radius`, `--dcb-gap`, `--dcb-font`, `--dcb-muted`).

A **skin** is one small CSS file that only sets those variables, see `skins/dark.css`. Put your own into the folder `dcb-skins/` of your (child) theme; it shows up in the Design page automatically. The first line `/* Skin: My name */` is the display name. `[dcb_list cpt="ki_bot" layout="cards"]` overrides the layout per shortcode.

## Multilingual (Polylang, WPML prepared)

- **Language-neutral vs. translated fields:** the last column of a field line decides.
  `neutral` = same value in every language and kept in sync (e.g. SHA256, parameter count, links);
  `translate` = translated per language. Default: only `textarea` is translated.
  Example: `sha256|SHA256|text|neutral`, `notes|Notes|textarea|translate`, `license|License|select|MIT,AGPL|neutral`
- With Polylang active, all CPTs and taxonomies become translatable automatically. Neutral fields are copied and synced between translations; translatable fields are copied once when a translation is created.
- CPT names, field labels, select options and taxonomy labels are registered as strings (Polylang: *Languages → Translations*, group "Dynamic CPT Builder").
- Plugin UI: English source, German translation included (`languages/`). Other languages: translate `languages/dcb.pot`.

## Public submissions (form for visitors)

Switch on **Public submissions** for a CPT, then put `[dcb_submit]` on any page (or `[dcb_submit cpt="ki_bot,tool"]` to limit it). With several CPTs the visitor first chooses the category and the form shows exactly the fields of that CPT.

Security, deny by default:

- Entries are always saved as **pending**, with no author and comments closed. Nothing goes live without review.
- Text is plain text only (no HTML), URL fields accept only `http`/`https`, select fields only the defined options.
- Spam protection: hidden honeypot field, signed time token (too fast / expired is refused), 5 submissions per hour per connection, at most 200 pending entries (filters `dcb_submit_rate_limit`, `dcb_submit_max_pending`).
- One optional image per entry: only real JPG, PNG or WebP, max. 2 MB, max. 6000 px. The file content is checked (not the name or browser type), names like `.php`, `.svg`, `.html`, `.js`, `.zip`, `.exe` are refused, files containing code markers are refused, the image is **re-encoded** and stored under a random name. If re-encoding is impossible (no GD/Imagick), the upload is refused.
- Known limit: a stored image has a public URL even while the entry is still pending.

Privacy: the submitter's email and the time of consent are stored in private meta and shown only to editors. There is no exporter/eraser for the WordPress privacy tools yet. Add a privacy page; the form links to it automatically.

## Removing a definition

"Remove" deletes only the definition. Posts stay in the database; if you later create the CPT again with the same key, they are back.

## License

AGPL-3.0-or-later, see `LICENSE`.

---
 soon in türkisch and german
