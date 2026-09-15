<?php
/**
 * Plugin Name: TLK Production Dashboard
 * Description: Production dashboard for TLK Precision
 * Version: 1.1.4
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
        '1.1.4',
        'all'
    );

    // Enqueue JavaScript file
    wp_enqueue_script(
        'tlk_dash_script',
        plugins_url('js/tlk-dash.js', __FILE__),
        array('jquery'),
        '1.1.4',
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
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        department VARCHAR(50) DEFAULT '',
        employee VARCHAR(100) DEFAULT '',
        qty VARCHAR(50) DEFAULT '',
        entry_date DATETIME NOT NULL,
        updated_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY entry_date (entry_date)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta($sql);
}
register_activation_hook(__FILE__, 'tlk_create_production_table');

/**
 * Upgrade the production table when new columns/indexes are added.
 * dbDelta safely updates an existing table without removing its data.
 */
function tlk_maybe_upgrade_production_table() {
    $db_version = '1.1.0';

    if (get_option('tlk_production_db_version') === $db_version) {
        return;
    }

    tlk_create_production_table();
    update_option('tlk_production_db_version', $db_version);
}
add_action('init', 'tlk_maybe_upgrade_production_table', 5);

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
 * Create persistent registry of POs that have appeared on the TLK schedule.
 * This table is not truncated during normal schedule syncs.
 */
function tlk_create_seen_orders_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'tlk_seen_orders';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        po_number VARCHAR(100) NOT NULL,
        due_date DATE DEFAULT NULL,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        shipped_recorded TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY po_number (po_number),
        KEY is_active (is_active),
        KEY shipped_recorded (shipped_recorded)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * Make sure persistent seen-orders table exists.
 */
function tlk_seen_orders_table_exists() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'tlk_seen_orders';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));

    if ($exists !== $table_name) {
        tlk_create_seen_orders_table();
    }

    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;
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

register_activation_hook(
    __FILE__,
    'tlk_create_seen_orders_table'
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

    if (
        !isset($_POST['tlk_production_nonce']) ||
        !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['tlk_production_nonce'])),
            'tlk_production_entry'
        )
    ) {
        wp_die('Security check failed.');
    }

    if (!current_user_can('read')) {
        wp_die('You are not allowed to submit production entries.');
    }

    if (
        !isset($_POST['department']) ||
        !isset($_POST['employee']) ||
        !isset($_POST['qty'])
    ) {
        wp_die('Missing required parameters.');
    }

    $department = sanitize_text_field(wp_unslash($_POST['department']));
    $employees  = (array) wp_unslash($_POST['employee']);
    $quantities = (array) wp_unslash($_POST['qty']);
    $new_names  = isset($_POST['new_employee'])
        ? (array) wp_unslash($_POST['new_employee'])
        : array();

    $allowed_departments = array('CNC', 'Pouring', 'Building');

    if (!in_array($department, $allowed_departments, true)) {
        wp_die('Invalid department.');
    }

    if (count($employees) !== count($quantities)) {
        wp_die('Each employee must have a production quantity.');
    }

    global $wpdb;

    if (!tlk_production_table_exists()) {
        wp_die('Production table does not exist.');
    }

    $table_name = $wpdb->prefix . 'tlk_production';
    $entry_date = current_time('mysql');
    $inserted   = 0;

    foreach ($employees as $index => $employee_raw) {
        $employee = sanitize_text_field($employee_raw);
        $qty_raw  = isset($quantities[$index]) ? $quantities[$index] : '';

        if ($employee === '' || $qty_raw === '') {
            continue;
        }

        if ($employee === '__new__') {
            $employee = isset($new_names[$index])
                ? sanitize_text_field($new_names[$index])
                : '';

            if ($employee === '') {
                wp_die('Please enter the new employee name for each new employee row.');
            }
        }

        $qty = absint($qty_raw);

        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id'    => get_current_user_id(),
                'department' => $department,
                'employee'   => $employee,
                'qty'        => $qty,
                'entry_date' => $entry_date,
            ),
            array('%d', '%s', '%s', '%d', '%s')
        );

        if ($result === false) {
            wp_die(
                'Database insertion failed: ' .
                esc_html($wpdb->last_error)
            );
        }

        $inserted++;
    }

    if ($inserted < 1) {
        wp_die('Please add at least one employee and quantity.');
    }

    wp_safe_redirect(home_url('/production-entry-success/'));
    exit;
}

add_action(
    'admin_post_save_custom_get_data',
    'handle_production_form_submission'
);

/**
 * Get the current logged-in user's production entries that are still editable.
 * Entries are editable for 24 hours after they were created.
 */
function tlk_get_current_user_editable_entries() {
    global $wpdb;

    $user_id = get_current_user_id();

    if (!$user_id || !tlk_production_table_exists()) {
        return array();
    }

    $table_name = $wpdb->prefix . 'tlk_production';
    $now = current_time('mysql');

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, user_id, department, employee, qty, entry_date, updated_at
             FROM {$table_name}
             WHERE user_id = %d
               AND entry_date >= DATE_SUB(%s, INTERVAL 24 HOUR)
             ORDER BY entry_date DESC",
            $user_id,
            $now
        ),
        ARRAY_A
    );
}

/**
 * Update one production entry belonging to the current user.
 * The server enforces the 24-hour edit window.
 */
function tlk_handle_production_entry_update() {
    if (
        !isset($_POST['tlk_edit_production_nonce']) ||
        !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['tlk_edit_production_nonce'])),
            'tlk_edit_production_entry'
        )
    ) {
        wp_die('Security check failed.');
    }

    $entry_id = isset($_POST['entry_id'])
        ? absint($_POST['entry_id'])
        : 0;

    $department = isset($_POST['department'])
        ? sanitize_text_field(wp_unslash($_POST['department']))
        : '';

    $employee = isset($_POST['employee'])
        ? sanitize_text_field(wp_unslash($_POST['employee']))
        : '';

    $qty = isset($_POST['qty'])
        ? absint($_POST['qty'])
        : 0;

    if (!$entry_id || $department === '' || $employee === '') {
        wp_die('Missing required parameters.');
    }

    global $wpdb;

    if (!tlk_production_table_exists()) {
        wp_die('Production table does not exist.');
    }

    $table_name = $wpdb->prefix . 'tlk_production';
    $user_id = get_current_user_id();

    $entry = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, user_id, entry_date
             FROM {$table_name}
             WHERE id = %d
               AND user_id = %d
             LIMIT 1",
            $entry_id,
            $user_id
        ),
        ARRAY_A
    );

    if (!$entry) {
        wp_die('Production entry not found or you do not have permission to edit it.');
    }

    $timezone = wp_timezone();
    $entry_time = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $entry['entry_date'],
        $timezone
    );
    $now = new DateTimeImmutable('now', $timezone);

    if (!$entry_time || $entry_time->modify('+24 hours') < $now) {
        wp_die('This production entry can no longer be edited because the 24-hour edit window has expired.');
    }

    $updated = $wpdb->update(
        $table_name,
        array(
            'department' => $department,
            'employee'   => $employee,
            'qty'        => $qty,
            'updated_at' => current_time('mysql'),
        ),
        array(
            'id'      => $entry_id,
            'user_id' => $user_id,
        ),
        array(
            '%s',
            '%s',
            '%d',
            '%s',
        ),
        array(
            '%d',
            '%d',
        )
    );

    if ($updated === false) {
        wp_die(
            'Database update failed: ' .
            esc_html($wpdb->last_error)
        );
    }

    $redirect_to = isset($_POST['redirect_to'])
        ? wp_validate_redirect(
            esc_url_raw(wp_unslash($_POST['redirect_to'])),
            home_url('/')
        )
        : home_url('/');

    $redirect_to = add_query_arg('entry_updated', '1', $redirect_to);

    wp_safe_redirect($redirect_to);
    exit;
}

add_action(
    'admin_post_tlk_update_production_entry',
    'tlk_handle_production_entry_update'
);

/**
 * Sync Google spreadsheet to WordPress.
 */
function tlk_sync_schedule_to_database() {
    global $wpdb;

    $table_name    = $wpdb->prefix . 'tlk_schedule';
    $history_table = $wpdb->prefix . 'tlk_order_history';
    $seen_table    = $wpdb->prefix . 'tlk_seen_orders';

    if (!tlk_schedule_table_exists()) {
        return new WP_Error('table_missing', 'Could not create the schedule database table. Database error: ' . $wpdb->last_error);
    }

    if (!tlk_order_history_table_exists()) {
        return new WP_Error('history_table_missing', 'Could not create the order history table. Database error: ' . $wpdb->last_error);
    }

    if (!tlk_seen_orders_table_exists()) {
        return new WP_Error('seen_table_missing', 'Could not create the seen-orders database table. Database error: ' . $wpdb->last_error);
    }

    $rows = get_schedule_data();

    if (empty($rows) || !is_array($rows)) {
        return new WP_Error('no_google_data', 'No schedule data was returned from Google.');
    }

    // Build one current record per PO from the fresh Google schedule.
    $current_orders = array();

    foreach ($rows as $row) {
        $po = isset($row['P.O.']) ? trim((string) $row['P.O.']) : '';
        if ($po === '') {
            continue;
        }

        $due_date = isset($row['DUE']) ? tlk_normalize_schedule_date($row['DUE']) : null;

        if (!isset($current_orders[$po])) {
            $current_orders[$po] = array('due_date' => $due_date);
        } elseif (empty($current_orders[$po]['due_date']) && !empty($due_date)) {
            $current_orders[$po]['due_date'] = $due_date;
        }
    }

    // Bootstrap the persistent registry from the existing saved snapshot on the first upgraded sync.
    $seen_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$seen_table}");
    if ($seen_count === 0) {
        $saved_orders = $wpdb->get_results(
            "SELECT po_number, due_date FROM {$table_name} WHERE po_number != ''",
            ARRAY_A
        );
        $bootstrap_time = current_time('mysql');

        foreach ((array) $saved_orders as $saved_order) {
            $po = trim((string) ($saved_order['po_number'] ?? ''));
            if ($po === '') {
                continue;
            }

            $due_date = tlk_normalize_schedule_date($saved_order['due_date'] ?? '');
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$seen_table} (po_number, due_date, first_seen, last_seen, is_active, shipped_recorded)
                 VALUES (%s, %s, %s, %s, 1, 0)
                 ON DUPLICATE KEY UPDATE due_date = VALUES(due_date), last_seen = VALUES(last_seen), is_active = 1",
                $po, $due_date, $bootstrap_time, $bootstrap_time
            ));
        }
    }

    $previously_active = $wpdb->get_results(
        "SELECT po_number, due_date, shipped_recorded FROM {$seen_table} WHERE is_active = 1",
        ARRAY_A
    );

    $shipped_date = current_time('Y-m-d');
    $now = current_time('mysql');

    // Anything previously active but absent now is considered shipped.
    foreach ((array) $previously_active as $old_order) {
        $po = trim((string) ($old_order['po_number'] ?? ''));
        if ($po === '' || isset($current_orders[$po])) {
            continue;
        }

        $due_date = !empty($old_order['due_date']) ? $old_order['due_date'] : null;
        $on_time = ($due_date !== null && $shipped_date <= $due_date) ? 1 : 0;

        $already_recorded = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$history_table} WHERE po_number = %s",
            $po
        ));

        if ($already_recorded === 0) {
            $inserted_history = $wpdb->insert(
                $history_table,
                array(
                    'po_number'    => $po,
                    'due_date'     => $due_date,
                    'shipped_date' => $shipped_date,
                    'on_time'      => $on_time,
                ),
                array('%s', '%s', '%s', '%d')
            );

            if ($inserted_history === false) {
                return new WP_Error('history_insert_failed', 'Could not record shipment history for PO ' . $po . ': ' . $wpdb->last_error);
            }
        }

        $updated_seen = $wpdb->update(
            $seen_table,
            array(
                'is_active'        => 0,
                'shipped_recorded' => 1,
                'last_seen'        => $now,
            ),
            array('po_number' => $po),
            array('%d', '%d', '%s'),
            array('%s')
        );

        if ($updated_seen === false) {
            return new WP_Error('seen_update_failed', 'Could not update persistent PO ' . $po . ': ' . $wpdb->last_error);
        }
    }

    // Register/update every PO currently present in Google.
    foreach ($current_orders as $po => $order_data) {
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, is_active, shipped_recorded FROM {$seen_table} WHERE po_number = %s LIMIT 1",
            $po
        ), ARRAY_A);

        if ($existing) {
            // If a historically shipped PO reappears, keep shipped_recorded intact;
            // history remains duplicate-safe if it disappears again.
            $updated = $wpdb->update(
                $seen_table,
                array(
                    'due_date'  => $order_data['due_date'],
                    'last_seen' => $now,
                    'is_active' => 1,
                ),
                array('po_number' => $po),
                array('%s', '%s', '%d'),
                array('%s')
            );

            if ($updated === false) {
                return new WP_Error('seen_update_failed', 'Could not update persistent PO ' . $po . ': ' . $wpdb->last_error);
            }
        } else {
            $inserted_seen = $wpdb->insert(
                $seen_table,
                array(
                    'po_number'        => $po,
                    'due_date'         => $order_data['due_date'],
                    'first_seen'       => $now,
                    'last_seen'        => $now,
                    'is_active'        => 1,
                    'shipped_recorded' => 0,
                ),
                array('%s', '%s', '%s', '%s', '%d', '%d')
            );

            if ($inserted_seen === false) {
                return new WP_Error('seen_insert_failed', 'Could not register PO ' . $po . ': ' . $wpdb->last_error);
            }
        }
    }

    // Only replace the current snapshot after all detection/registry work succeeds.
    $deleted = $wpdb->query("TRUNCATE TABLE {$table_name}");
    if ($deleted === false) {
        return new WP_Error('truncate_failed', 'Could not clear schedule table: ' . $wpdb->last_error);
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
                'open_raw'    => isset($row['OPEN_RAW']) ? absint($row['OPEN_RAW']) : 0,
                'status'      => isset($row['STATUS']) ? $row['STATUS'] : '',
                'notes'       => isset($row['NOTES']) ? $row['NOTES'] : '',
                'synced_at'   => $now,
            )
        );

        if ($result === false) {
            return new WP_Error('insert_failed', 'Database insert failed: ' . $wpdb->last_error);
        }

        $inserted++;
    }

    update_option('tlk_schedule_last_successful_sync', $now, false);
    update_option('tlk_schedule_last_sync_row_count', $inserted, false);

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
 * Get all month/year combinations that have relevant dashboard activity.
 *
 * Uses:
 * - Production entry dates from wp_tlk_production
 * - Historical order due dates from wp_tlk_order_history
 *
 * This allows historical months to remain selectable even if there are
 * no production entries for that month.
 */
function tlk_get_available_dashboard_periods() {
    global $wpdb;

    $periods = array();

    /*
     * =========================================
     * Production entry periods
     * =========================================
     */
    if (tlk_production_table_exists()) {

        $production_table = $wpdb->prefix . 'tlk_production';

        $production_periods = $wpdb->get_results(
            "SELECT DISTINCT
                YEAR(entry_date) AS year,
                MONTH(entry_date) AS month
             FROM {$production_table}
             WHERE entry_date IS NOT NULL
             ORDER BY year DESC, month DESC",
            ARRAY_A
        );

        foreach ($production_periods as $period) {

            $year  = (int) $period['year'];
            $month = (int) $period['month'];

            if (!$year || !$month) {
                continue;
            }

            $key = sprintf(
                '%04d-%02d',
                $year,
                $month
            );

            $periods[$key] = array(
                'year'  => $year,
                'month' => $month,
            );
        }
    }

    /*
     * =========================================
     * Historical schedule periods
     * =========================================
     *
     * Use due_date here instead of shipped_date.
     *
     * Example:
     * Due: August 31
     * Shipped: September 10
     *
     * August should still be considered a month
     * where schedule activity existed.
     */
    if (tlk_order_history_table_exists()) {

        $history_table = $wpdb->prefix . 'tlk_order_history';

        $history_periods = $wpdb->get_results(
            "SELECT DISTINCT
                YEAR(due_date) AS year,
                MONTH(due_date) AS month
             FROM {$history_table}
             WHERE due_date IS NOT NULL
             ORDER BY year DESC, month DESC",
            ARRAY_A
        );

        foreach ($history_periods as $period) {

            $year  = (int) $period['year'];
            $month = (int) $period['month'];

            if (!$year || !$month) {
                continue;
            }

            $key = sprintf(
                '%04d-%02d',
                $year,
                $month
            );

            $periods[$key] = array(
                'year'  => $year,
                'month' => $month,
            );
        }
    }

    /*
     * Sort newest to oldest.
     */
    krsort($periods);

    return array_values($periods);
}

/**
 * Get on-time delivery stats for a month.
 */
function tlk_get_on_time_delivery($year, $month) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'tlk_order_history';

    $start_date = sprintf(
        '%04d-%02d-01',
        $year,
        $month
    );

    $end_date = gmdate(
        'Y-m-d',
        strtotime($start_date . ' +1 month')
    );

    $orders = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT
                po_number,
                due_date,
                shipped_date,
                on_time
             FROM {$table_name}
             WHERE shipped_date >= %s
               AND shipped_date < %s
             ORDER BY shipped_date ASC, po_number ASC",
            $start_date,
            $end_date
        ),
        ARRAY_A
    );

    $total = count($orders);

    $on_time = 0;

    foreach ($orders as $order) {
        if ((int) $order['on_time'] === 1) {
            $on_time++;
        }
    }

    $percent = $total > 0
        ? ($on_time / $total) * 100
        : null;

    return array(
        'percent' => $percent,
        'on_time' => $on_time,
        'total'   => $total,
        'orders'  => $orders,
    );
}

/**
 * Server cron endpoint for the TLK schedule sync.
 *
 * Example:
 * https://your-site.com/?tlk_schedule_cron=YOUR_SECRET_KEY
 * 
 * In Hostinger hPanel, go to site's Advanced Cron Jobs area.
 * Recommended: run hourly.
 * Minute: 0 | Hour: * | Day: * | Month: * | Weekday: *
 * 0 * * * *
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

/**
 * Redirect selected users once, immediately after login.
 */
function custom_login_redirect($redirect_to, $request, $user) {

    if (is_wp_error($user)) {
        return $redirect_to;
    }

    $allowed_emails = array(
        'connor@flexrockperformance.com',
        'josh@tlkprecision.com',
        'brian@tlkprecision.com',
        'todd@tlkprecision.com',
        'deric@tlkprecision.com',
    );

    if (
        !empty($user->user_email) &&
        in_array(
            $user->user_email,
            $allowed_emails,
            true
        )
    ) {
        return home_url('/tlk-production-dashboard/');
    }

    return $redirect_to;
}

add_filter(
    'login_redirect',
    'custom_login_redirect',
    PHP_INT_MAX,
    3
);

/* Check if CNC has made at least 60 parts this month */
function cnc_quota(){
    global $wpdb;

    $target_num = 60;

    $table_name = $wpdb->prefix . 'tlk_production';

    $current_year  = (int) wp_date('Y');
    $current_month = (int) wp_date('n');

    $result = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COALESCE(SUM(qty), 0)
             FROM {$table_name}
             WHERE department = %s
               AND YEAR(entry_date) = %d
               AND MONTH(entry_date) = %d",
            'CNC',
            $current_year,
            $current_month
        )
    );

    return array(
        'total' => (int) $result,
        'met'   => (int) $result >= $target_num,
    );
}

/* Check if Pouring has made at least 60 parts this month */
function pouring_quota() {
    global $wpdb;

    $target_num = 60;

    $table_name = $wpdb->prefix . 'tlk_production';

    $current_year  = (int) wp_date('Y');
    $current_month = (int) wp_date('n');

    $result = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COALESCE(SUM(qty), 0)
             FROM {$table_name}
             WHERE department = %s
               AND YEAR(entry_date) = %d
               AND MONTH(entry_date) = %d",
            'Pouring',
            $current_year,
            $current_month
        )
    );

    return array(
        'total' => (int) $result,
        'met'   => (int) $result >= $target_num,
    );
}

/* Check if Buiding has made at least 60 parts this month */
function building_quota() {
    global $wpdb;

    $target_num = 60;

    $table_name = $wpdb->prefix . 'tlk_production';

    $current_year  = (int) wp_date('Y');
    $current_month = (int) wp_date('n');

    $result = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COALESCE(SUM(qty), 0)
             FROM {$table_name}
             WHERE department = %s
               AND YEAR(entry_date) = %d
               AND MONTH(entry_date) = %d",
            'Building',
            $current_year,
            $current_month
        )
    );

    return array(
        'total' => (int) $result,
        'met'   => (int) $result >= $target_num,
    );
}