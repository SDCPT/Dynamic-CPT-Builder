###### WordPress Plugins by Volkan Sah - Simple codings (Realesed 27.08.2024)
![WordPress](https://img.shields.io/badge/WordPress-Compatible-blue.svg)
![Version](https://img.shields.io/badge/version-2.3.0-orange.svg)
![PHP](https://img.shields.io/badge/php-%23777BB4.svg?&logo=php&logoColor=white)
[![Stars](https://img.shields.io/github/stars/SDCPT/Secure-Dynamic-CPT-Builder)](https://github.com/SDCPT/Secure-Dynamic-CPT-Builder)
![Forks](https://img.shields.io/github/forks/SDCPT/Secure-Dynamic-CPT-Builder)

> Slim & powerfull solution for creating multiple Custom Post Types (CPTs) –  simple, clean, and secure with real PHP power.

## About

**Secure & Dynamic CPT Builder** is a WordPress plugin that allows developers and site administrators to **dynamically create and manage custom post types** from the admin panel, with a strong focus on **security, performance, and flexibility**.

Unlike traditional CPT plugins or Business Directories , this solution prioritizes **data integrity**, **clean architecture**, and **security-first principles**, ensuring that all user-submitted content is validated and securely stored.

---

## Key Features

* **Dynamic CPT Creation**
  Create and manage any type of custom post types directly from the WordPress admin panel without writing php code.

* **Custom Form Fields with JSON**
  Define fields for each CPT using a JSON-based structure. Supports various input types: `text`, `textarea`, `email`, `tel`, and more.

* **Secure Data Handling**
  All submissions are protected with **nonce verification** and stored using **PDO prepared statements** to prevent SQL injection.

* **Dedicated Entry Management**
  Manage all form submissions in a central admin dashboard. Approve, edit, or convert entries into published posts with a single click.

* **Smart Metabox Integration**
  Automatically links additional fields to existing WordPress posts. Works even for posts created manually.

* **Clean & Slim Architecture**
  Minimal code footprint for **high performance** and **maximum compatibility** with WordPress core.

* **Safe Deletion**
  When you delete a CPT, all associated data (custom entries, linked posts) is removed properly, preventing orphaned records.

---

### Why This Plugin?

Most CPT plugins are flexible but **lack security** or **bloat your site**. This plugin is built with:
* **With ❤️‍🔥 and 🧠**
*  **Security and clen code by default**
* **Data stored in a separate table** for better structure and validation
* **Slim and optimized code**, no unnecessary overhead
* **Ideal for projects where security and performance matters**

---

### Installation

1. Upload the plugin folder to your `wp-content/plugins/` directory.
2. Activate it via **Plugins → Installed Plugins** in WordPress.
3. A new menu item **Secure CPTs** will appear in the admin panel.

---

## Usage

### 1. Create a Custom Post Type

* Go to **Secure CPTs** in the admin panel.
* Enter a **CPT Key**, **Name**, and **Slug**.
* Define fields in JSON format.

Example:

```json
{
    "title": {
        "label": "Title",
        "type": "text",
        "required": true,
        "is_title": true
    },
    "content": {
        "label": "Content",
        "type": "textarea",
        "required": false,
        "is_content": true
    },
    "email": {
        "label": "Contact Email",
        "type": "email",
        "required": true
    },
    "phone": {
        "label": "Phone Number",
        "type": "tel",
        "required": false
    }
}
```

**Note:**

* `"content"` maps to the WordPress post content.
* If you only need basic posts, use:

```json
{
    "title": {
        "label": "Title",
        "type": "text",
        "required": true,
        "is_title": true
    },
    "content": {
        "label": "Content",
        "type": "textarea",
        "required": false,
        "is_content": true
    }
}
```

---

### 2. Display the Form on the Frontend

Use the shortcode:

```
[secure_cpt_form cpt="your_cpt_key"]
```

---

### 3. Display a List of Entries

Use the shortcode:

```
[secure_cpt_list cpt="your_cpt_key"]
```

**Tip:** CPT archives and single pages are automatically handled by WordPress templates. Shortcodes are for custom page layouts.

---

## 🛠 Roadmap

* [ ] Import/Export CPT configurations
* [ ] Full REST API support
* [ ] Enable/disable front-end form per CPT
* [ ] Enable/disable entry listing per CPT
* [ ] CSS simplification (reduce custom UI, inherit theme styles)
* [ ] Separate CSS files for `admin.css` and `public.css`
* [ ] Default template parts for single and archive views
* [ ] Fix post publish from Manage Entries (draft/trash works fine)

**Done so far:**
* [x] More field types
* [x] Validation & sanitization improvements
* [x] Secure content flag
* [x] Core cleanup for better performance

---

## 📄 License

Licensed under **Apache 2.0** (see `LICENSE` file for details).
