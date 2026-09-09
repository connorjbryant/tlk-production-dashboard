<?php
/**
 * Plugin Name: TLK Production Dashboard
 * Description: Departmental production data statistics
 * Version: 1.0.0
 * Author: Connor Bryant
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) exit;

add_filter('theme_page_templates', 'tlk_add_page_template_to_dropdown');
add_filter('template_include', 'tlk_change_page_template', 99);

/**
 * Add page templates
 * 
 * @param array $templates The list of page templates
 * 
 * @return array $templates The modified list of page templates
 * 
 */
function tlk_add_page_template_to_dropdown($templates){
    $templates[plugin_dir_path(__FILE__) . 'templates/page-template.php'] = __('Page Template From TLK Department Dashboard', 'text-domain');

    return $templates;
}

function tlk_change_page_template($template){
    if (is_page()){
        $meta = get_post_meta(get_the_ID());

        if (!empty($meta['__wp_page_template'][0]) && $meta['__wp_page_template'][0] != $template){
            $template = $meta['_wp_page_template'][0];
        }
    }

    return $template;
}