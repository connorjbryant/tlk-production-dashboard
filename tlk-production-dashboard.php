<?php
/**
 * Plugin Name: TLK Production Dashboard
 * Description: Production dashboard for TLK Precision
 * Version: 1.0.2
 * Author: Connor Bryant
 * License: GPL-2.0+
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

function tlk_dash_enqueue_assets(){
    // Enqueue CSS file
    wp_enqueue_style(
        'tlk_dash_styles',
        plugins_url('css/tlk-dash.css', __FILE__),
        array(),
        '1.0.0',
        'all'
    );

    // Enqueue JavaScript file
    wp_enqueue_script(
        'tlk_dash_script',
        plugins_url('js/tlk-dash.js', __FILE__),
        array('jquery'),
        '1.0.0',
        true
    );
}
// Hook the function into wp_enqueue_scripts for the site front-end
add_action('wp_enqueue_scripts', 'tlk_dash_enqueue_assets');

/**
 * Add page template
 */
add_filter('theme_page_templates', 'tlk_add_page_template_to_dropdown');
function tlk_add_page_template_to_dropdown($templates){
    $templates['templates/page-template.php'] = __('TLK Department Dashboard', 'text-domain');

    return $templates;
}

/**
 * Custom CSS class for targeted removal of certain theme defaults
 */
add_filter('body_class', 'custom_template_body_class');
function custom_template_body_class($classes){
    // Check if current page has the custom template file
    if (is_page_template('templates/page-template.php')){
        $classes[] = 'tlk-prod-dash';
    }

    return $classes;
}

/**
 * Load page template if selected
 */
add_filter('template_include', 'tlk_change_page_template', 99);
function tlk_change_page_template($template){
    if (is_page()){
        $selected_template = get_page_template_slug(get_the_ID());

        if ($selected_template === 'templates/page-template.php'){
            $plugin_template = plugin_dir_path(__FILE__) . 'templates/page-template.php';

            if (file_exists($plugin_template)){
                return $plugin_template;
            }
        }
    }

    return $template;
}