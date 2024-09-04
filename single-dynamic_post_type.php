<?php
// Custom Single Template for Dynamic CPT
get_header(); // Laden des Header-Teils des Themes
?>

<div class="dynamic-post-single">
    <h1><?php the_title(); ?></h1> <!-- Titel des Eintrags -->

    <p><strong>Adresse:</strong> <?php echo get_post_meta(get_the_ID(), 'address', true); ?></p> <!-- Adresse -->
    <p><strong>Telefonnummer:</strong> <?php echo get_post_meta(get_the_ID(), 'phone', true); ?></p> <!-- Telefonnummer -->
    <p><strong>E-Mail:</strong> <?php echo get_post_meta(get_the_ID(), 'email', true); ?></p> <!-- E-Mail-Adresse -->
    <p><strong>Öffnungszeiten:</strong> <?php echo get_post_meta(get_the_ID(), 'hours', true); ?></p> <!-- Öffnungszeiten -->
    
    <p><?php the_content(); ?></p> <!-- Haupteingabe-Inhalt -->

    <a href="<?php echo get_post_meta(get_the_ID(), 'website', true); ?>" target="_blank">Zur Webseite</a> <!-- Link zur Webseite -->
</div>

<?php get_footer(); // Laden des Footer-Teils des Themes ?>

