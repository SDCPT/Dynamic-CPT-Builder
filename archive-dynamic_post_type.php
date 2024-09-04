<?php
// Custom Archive Template for Dynamic CPT
get_header(); // Laden des Header-Teils des Themes
?>

<div class="dynamic-post-type-archive">
    <h1><?php post_type_archive_title(); ?></h1> <!-- Titel des Archivs -->

    <?php if ( have_posts() ) : ?>
        <div class="dynamic-post-grid">
            <?php while ( have_posts() ) : the_post(); ?>
                <div class="dynamic-post-item">
                    <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2> <!-- Titel und Link zur Einzelansicht -->
                    <p><?php echo get_post_meta(get_the_ID(), 'address', true); ?></p> <!-- Adresse als Metadatum -->
                    <p><?php echo get_post_meta(get_the_ID(), 'phone', true); ?></p> <!-- Telefonnummer als Metadatum -->
                    <a href="<?php the_permalink(); ?>">Mehr erfahren</a> <!-- Link zur Einzelansicht -->
                </div>
            <?php endwhile; ?>
        </div>
    <?php else : ?>
        <p>Keine Einträge gefunden.</p> <!-- Wenn keine Einträge vorhanden sind -->
    <?php endif; ?>
</div>

<?php get_footer(); // Laden des Footer-Teils des Themes ?>
