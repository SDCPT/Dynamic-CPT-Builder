<?php
// Custom Archive Template for Dynamic CPT
get_header();

global $wpdb;
$table_name = $wpdb->prefix . 'dynamic_cpt_entries';

// Abrufen aller Einträge aus der Datenbank
$entries = $wpdb->get_results( "SELECT * FROM $table_name" );

?>

<div class="dynamic-post-type-archive">
    <h1>Einträge Übersicht</h1>

    <?php if ($entries) : ?>
        <div class="dynamic-post-grid">
            <?php foreach ($entries as $entry) : ?>
                <div class="dynamic-post-item">
                    <h2><?php echo esc_html($entry->title); ?></h2>
                    <p><?php echo esc_html($entry->address); ?></p>
                    <p><?php echo esc_html($entry->phone); ?></p>
                    <a href="<?php echo esc_url(get_permalink($entry->id)); ?>">Mehr erfahren</a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else : ?>
        <p>Keine Einträge gefunden.</p>
    <?php endif; ?>
</div>

<?php
get_footer();
