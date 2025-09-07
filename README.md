# Secure & Dynamic CPT Builder

> [!IMPORTANT]
> dev status! build it new and better! Works fine , till yet 😄

**Secure & Dynamic CPT Builder** is a WordPress plugin that allows developers and administrators to create custom post types dynamically from the admin panel, with a strong focus on security and data integrity.

This plugin provides a robust and customizable framework for handling user-submitted data, ensuring it is sanitized, validated, and securely stored in a dedicated database table.

---

## Features

* **Dynamic CPT Creation:** Create and manage new custom post types directly from a user-friendly admin page without writing a single line of code.
* **Custom Form Fields:** Define form fields for each CPT using a flexible JSON structure, supporting various input types like `text`, `textarea`, `email`, and more.
* **Secure Data Handling:** All form submissions are protected with nonce verification and stored in a custom database table using PDO prepared statements to prevent SQL injection.
* **Dedicated Entry Management:** A new admin section provides a central hub to manage all form submissions. Admins can review, approve, and convert entries to a published WordPress post with a single click.
* **Smart Metabox Integration:** An intelligent backend metabox automatically links existing WordPress posts to the custom database, ensuring data consistency and allowing you to save additional fields for any post, even those created manually.
* **Robust Deletion:** The CPT deletion process now safely removes the custom post type and all associated entries and WordPress posts, preventing data orphans.

---

## Why this plugin is different

While many CPT plugins offer flexibility, they often fall short on security. **Secure & Dynamic CPT Builder** is built on the principle of "security by default." It separates submitted data from the main WordPress post table and uses strict validation, making it an ideal choice for projects where **data integrity and user security are critical**.

---

## Installation

1.  Upload the plugin folder to your `wp-content/plugins/` directory.
2.  Activate the plugin through the **Plugins → Installed Plugins** menu in WordPress.
3.  A new admin menu item named **"Secure CPTs"** will appear.

---

## Usage

### Create a CPT

* Go to the **"Secure CPTs"** admin page.
* Enter a **CPT Key**, **Name**, and **URL Slug**.
* Define your form fields in the **Fields JSON** editor.

Here is a basic JSON example for your fields:

```json
{
    "title": {
        "label": "Listing Title",
        "type": "text",
        "required": true
    },
    "content": {
        "label": "Description",
        "type": "textarea"
    },
    "email": {
        "label": "Contact Email",
        "type": "email",
        "required": true
    },
    "phone": {
        "label": "Phone Number",
        "type": "tel"
    }
}
````

### Display a Form on the Frontend

Place the following shortcode on any page or post:

`[secure_cpt_form cpt="your_cpt_key"]`

### Display a List of Entries

Place the following shortcode on any page or post:

`[secure_cpt_list cpt="your_cpt_key"]`

**Note:** All registered CPTs are also automatically displayed on their standard WordPress archive pages (e.g., `/your_cpt_slug/`) and single post templates. This shortcode is a bonus feature for custom use cases where you need to display a list on a specific page. For modern themes like Twenty-Twenty, the standard archive page often offers better styling control.

-----

## Roadmap

  * Import/Export CPT configurations.
  * Enhanced REST API support.
  * More field types (e.g., file uploads, dates, number inputs).

-----

## License

Privat! Non-Commercial Use allowed!
