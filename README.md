# Secure Custom Post Types

**Secure CPT** is a WordPress plugin that allows you to create **custom post types dynamically** with fully customizable form fields, while implementing strong security measures.

## Features

* Dynamic CPT creation without editing `functions.php`
* Custom form fields via JSON (text, textarea, email, select, checkbox)
* Frontend shortcodes for forms and entry lists
* AJAX-powered form submissions
* Data sanitization and validation for all inputs
* CSRF protection using WordPress nonces
* PDO prepared statements for SQL security
* XSS protection through output escaping
* Admin panel for managing CPTs
* Developer option to reset plugin tables

## Why this plugin is different

Most CPT plugins only focus on flexibility, not security.
**Secure CPT** uses strict security practices:

* Nonce verification for all actions
* Sanitization and validation of every input
* Prepared SQL statements (PDO)
* Role-based access for CPT management

Ideal for projects where **data integrity and security matter**.

## Installation

1. Upload the plugin to `wp-content/plugins/`
2. Activate via **Plugins → Installed Plugins**
3. Go to **Secure CPT** in the WordPress admin menu

## Usage

### Create a CPT

* In the admin panel, enter:

  * **CPT Key** (unique, e.g., `secure_entry`)
  * **CPT Label** (e.g., `Secure Entry`)
  * **Fields JSON** (example):

```json
{
    "title": {"type": "text", "label": "Title", "required": true},
    "content": {"type": "textarea", "label": "Content"},
    "email": {"type": "email", "label": "Contact Email", "required": true}
}
```

### Display a form on the frontend

```php
[secure_cpt_form cpt="secure_entry"]
```

### Display entries

```php
[secure_cpt_list cpt="secure_entry"]
```

## Security Notes

* Use HTTPS for all forms
* Validate your JSON configuration
* Do not expose sensitive data via shortcodes

## Roadmap

* Add default JSON templates
* Improve CPT delete flow
* Import/Export CPT configurations
* REST API support

## License
Privat, only for non-commercial use!

