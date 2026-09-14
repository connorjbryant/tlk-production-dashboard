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
 * Add page template(s)
 */
add_filter('theme_page_templates', 'tlk_add_page_template_to_dropdown');
function tlk_add_page_template_to_dropdown($templates){
    $templates['templates/page-template.php'] = __('TLK Department Dashboard', 'text-domain');
    $templates['templates/schedule-backup.php'] = __('TLK Schedule', 'text-domain');

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
function tlk_change_page_template($template) {

    if (!is_page()) {
        return $template;
    }

    $selected_template = get_page_template_slug(get_the_ID());

    $plugin_templates = array(
        'templates/page-template.php',
        'templates/schedule-backup.php',
    );

    if (in_array($selected_template, $plugin_templates, true)) {

        $plugin_template = plugin_dir_path(__FILE__) . $selected_template;

        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
    }

    return $template;
}

/**
 * Connects to the Google Apps Script Web App
 */
function get_schedule_data() {
    $web_app_url = 'https://script.google.com/macros/s/AKfycbw3-sQOqCGQwUvoBS3E46vBvjg7hLYmDXrw_vYkQJ7kW2OduB0p588CmBCY9rhd54q0gQ/exec';

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
        open_raw INT NOT NULL DEFAULT 0,
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
 * Create order shipment history table.
 */
function tlk_create_order_history_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'tlk_order_history';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        po_number VARCHAR(100) NOT NULL,
        due_date DATE DEFAULT NULL,
        shipped_date DATE NOT NULL,
        on_time TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY po_number (po_number),
        KEY shipped_date (shipped_date)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta($sql);
}

/**
 * Make sure order history table exists.
 */
function tlk_order_history_table_exists() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'tlk_order_history';

    $exists = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table_name
        )
    );

    if ($exists !== $table_name) {
        tlk_create_order_history_table();
    }

    return $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table_name
        )
    ) === $table_name;
}

/**
 * Convert TLK schedule date to YYYY-MM-DD.
 */
function tlk_normalize_schedule_date($date_string) {

    $date_string = trim((string) $date_string);

    if ($date_string === '') {
        return null;
    }

    $timezone = wp_timezone();

    $date = DateTimeImmutable::createFromFormat(
        '!n/j/y',
        $date_string,
        $timezone
    );

    if (!$date) {
        $date = DateTimeImmutable::createFromFormat(
            '!n/j/Y',
            $date_string,
            $timezone
        );
    }

    if (!$date) {
        return null;
    }

    return $date->format('Y-m-d');
}

register_activation_hook(
    __FILE__,
    'tlk_create_order_history_table'
);

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
    $employee = sanitize_text_field($_POST['employee']);

    if ($employee === '__new__') {

        $employee = isset($_POST['new_employee'])
            ? sanitize_text_field($_POST['new_employee'])
            : '';

        if ($employee === '') {
            wp_die('Please enter the new employee name.');
        }
    }
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
 * Sync Google spreadsheet to WordPress.
 */
function tlk_sync_schedule_to_database() {
    global $wpdb;

    $table_name   = $wpdb->prefix . 'tlk_schedule';
    $history_table = $wpdb->prefix . 'tlk_order_history';

    /*
     * Make sure database tables exist.
     */
    if (!tlk_schedule_table_exists()) {
        return new WP_Error(
            'table_missing',
            'Could not create the schedule database table. Database error: ' .
            $wpdb->last_error
        );
    }

    if (!tlk_order_history_table_exists()) {
        return new WP_Error(
            'history_table_missing',
            'Could not create the order history table. Database error: ' .
            $wpdb->last_error
        );
    }

    /*
     * Get fresh Google Sheet data.
     */
    $rows = get_schedule_data();

    if (empty($rows) || !is_array($rows)) {
        return new WP_Error(
            'no_google_data',
            'No schedule data was returned from Google.'
        );
    }

    /*
     * =========================================
     * Capture orders currently in WordPress.
     * =========================================
     *
     * These represent the schedule BEFORE the
     * newest Google data replaces it.
     */
    $old_rows = $wpdb->get_results(
        "SELECT po_number, due_date
         FROM {$table_name}
         WHERE po_number != ''",
        ARRAY_A
    );

    /*
     * Build a unique list of old P.O.s.
     */
    $old_orders = array();

    foreach ($old_rows as $old_row) {

        $po = trim($old_row['po_number']);

        if ($po === '') {
            continue;
        }

        /*
         * Only need one record per P.O.
         */
        if (!isset($old_orders[$po])) {
            $old_orders[$po] = array(
                'due_date' => $old_row['due_date'],
            );
        }
    }

    /*
     * =========================================
     * Build a list of P.O.s in NEW Google data.
     * =========================================
     */
    $new_orders = array();

    foreach ($rows as $row) {

        $po = isset($row['P.O.'])
            ? trim((string) $row['P.O.'])
            : '';

        if ($po === '') {
            continue;
        }

        $new_orders[$po] = true;
    }

    /*
     * =========================================
     * Detect orders that disappeared.
     * =========================================
     *
     * If an order existed before but is no
     * longer on the schedule, treat it as shipped.
     */
    $shipped_date = current_time('Y-m-d');

    foreach ($old_orders as $po => $old_order) {

        /*
         * Still exists on schedule.
         */
        if (isset($new_orders[$po])) {
            continue;
        }

        /*
         * Order disappeared.
         * Treat this as its shipment date.
         */
        $due_date = tlk_normalize_schedule_date(
            $old_order['due_date']
        );

        /*
         * Determine whether shipment was on time.
         */
        $on_time = 0;

        if ($due_date !== null) {
            $on_time = ($shipped_date <= $due_date) ? 1 : 0;
        }

        /*
         * Save shipment history.
         */
        $wpdb->insert(
            $history_table,
            array(
                'po_number'    => $po,
                'due_date'     => $due_date,
                'shipped_date' => $shipped_date,
                'on_time'      => $on_time,
            ),
            array(
                '%s',
                '%s',
                '%s',
                '%d',
            )
        );
    }

    /*
     * =========================================
     * Google Sheet is source of truth.
     * Replace current schedule.
     * =========================================
     */
    $deleted = $wpdb->query(
        "TRUNCATE TABLE {$table_name}"
    );

    if ($deleted === false) {
        return new WP_Error(
            'truncate_failed',
            'Could not clear schedule table: ' .
            $wpdb->last_error
        );
    }

    $inserted = 0;

    foreach ($rows as $row) {

        $result = $wpdb->insert(
            $table_name,
            array(
                'po_number'   => isset($row['P.O.'])
                    ? $row['P.O.']
                    : '',

                'order_date'  => isset($row['DATE'])
                    ? $row['DATE']
                    : '',

                'customer'    => isset($row['CUSTOMER'])
                    ? $row['CUSTOMER']
                    : '',

                'due_date'    => isset($row['DUE'])
                    ? $row['DUE']
                    : '',

                'part_number' => isset($row['PART NUMBER'])
                    ? $row['PART NUMBER']
                    : '',

                'qty'         => isset($row['QTY'])
                    ? $row['QTY']
                    : '',

                'open_qty'    => isset($row['OPEN'])
                    ? $row['OPEN']
                    : '',

                'open_raw'    => isset($row['OPEN_RAW'])
                    ? absint($row['OPEN_RAW'])
                    : 0,

                'status'      => isset($row['STATUS'])
                    ? $row['STATUS']
                    : '',

                'notes'       => isset($row['NOTES'])
                    ? $row['NOTES']
                    : '',

                'synced_at'   => current_time('mysql'),
            )
        );

        if ($result === false) {
            return new WP_Error(
                'insert_failed',
                'Database insert failed: ' .
                $wpdb->last_error
            );
        }

        $inserted++;
    }

    return $inserted;
}

/**
 * Get total open quantity from saved schedule.
 */
function tlk_get_total_open_orders() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'tlk_schedule';

    if (!tlk_schedule_table_exists()) {
        return 0;
    }

    return (int) $wpdb->get_var(
        "SELECT COALESCE(SUM(open_raw), 0)
         FROM {$table_name}"
    );
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

/**
 * Get number of unique orders that are red on the schedule.
 * Red means due today or already past due.
 */
function tlk_get_past_due_orders() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'tlk_schedule';

    if (!tlk_schedule_table_exists()) {
        return 0;
    }

    $rows = $wpdb->get_results(
        "SELECT po_number, due_date
         FROM {$table_name}
         WHERE po_number != ''
           AND due_date != ''",
        ARRAY_A
    );

    if (empty($rows)) {
        return 0;
    }

    $timezone = wp_timezone();
    $today = new DateTimeImmutable('today', $timezone);

    $past_due_pos = array();

    foreach ($rows as $row) {

        $due_string = trim($row['due_date']);

        $due = DateTimeImmutable::createFromFormat(
            '!n/j/y',
            $due_string,
            $timezone
        );

        if (!$due) {
            $due = DateTimeImmutable::createFromFormat(
                '!n/j/Y',
                $due_string,
                $timezone
            );
        }

        if (!$due) {
            continue;
        }

        // Match Google Sheet red logic:
        // red = due today OR already past due.
        if ($due < $today) {
            $past_due_pos[$row['po_number']] = true;
        }
    }

    return count($past_due_pos);
}

/**
 * Get total open quantity for past due orders.
 * Past due means due BEFORE today.
 */
function tlk_get_past_due_open_quantity() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'tlk_schedule';

    if (!tlk_schedule_table_exists()) {
        return 0;
    }

    $rows = $wpdb->get_results(
        "SELECT due_date, open_raw
         FROM {$table_name}
         WHERE due_date != ''",
        ARRAY_A
    );

    if (empty($rows)) {
        return 0;
    }

    $timezone = wp_timezone();
    $today = new DateTimeImmutable('today', $timezone);

    $total_open = 0;

    foreach ($rows as $row) {

        $due_string = trim($row['due_date']);

        $due = DateTimeImmutable::createFromFormat(
            '!n/j/y',
            $due_string,
            $timezone
        );

        if (!$due) {
            $due = DateTimeImmutable::createFromFormat(
                '!n/j/Y',
                $due_string,
                $timezone
            );
        }

        if (!$due) {
            continue;
        }

        // Past due = BEFORE today.
        if ($due < $today) {
            $total_open += (int) $row['open_raw'];
        }
    }

    return $total_open;
}

/**
 * Get on-time delivery stats for a month.
 */
function tlk_get_on_time_delivery($year, $month) {
    global $wpdb;

    if (!tlk_order_history_table_exists()) {
        return array(
            'total'   => 0,
            'on_time' => 0,
            'percent' => null,
        );
    }

    $table_name = $wpdb->prefix . 'tlk_order_history';

    $year  = absint($year);
    $month = absint($month);

    $start_date = sprintf(
        '%04d-%02d-01',
        $year,
        $month
    );

    $start = new DateTimeImmutable(
        $start_date,
        wp_timezone()
    );

    $end = $start->modify('+1 month');

    $stats = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(on_time) AS on_time
             FROM {$table_name}
             WHERE shipped_date >= %s
               AND shipped_date < %s",
            $start->format('Y-m-d'),
            $end->format('Y-m-d')
        ),
        ARRAY_A
    );

    $total = isset($stats['total'])
        ? (int) $stats['total']
        : 0;

    $on_time = isset($stats['on_time'])
        ? (int) $stats['on_time']
        : 0;

    if ($total === 0) {
        return array(
            'total'   => 0,
            'on_time' => 0,
            'percent' => null,
        );
    }

    return array(
        'total'   => $total,
        'on_time' => $on_time,
        'percent' => ($on_time / $total) * 100,
    );
}

/**
 * Server cron endpoint for the TLK schedule sync.
 *
 * Example:
 * https://your-site.com/?tlk_schedule_cron=YOUR_SECRET_KEY
 * 
 * In Hostinger hPanel, go to site's Advanced Cron Jobs area.
 * Minute: 0 | Hour: 6 | Day, Month, Weekday blank
 * 0 6 * * *
 * curl -fsS "https://YOUR-DOMAIN.com/?tlk_schedule_cron=YOUR_SECRET_KEY" >/dev/null 2>&1
 */
function tlk_handle_server_cron_sync() {

    if (!isset($_GET['tlk_schedule_cron'])) {
        return;
    }

    $provided_key = sanitize_text_field(
        wp_unslash($_GET['tlk_schedule_cron'])
    );

    /*
     * Change this to a long random secret.
     */
    $expected_key = 'asdhasoia889y32thoaegohi';

    if (!hash_equals($expected_key, $provided_key)) {
        status_header(403);
        exit('Unauthorized');
    }

    $result = tlk_sync_schedule_to_database();

    if (is_wp_error($result)) {
        status_header(500);

        exit(
            'TLK sync failed: ' .
            esc_html($result->get_error_message())
        );
    }

    exit(
        'TLK schedule sync complete. Rows synced: ' .
        absint($result)
    );
}
add_action('init', 'tlk_handle_server_cron_sync');

/* Select employees from existing production records */
function tlk_select_employee() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'tlk_production';

    return $wpdb->get_col(
        "SELECT DISTINCT employee
         FROM {$table_name}
         WHERE employee IS NOT NULL
           AND employee != ''
         ORDER BY employee ASC"
    );
}