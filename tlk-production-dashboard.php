<?php
/**
 * Plugin Name: TLK Production Dashboard
 * Description: Production dashboard for TLK Precision
 * Version: 1.8.9
 * Author: Connor Bryant
 * License: GPL-2.0+
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

function tlk_is_production_dashboard() {
    if (!is_page()) {
        return false;
    }
    return get_page_template_slug(get_the_ID()) === 'templates/page-template.php';
}

function tlk_dash_enqueue_assets(){
    if (!tlk_is_production_dashboard()) {
        return;
    }

    $version = '1.8.9';

    wp_enqueue_style(
        'tlk_dash_styles',
        plugins_url('css/tlk-dash.css', __FILE__),
        array(),
        $version,
        'all'
    );

    wp_enqueue_script(
        'tlk_dash_script',
        plugins_url('js/tlk-dash.js', __FILE__),
        array('jquery'),
        $version,
        true
    );
}
add_action('wp_enqueue_scripts', 'tlk_dash_enqueue_assets');

/**
 * Force a desktop-class viewport so Smart TV browsers don't
 * report a tiny CSS viewport and trigger mobile breakpoints.
 * Only output on the dashboard template to avoid affecting other pages.
 */
function tlk_dash_viewport_meta() {
    if (!tlk_is_production_dashboard()) {
        return;
    }
    echo '<meta name="viewport" content="width=1920, initial-scale=1">' . "\n";
    echo '<script>document.documentElement.className += " tlk-prod-dash";</script>' . "\n";
}
add_action('wp_head', 'tlk_dash_viewport_meta', 0);


function tlk_dash_force_desktop_class($classes) {
    $classes[] = 'tlk-prod-dash';
    return $classes;
}

function tlk_dash_maybe_force_desktop() {
    if (!is_page()) {
        return;
    }
    $selected = get_page_template_slug(get_the_ID());
    if ($selected === 'templates/page-template.php') {
        add_filter('body_class', 'tlk_dash_force_desktop_class', 99);
    }
}
add_action('wp', 'tlk_dash_maybe_force_desktop');

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
 * Repair department values written by older recent-entry edit forms.
 *
 * Older versions saved cnc / pour / Build instead of the canonical values
 * CNC / Pouring / Building. That caused edited rows to stop matching the
 * department totals. Run this migration once per site.
 */
function tlk_normalize_legacy_department_values() {
    if (get_option('tlk_department_value_migration_189') === 'done') {
        return;
    }

    global $wpdb;

    if (!tlk_production_table_exists()) {
        return;
    }

    $table_name = $wpdb->prefix . 'tlk_production';

    $wpdb->query(
        "UPDATE {$table_name}
         SET department = CASE
             WHEN LOWER(TRIM(department)) = 'cnc' THEN 'CNC'
             WHEN LOWER(TRIM(department)) IN ('pour', 'pouring') THEN 'Pouring'
             WHEN LOWER(TRIM(department)) IN ('build', 'building') THEN 'Building'
             ELSE department
         END
         WHERE LOWER(TRIM(department)) IN ('cnc', 'pour', 'pouring', 'build', 'building')"
    );

    update_option('tlk_department_value_migration_189', 'done', false);
}
add_action('init', 'tlk_normalize_legacy_department_values', 20);

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
            $current_user = wp_get_current_user();
            $can_add_employee = is_user_logged_in()
                && strtolower((string) $current_user->user_email) === 'connor@flexrockperformance.com';

            if (!$can_add_employee) {
                wp_die(
                    'You do not have permission to add employees.',
                    'Permission Denied',
                    array('response' => 403)
                );
            }

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

    // Recent-entry edits must use the same canonical department values as
    // the main production-entry form and the reporting queries.
    $department_aliases = array(
        'cnc'      => 'CNC',
        'pour'     => 'Pouring',
        'pouring'  => 'Pouring',
        'build'    => 'Building',
        'building' => 'Building',
    );
    $department_key = strtolower(trim($department));
    $department = isset($department_aliases[$department_key])
        ? $department_aliases[$department_key]
        : $department;

    $allowed_departments = array('CNC', 'Pouring', 'Building');
    if (!in_array($department, $allowed_departments, true)) {
        wp_die('Invalid department.');
    }

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
 * Email address used for TLK schedule sync notifications.
 */
function tlk_schedule_notification_email() {
    return 'connor@flexrockperformance.com';
}

/**
 * Normalize a schedule row so saved database rows and fresh Google rows
 * can be compared consistently.
 */
function tlk_schedule_notification_normalize_row($row, $source = 'database') {
    if ($source === 'google') {
        return array(
            'po_number'   => trim((string) ($row['P.O.'] ?? '')),
            'order_date'  => trim((string) ($row['DATE'] ?? '')),
            'customer'    => trim((string) ($row['CUSTOMER'] ?? '')),
            'due_date'    => trim((string) ($row['DUE'] ?? '')),
            'part_number' => trim((string) ($row['PART NUMBER'] ?? '')),
            'qty'         => trim((string) ($row['QTY'] ?? '')),
            'open_qty'    => trim((string) ($row['OPEN'] ?? '')),
            'status'      => trim((string) ($row['STATUS'] ?? '')),
            'notes'       => trim((string) ($row['NOTES'] ?? '')),
        );
    }

    return array(
        'po_number'   => trim((string) ($row['po_number'] ?? '')),
        'order_date'  => trim((string) ($row['order_date'] ?? '')),
        'customer'    => trim((string) ($row['customer'] ?? '')),
        'due_date'    => trim((string) ($row['due_date'] ?? '')),
        'part_number' => trim((string) ($row['part_number'] ?? '')),
        'qty'         => trim((string) ($row['qty'] ?? '')),
        'open_qty'    => trim((string) ($row['open_qty'] ?? '')),
        'status'      => trim((string) ($row['status'] ?? '')),
        'notes'       => trim((string) ($row['notes'] ?? '')),
    );
}

/**
 * Group schedule rows by PO and create a stable signature for comparison.
 */
function tlk_schedule_notification_group_rows($rows, $source = 'database') {
    $grouped = array();

    foreach ((array) $rows as $row) {
        $normalized = tlk_schedule_notification_normalize_row($row, $source);
        $po = $normalized['po_number'];

        if ($po === '') {
            continue;
        }

        if (!isset($grouped[$po])) {
            $grouped[$po] = array();
        }

        $grouped[$po][] = $normalized;
    }

    foreach ($grouped as $po => &$po_rows) {
        usort($po_rows, function ($a, $b) {
            return strcmp(wp_json_encode($a), wp_json_encode($b));
        });
    }
    unset($po_rows);

    ksort($grouped, SORT_NATURAL);

    return $grouped;
}

/**
 * Compare the previous saved schedule with the fresh Google schedule.
 */
function tlk_schedule_notification_get_changes($old_rows, $new_rows) {
    $old = tlk_schedule_notification_group_rows($old_rows, 'database');
    $new = tlk_schedule_notification_group_rows($new_rows, 'google');

    $changes = array(
        'added'   => array(),
        'removed' => array(),
        'changed' => array(),
    );

    foreach ($new as $po => $rows) {
        if (!isset($old[$po])) {
            $changes['added'][$po] = $rows;
            continue;
        }

        if (wp_json_encode($old[$po]) !== wp_json_encode($rows)) {
            $changes['changed'][$po] = array(
                'old' => $old[$po],
                'new' => $rows,
            );
        }
    }

    foreach ($old as $po => $rows) {
        if (!isset($new[$po])) {
            $changes['removed'][$po] = $rows;
        }
    }

    return $changes;
}

/**
 * Format all rows for one PO into concise email text.
 */
function tlk_schedule_notification_format_po_rows($rows) {
    $lines = array();

    foreach ((array) $rows as $row) {
        $parts = array();

        if ($row['customer'] !== '') {
            $parts[] = 'Customer: ' . $row['customer'];
        }
        if ($row['part_number'] !== '') {
            $parts[] = 'Part: ' . $row['part_number'];
        }
        if ($row['qty'] !== '') {
            $parts[] = 'Qty: ' . $row['qty'];
        }
        if ($row['open_qty'] !== '') {
            $parts[] = 'Open: ' . $row['open_qty'];
        }
        if ($row['due_date'] !== '') {
            $parts[] = 'Due: ' . $row['due_date'];
        }
        if ($row['status'] !== '') {
            $parts[] = 'Status: ' . $row['status'];
        }
        if ($row['notes'] !== '') {
            $parts[] = 'Notes: ' . $row['notes'];
        }

        $lines[] = '  - ' . implode(' | ', $parts);
    }

    return implode("\n", $lines);
}

/**
 * Send an email only when the schedule itself changed.
 */
function tlk_send_schedule_change_email($changes, $sync_time) {
    $added_count   = count($changes['added']);
    $removed_count = count($changes['removed']);
    $changed_count = count($changes['changed']);

    if (($added_count + $removed_count + $changed_count) === 0) {
        return;
    }

    $subject = sprintf(
        'TLK Schedule Changes Detected - %d Added, %d Removed, %d Changed',
        $added_count,
        $removed_count,
        $changed_count
    );

    $message = "TLK schedule changes were detected during the sync at {$sync_time}.\n\n";

    if ($added_count > 0) {
        $message .= "NEW PO(S)\n";
        $message .= "---------\n";
        foreach ($changes['added'] as $po => $rows) {
            $message .= "PO {$po}\n";
            $message .= tlk_schedule_notification_format_po_rows($rows) . "\n\n";
        }
    }

    if ($removed_count > 0) {
        $message .= "REMOVED / NO LONGER ON SCHEDULE\n";
        $message .= "-------------------------------\n";
        foreach ($changes['removed'] as $po => $rows) {
            $message .= "PO {$po}\n";
            $message .= tlk_schedule_notification_format_po_rows($rows) . "\n\n";
        }
    }

    if ($changed_count > 0) {
        $message .= "CHANGED PO(S)\n";
        $message .= "-------------\n";
        foreach ($changes['changed'] as $po => $change) {
            $message .= "PO {$po}\n";
            $message .= "Before:\n";
            $message .= tlk_schedule_notification_format_po_rows($change['old']) . "\n";
            $message .= "After:\n";
            $message .= tlk_schedule_notification_format_po_rows($change['new']) . "\n\n";
        }
    }

    wp_mail(tlk_schedule_notification_email(), $subject, $message);
}

/**
 * Send a confirmation after every successful schedule sync.
 */
function tlk_send_schedule_sync_success_email($inserted, $changes, $sync_time) {
    $change_total = count($changes['added']) + count($changes['removed']) + count($changes['changed']);

    $subject = 'TLK Schedule Successfully Synced';
    $message = "The TLK schedule successfully synced at {$sync_time}.\n\n";
    $message .= 'Rows synced: ' . absint($inserted) . "\n";
    $message .= 'New POs: ' . count($changes['added']) . "\n";
    $message .= 'Removed POs: ' . count($changes['removed']) . "\n";
    $message .= 'Changed POs: ' . count($changes['changed']) . "\n";
    $message .= 'Schedule changes detected: ' . ($change_total > 0 ? 'Yes' : 'No') . "\n";

    wp_mail(tlk_schedule_notification_email(), $subject, $message);
}

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

    // Capture the current saved snapshot before it is replaced so we can
    // report exactly which POs were added, removed, or changed.
    $previous_schedule_rows = $wpdb->get_results(
        "SELECT po_number, order_date, customer, due_date, part_number, qty, open_qty, status, notes FROM {$table_name} ORDER BY id ASC",
        ARRAY_A
    );

    // Avoid treating the very first sync on an empty installation as a giant change.
    $schedule_changes = !empty($previous_schedule_rows)
        ? tlk_schedule_notification_get_changes($previous_schedule_rows, $rows)
        : array('added' => array(), 'removed' => array(), 'changed' => array());

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

    // A change email is sent only when the schedule differs from the previous
    // saved snapshot. A success email is sent after every completed sync.
    tlk_send_schedule_change_email($schedule_changes, $now);
    tlk_send_schedule_sync_success_email($inserted, $schedule_changes, $now);

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

/**
 * Get the average monthly production per employee for a department.
 *
 * Each employee's entries for the current month are totaled first.
 * Those employee totals are then averaged together.
 */
function tlk_department_quota($department) {
    global $wpdb;

    $target_num = tlk_get_department_target($department);
    $table_name = $wpdb->prefix . 'tlk_production';

    $current_year  = (int) wp_date('Y');
    $current_month = (int) wp_date('n');

    $employee_totals = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT SUM(qty) AS employee_total
             FROM {$table_name}
             WHERE department = %s
               AND YEAR(entry_date) = %d
               AND MONTH(entry_date) = %d
             GROUP BY employee",
            $department,
            $current_year,
            $current_month
        )
    );

    if (empty($employee_totals)) {
        $average = 0;
    } else {
        $average = array_sum(array_map('intval', $employee_totals)) / count($employee_totals);
    }

    return array(
        'total' => $average,
        'met'   => $average >= $target_num,
    );
}

/* CNC average production per employee this month */
function cnc_quota() {
    return tlk_department_quota('CNC');
}

/* Pouring average production per employee this month */
function pouring_quota() {
    return tlk_department_quota('Pouring');
}

/* Building average production per employee this month */
function building_quota() {
    return tlk_department_quota('Building');
}
/**
 * Department-level monthly targets used by the frontend average-parts metric.
 * Stored in wp_options so they can be edited without changing PHP.
 */
function tlk_get_department_targets() {
    $defaults = array(
        'CNC'      => 60,
        'Pouring'  => 60,
        'Building' => 60,
    );

    $saved = get_option('tlk_department_targets', array());
    if (!is_array($saved)) {
        $saved = array();
    }

    return array_merge($defaults, $saved);
}

function tlk_get_department_target($department) {
    $targets = tlk_get_department_targets();
    return isset($targets[$department]) ? (float) $targets[$department] : 60.0;
}

function tlk_save_department_targets() {
    if (!tlk_can_manage_employee_performance()) {
        wp_die('You are not allowed to manage department targets.');
    }

    check_admin_referer('tlk_save_department_targets');

    $departments = array('CNC', 'Pouring', 'Building');
    $posted = isset($_POST['department_targets']) ? (array) wp_unslash($_POST['department_targets']) : array();
    $targets = tlk_get_department_targets();

    foreach ($departments as $department) {
        if (isset($posted[$department])) {
            $targets[$department] = max(0, (float) $posted[$department]);
        }
    }

    update_option('tlk_department_targets', $targets, false);

    wp_safe_redirect(add_query_arg(array(
        'page' => 'tlk-employee-performance',
        'department_targets_saved' => '1',
    ), admin_url('admin.php')));
    exit;
}
add_action('admin_post_tlk_save_department_targets', 'tlk_save_department_targets');

/**
 * Individual employee production targets + private performance reporting.
 * Targets are monthly and are used only to calculate department goal attainment.
 */
function tlk_create_employee_targets_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tlk_employee_targets';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        employee VARCHAR(100) NOT NULL,
        department VARCHAR(50) NOT NULL,
        monthly_target DECIMAL(10,2) NOT NULL DEFAULT 60,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY employee_department (employee, department)
    ) {$charset_collate};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'tlk_create_employee_targets_table');

function tlk_maybe_upgrade_employee_targets_table() {
    if (get_option('tlk_employee_targets_db_version') === '1.0.0') return;
    tlk_create_employee_targets_table();
    update_option('tlk_employee_targets_db_version', '1.0.0');
}
add_action('init', 'tlk_maybe_upgrade_employee_targets_table', 6);

function tlk_can_manage_employee_performance() {
    if (!is_user_logged_in()) return false;
    $user = wp_get_current_user();
    $allowed = array(
        'connor@flexrockperformance.com',
        'josh@tlkprecision.com',
        'brian@tlkprecision.com',
        'todd@tlkprecision.com',
        'deric@tlkprecision.com',
    );
    return current_user_can('manage_options') || in_array(strtolower((string) $user->user_email), $allowed, true);
}

function tlk_get_employee_target($employee, $department, $default = 60) {
    global $wpdb;
    $table = $wpdb->prefix . 'tlk_employee_targets';
    $target = $wpdb->get_var($wpdb->prepare(
        "SELECT monthly_target FROM {$table} WHERE employee = %s AND department = %s LIMIT 1",
        $employee, $department
    ));
    return $target !== null ? (float) $target : (float) $default;
}

function tlk_get_employee_performance($department, $year, $month) {
    global $wpdb;
    $production = $wpdb->prefix . 'tlk_production';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT employee, SUM(CAST(qty AS DECIMAL(10,2))) AS produced
         FROM {$production}
         WHERE department = %s AND YEAR(entry_date) = %d AND MONTH(entry_date) = %d
           AND employee IS NOT NULL AND employee != ''
         GROUP BY employee ORDER BY employee ASC",
        $department, $year, $month
    ), ARRAY_A);

    foreach ($rows as &$row) {
        $row['produced'] = (float) $row['produced'];
        $row['target'] = tlk_get_employee_target($row['employee'], $department, 60);
        $row['percent'] = $row['target'] > 0 ? ($row['produced'] / $row['target']) * 100 : 0;
    }
    unset($row);
    return $rows;
}

function tlk_get_department_performance($department, $year = null, $month = null) {
    $year = $year ?: (int) wp_date('Y');
    $month = $month ?: (int) wp_date('n');
    $rows = tlk_get_employee_performance($department, $year, $month);
    if (!$rows) return array('average_parts' => 0, 'percent' => 0, 'met' => false, 'employee_count' => 0);

    $produced = array_sum(array_column($rows, 'produced'));
    $percent_sum = array_sum(array_column($rows, 'percent'));
    $count = count($rows);
    $percent = $percent_sum / $count;
    return array(
        'average_parts' => $produced / $count,
        'percent' => $percent,
        'met' => $percent >= 100,
        'employee_count' => $count,
    );
}

function tlk_employee_performance_menu() {
    add_menu_page(
        'Employee Performance', 'Employee Performance', 'read',
        'tlk-employee-performance', 'tlk_render_employee_performance_page',
        'dashicons-chart-bar', 26
    );
}
add_action('admin_menu', 'tlk_employee_performance_menu');

function tlk_save_employee_targets() {
    if (!tlk_can_manage_employee_performance()) wp_die('You are not allowed to manage employee targets.');
    check_admin_referer('tlk_save_employee_targets');
    global $wpdb;
    $table = $wpdb->prefix . 'tlk_employee_targets';
    $targets = isset($_POST['targets']) ? (array) wp_unslash($_POST['targets']) : array();
    foreach ($targets as $encoded => $value) {
        $parts = explode('|', base64_decode($encoded), 2);
        if (count($parts) !== 2) continue;
        list($department, $employee) = $parts;
        $target = max(0, (float) $value);
        $wpdb->replace($table, array(
            'employee' => sanitize_text_field($employee),
            'department' => sanitize_text_field($department),
            'monthly_target' => $target,
            'updated_at' => current_time('mysql'),
        ), array('%s','%s','%f','%s'));
    }
    wp_safe_redirect(add_query_arg(array('page'=>'tlk-employee-performance','targets_saved'=>'1'), admin_url('admin.php')));
    exit;
}
add_action('admin_post_tlk_save_employee_targets', 'tlk_save_employee_targets');

function tlk_render_employee_performance_page() {
    if (!tlk_can_manage_employee_performance()) wp_die('You are not allowed to view employee performance.');
    $year = isset($_GET['year']) ? max(2020, absint($_GET['year'])) : (int) wp_date('Y');
    $month = isset($_GET['month']) ? min(12, max(1, absint($_GET['month']))) : (int) wp_date('n');
    $departments = array('CNC','Pouring','Building');
    ?>
    <div class="wrap">
        <h1>Employee Performance</h1>
        <p>Private individual production detail. The public admin dashboard shows department-level results.</p>
        <a href="/tlk-production-dashboard">Go To Dashboard Overview</a>
        <?php if (isset($_GET['department_targets_saved'])) : ?><div class="notice notice-success is-dismissible"><p>Department targets saved.</p></div><?php endif; ?>

        <?php $department_targets = tlk_get_department_targets(); ?>
        <div class="card" style="max-width:900px;margin:18px 0;padding:18px 22px;">
            <h2 style="margin-top:0;">Department Targets</h2>
            <p>Set a goal number of parts people should make on average.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tlk_save_department_targets">
                <?php wp_nonce_field('tlk_save_department_targets'); ?>
                <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end;">
                    <?php foreach (array('CNC','Pouring','Building') as $target_department) : ?>
                        <label>
                            <strong><?php echo esc_html($target_department); ?></strong><br>
                            <input type="number" min="0" step="1" name="department_targets[<?php echo esc_attr($target_department); ?>]" value="<?php echo esc_attr($department_targets[$target_department]); ?>" style="width:120px;">
                        </label>
                    <?php endforeach; ?>
                    <button type="submit" class="button button-primary">Save Department Targets</button>
                </div>
            </form>
        </div>
        <?php if (isset($_GET['targets_saved'])) : ?><div class="notice notice-success is-dismissible"><p>Employee targets saved.</p></div><?php endif; ?>
        <form method="get" style="margin:18px 0;display:flex;gap:8px;align-items:center;">
            <input type="hidden" name="page" value="tlk-employee-performance">
            <select name="month"><?php for ($m=1;$m<=12;$m++): ?><option value="<?php echo $m; ?>" <?php selected($month,$m); ?>><?php echo esc_html(wp_date('F', mktime(0,0,0,$m,1))); ?></option><?php endfor; ?></select>
            <input type="number" name="year" value="<?php echo esc_attr($year); ?>" min="2020" max="2100">
            <button class="button">View</button>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="tlk_save_employee_targets">
            <?php wp_nonce_field('tlk_save_employee_targets'); ?>
            <?php foreach ($departments as $department):
                $rows = tlk_get_employee_performance($department, $year, $month);
                $dept = tlk_get_department_performance($department, $year, $month); ?>
                <h2 style="margin-top:28px;"><?php echo esc_html($department); ?> <small style="font-weight:400;">— <?php echo esc_html(number_format_i18n($dept['percent'],1)); ?>% goal attainment</small></h2>
                <?php if (!$rows): ?><p>No production entries for this department in this period.</p><?php else: ?>
                <table class="widefat striped" style="max-width:900px;">
                    <thead><tr><th>Employee</th><th>Produced</th><th>Expected</th><th>Goal %</th></tr></thead>
                    <tbody><?php foreach ($rows as $row):
                        $key = base64_encode($department . '|' . $row['employee']); ?>
                        <tr>
                            <td><strong><?php echo esc_html($row['employee']); ?></strong></td>
                            <td><?php echo esc_html(number_format_i18n($row['produced'])); ?></td>
                            <td><input type="number" min="0" step="1" name="targets[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($row['target']); ?>" style="width:100px;"></td>
                            <td><?php echo esc_html(number_format_i18n($row['percent'],1)); ?>%</td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table>
                <?php endif; ?>
            <?php endforeach; ?>
            <p><button type="submit" class="button button-primary">Save Expected Production</button></p>
        </form>
    </div>
    <?php
}