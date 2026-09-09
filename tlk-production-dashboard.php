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

/**
 * Connects to the Google Apps Script Web App, follows security redirects, and caches data.
 */
function get_schedule_data() {
    $web_app_url = 'https://script.google.com/macros/s/AKfycbyzOP1F1WwajHXYC2t3pv21jCs6hIokKcvHcdZzi0wGuffbLnvwY-PqC6hyhm6clZmptw/exec';
    
    $cache_key = 'clean_schedule_cache_data';
    $data      = get_transient($cache_key);
    
    // If cache is empty or expired, run a live background fetch
    if (false === $data) {
        // Apps Script utilizes structural HTTP redirects, timeout and redirection configs are mandatory
        $response = wp_remote_get($web_app_url, array(
            'timeout'     => 15,
            'redirection' => 5
        ));
        
        // Handle network or script hosting errors gracefully
        if (is_wp_error($response)) {
            error_log('Bespoke Sync Failure: ' . $response->get_error_message());
            return array();
        }
        
        $json_string = wp_remote_retrieve_body($response);
        $data        = json_decode($json_string, true);
        
        // Ensure parsing succeeded and we have a valid key-value array structure
        if (!is_array($data) || isset($data['error'])) {
            if (isset($data['error'])) {
                error_log('Google App Script Error: ' . $data['error']);
            }
            return array();
        }
        
        // Cache the processed data array locally for 15 minutes to preserve site load speeds
        set_transient($cache_key, $data, 15 * MINUTE_IN_SECONDS);
    }
    
    return $data;
}