# Dynamic-CPT



    Fügen Sie zunächst ein neues Post-Format zu Ihrem Custom Post Type hinzu:

'supports' => array('title', 'editor', 'thumbnail', 'post-formats'),
'post-formats' => array('standard', 'custom-code'),

    Erstellen Sie eine Metabox für das "Custom Code" Format:

function add_custom_meta_boxes() {
    global $post;
    
    $post_format = get_post_format($post->ID);
    
    if ($post_format === 'custom-code') {
        add_meta_box('custom_code_meta_box', 'Custom Code', 'custom_code_meta_box_callback', 'dynamic_post_type', 'normal', 'high');
    }
}
add_action('add_meta_boxes', 'add_custom_meta_boxes');

function custom_code_meta_box_callback($post) {
    wp_nonce_field('custom_code_meta_box', 'custom_code_meta_box_nonce');
    $custom_code = get_post_meta($post->ID, '_custom_code', true);
    ?>
    <p>
        <label for="custom_code">Custom Code:</label>
        <textarea id="custom_code" name="custom_code" rows="10" cols="50"><?php echo esc_textarea($custom_code); ?></textarea>
    </p>
    <?php
}

    Speichern Sie den benutzerdefinierten Code:

function save_custom_code_meta($post_id) {
    if (!isset($_POST['custom_code_meta_box_nonce']) || !wp_verify_nonce($_POST['custom_code_meta_box_nonce'], 'custom_code_meta_box')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (isset($_POST['custom_code'])) {
        update_post_meta($post_id, '_custom_code', wp_kses_post($_POST['custom_code']));
    }
}
add_action('save_post', 'save_custom_code_meta');

    Erstellen Sie eine benutzerdefinierte Template-Datei für das "Custom Code" Format. Nennen Sie diese Datei single-dynamic_post_type-custom-code.php und platzieren Sie sie in Ihrem Theme-Verzeichnis:

<?php
/*
Template Name: Custom Code Template
*/

$post_id = get_the_ID();
$custom_code = get_post_meta($post_id, '_custom_code', true);

if (!empty($custom_code)) {
    // Führen Sie den benutzerdefinierten Code aus
    eval('?>' . $custom_code);
} else {
    // Fallback, falls kein benutzerdefinierter Code vorhanden ist
    get_header();
    ?>
    <div id="primary" class="content-area">
        <main id="main" class="site-main">
            <?php
            while (have_posts()) :
                the_post();
                the_content();
            endwhile;
            ?>
        </main>
    </div>
    <?php
    get_footer();
}

WICHTIGER HINWEIS: Die Verwendung von eval() kann sehr gefährlich sein, wenn nicht sorgfältig gehandhabt. Stellen Sie sicher, dass nur vertrauenswürdige Benutzer Zugriff auf diese Funktion haben, und implementieren Sie strenge Sicherheitsmaßnahmen.

    Fügen Sie eine Funktion hinzu, um das
