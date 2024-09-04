<?php
// Custom Archive Template for Dynamic CPT
get_header();
?>

<div class="dynamic-post-type-archive">
    <h1><?php post_type_archive_title(); ?></h1>
    <?php if ( have_posts() ) : ?>
        <div class="dynamic-post-grid">
            <?php while ( have_posts() ) : the_post(); ?>
                <div class="dynamic-post-item">
                    <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                    <p><?php echo get_post_meta(get_the_ID(), 'address', true); ?></p>
                    <p><?php echo get_post_meta(get_the_ID(), 'phone', true); ?></p>
                    <a href="<?php the_permalink(); ?>">Mehr erfahren</a>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else : ?>
        <p>Keine Einträge gefunden.</p>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
