<?php
// Custom Single Template for Dynamic CPT
get_header();
?>

<div class="dynamic-post-single">
    <h1><?php the_title(); ?></h1>
    <p><strong>Adresse:</strong> <?php echo get_post_meta(get_the_ID(), 'address', true); ?></p>
    <p><strong>Telefonnummer:</strong> <?php echo get_post_meta(get_the_ID(), 'phone', true); ?></p>
    <p><strong>E-Mail:</strong> <?php echo get_post_meta(get_the_ID(), 'email', true); ?></p>
    <p><strong>Öffnungszeiten:</strong> <?php echo get_post_meta(get_the_ID(), 'hours', true); ?></p>
    <p><?php the_content(); ?></p>
    <a href="<?php echo get_post_meta(get_the_ID(), 'website', true); ?>" target="_blank">Zur Webseite</a>
</div>

<?php get_footer(); ?>
