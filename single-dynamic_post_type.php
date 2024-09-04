<?php
// Custom Single Template for Dynamic CPT
get_header();

global $wpdb;
$table_name = $wpdb->prefix . 'dynamic_cpt_entries';
$post_id = get_the_ID();

// Abrufen des aktuellen Eintrags aus der Datenbank
$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $post_id ) );

if ($entry) :
?>

<div class="dynamic-post-single">
    <h1><?php echo esc_html($entry->title); ?></h1>
    <p><strong>Adresse:</strong> <?php echo esc_html($entry->address); ?></p>
    <p><strong>Telefonnummer:</strong> <?php echo esc_html($entry->phone); ?></p>
    <p><strong>E-Mail:</strong> <?php echo esc_html($entry->email); ?></p>
    <p><strong>Öffnungszeiten:</strong> <?php echo esc_html($entry->hours); ?></p>
    <p><?php echo esc_html($entry->description); ?></p>
    <a href="<?php echo esc_url($entry->website); ?>" target="_blank">Zur Webseite</a>
</div>

<?php else : ?>
    <p>Eintrag nicht gefunden.</p>
<?php endif; ?>

<?php
get_footer();


