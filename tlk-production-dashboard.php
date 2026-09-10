<?php
/**
 * Plugin Name: TLK Production Dashboard
 * Description: Production dashboard for TLK Precision
 * Version: 1.0.4
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
 * Connects to the Google Apps Script Web App
 */
function get_schedule_data() {
    $web_app_url = 'https://script.google.com/macros/s/AKfycbyECN9HB6_V-5aIU3yVYXuVQeghscd4NJejll8vLVES2GUaHd4mfvVrH7AVoe7V7wTCDQ/exec';

    delete_transient('clean_schedule_cache_data');

    $response = wp_remote_get($web_app_url, array(
        'timeout'     => 15,
        'redirection' => 10,
        'headers'     => array(
            'Accept' => 'application/json',
        ),
    ));

    if (is_wp_error($response)) {
        error_log(
            'Schedule Sync WP Error: ' .
            $response->get_error_message()
        );

        return array();
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $content_type = wp_remote_retrieve_header($response, 'content-type');
    $json_string = wp_remote_retrieve_body($response);

    error_log('Schedule HTTP status: ' . $status_code);
    error_log('Schedule Content-Type: ' . $content_type);
    error_log('Schedule raw response: ' . substr($json_string, 0, 3000));

    $data = json_decode($json_string, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log(
            'Schedule JSON decode error: ' .
            json_last_error_msg()
        );

        return array();
    }

    if (!is_array($data)) {
        error_log('Schedule response was not an array.');
        return array();
    }

    if (isset($data['error'])) {
        error_log(
            'Google Apps Script Error: ' .
            $data['error']
        );

        return array();
    }

    return $data;
}

/**
 * Create schedule table
 */
function tlk_create_schedule_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'tlk_schedule';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        po_number VARCHAR(100) DEFAULT '',
        order_date VARCHAR(50) DEFAULT '',
        customer VARCHAR(255) DEFAULT '',
        due_date VARCHAR(50) DEFAULT '',
        part_number VARCHAR(255) DEFAULT '',
        qty VARCHAR(50) DEFAULT '',
        open_qty VARCHAR(50) DEFAULT '',
        status VARCHAR(100) DEFAULT '',
        notes TEXT,
        synced_at DATETIME NOT NULL,
        PRIMARY KEY (id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta($sql);
}
register_activation_hook(__FILE__, 'tlk_create_schedule_table');

/**
 * Create production table
 */
function tlk_create_production_table(){
    global $wpdb;

    $table_name         = $wpdb->prefix . 'tlk_production';
    $charset_collate    = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        department VARCHAR(50) DEFAULT '',
        employee VARCHAR(100) DEFAULT '',
        qty VARCHAR(50) DEFAULT '',
        entry_date DATETIME NOT NULL,
        PRIMARY KEY (id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta($sql);
}
register_activation_hook(__FILE__, 'tlk_create_production_table');

/**
 * Make sure TLK table exists.
 */
function tlk_schedule_table_exists() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'tlk_schedule';

    $exists = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table_name
        )
    );

    if ($exists !== $table_name) {
        tlk_create_schedule_table();
    }

    return $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table_name
        )
    ) === $table_name;
}

/**
 * Make sure TLK production table exists
 */
function tlk_production_table_exists() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'tlk_production';

    $exists = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table_name
        )
    );

    if ($exists !== $table_name) {
        tlk_create_production_table();
    }

    return $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table_name
        )
    ) === $table_name;
}

/**
 * TLK production table form submissions
 */
function handle_production_form_submission() {

    // Security check
    if (
        !isset($_POST['tlk_production_nonce']) ||
        !wp_verify_nonce(
            $_POST['tlk_production_nonce'],
            'tlk_production_entry'
        )
    ) {
        wp_die('Security check failed.');
    }

    // Make sure required fields exist
    if (
        !isset($_POST['department']) ||
        !isset($_POST['employee']) ||
        !isset($_POST['qty'])
    ) {
        wp_die('Missing required parameters.');
    }

    // Sanitize form values
    $department = sanitize_text_field($_POST['department']);
    $employee   = sanitize_text_field($_POST['employee']);
    $qty        = absint($_POST['qty']);

    global $wpdb;

    // Make sure production table exists
    if (!tlk_production_table_exists()) {
        wp_die('Production table does not exist.');
    }

    $table_name = $wpdb->prefix . 'tlk_production';

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'department' => $department,
            'employee'   => $employee,
            'qty'        => $qty,
            'entry_date' => current_time('mysql'),
        ),
        array(
            '%s',
            '%s',
            '%d',
            '%s',
        )
    );

    // Redirect so refreshing doesn't submit again
    if ($inserted !== false) {
        wp_safe_redirect(home_url('/production-entry-success/'));
        exit;
    }

    wp_die(
        'Database insertion failed: ' .
        esc_html($wpdb->last_error)
    );
}

add_action(
    'admin_post_save_custom_get_data',
    'handle_production_form_submission'
);

/**
 * Sync Google spreadsheet to WordPress
 */
function tlk_sync_schedule_to_database() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'tlk_schedule';

    /*
     * Make sure our database table actually exists.
     */
    if (!tlk_schedule_table_exists()) {
        return new WP_Error(
            'table_missing',
            'Could not create the schedule database table. Database error: ' . $wpdb->last_error
        );
    }

    /*
     * Get current Google Sheet data.
     */
    $rows = get_schedule_data();

    if (empty($rows) || !is_array($rows)) {
        return new WP_Error(
            'no_google_data',
            'No schedule data was returned from Google.'
        );
    }

    /*
     * Google Sheet is source of truth.
     *
     * Remove the old WordPress copy first.
     */
    $deleted = $wpdb->query("TRUNCATE TABLE {$table_name}");

    if ($deleted === false) {
        return new WP_Error(
            'truncate_failed',
            'Could not clear schedule table: ' . $wpdb->last_error
        );
    }

    $inserted = 0;

    foreach ($rows as $row) {

        $result = $wpdb->insert(
            $table_name,
            array(
                'po_number'   => isset($row['P.O.']) ? $row['P.O.'] : '',
                'order_date'  => isset($row['DATE']) ? $row['DATE'] : '',
                'customer'    => isset($row['CUSTOMER']) ? $row['CUSTOMER'] : '',
                'due_date'    => isset($row['DUE']) ? $row['DUE'] : '',
                'part_number' => isset($row['PART NUMBER']) ? $row['PART NUMBER'] : '',
                'qty'         => isset($row['QTY']) ? $row['QTY'] : '',
                'open_qty'    => isset($row['OPEN']) ? $row['OPEN'] : '',
                'status'      => isset($row['STATUS']) ? $row['STATUS'] : '',
                'notes'       => isset($row['NOTES']) ? $row['NOTES'] : '',
                'synced_at'   => current_time('mysql'),
            )
        );

        if ($result === false) {
            return new WP_Error(
                'insert_failed',
                'Database insert failed: ' . $wpdb->last_error
            );
        }

        $inserted++;
    }

    return $inserted;
}

/**
 * Get saved WordPress schedule
 */
function tlk_get_saved_schedule() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'tlk_schedule';

    if (!tlk_schedule_table_exists()) {
        return array();
    }

    return $wpdb->get_results(
        "SELECT * FROM {$table_name} ORDER BY id ASC",
        ARRAY_A
    );
}