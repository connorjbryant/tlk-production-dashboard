<?php
/** Standalone destination chooser for selected TLK users. */
if (!defined('ABSPATH')) { exit; }
get_header();
?>
<main class="tlk-destination-choice">
    <div class="tlk-destination-choice__card">
        <h1>Where would you like to go?</h1>
        <p>Choose the page you would like to open.</p>
        <div class="tlk-destination-choice__actions">
            <a class="tlk-destination-choice__button" href="<?php echo esc_url(home_url('/standard-price-list/')); ?>">Standard Price List</a>
            <a class="tlk-destination-choice__button" href="<?php echo esc_url(home_url('/tlk-production-dashboard/')); ?>">Production Dashboard</a>
        </div>
    </div>
</main>
<?php get_footer(); ?>