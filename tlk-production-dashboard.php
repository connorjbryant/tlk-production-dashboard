<?php
/**
 * Plugin Name: TLK Production Dashboard
 * Description: Production dashboard for TLK Precision
 * Version: 3.4.7
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

    $version = '3.4.7';

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
 * Reserved label for legacy department totals where the employee breakdown is unknown.
 * These parts count toward department production totals, but never toward employee
 * performance, active person-days, or per-person goal percentages.
 */
function tlk_historical_unassigned_employee() {
    return 'Historical / Unassigned';
}

function tlk_is_historical_unassigned_employee($employee) {
    return strcasecmp(trim((string) $employee), tlk_historical_unassigned_employee()) === 0;
}

/**
 * Employee roster table. This is intentionally separate from production logs:
 * being on the roster never counts as production or as an active person-day.
 */
function tlk_create_employee_roster_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'tlk_employees';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        employee_name VARCHAR(100) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY employee_name (employee_name),
        KEY active (active)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'tlk_create_employee_roster_table');

function tlk_seed_employee_roster_from_production() {
    global $wpdb;

    $roster     = $wpdb->prefix . 'tlk_employees';
    $production = $wpdb->prefix . 'tlk_production';

    $names = $wpdb->get_col(
        "SELECT DISTINCT employee FROM {$production}
         WHERE employee IS NOT NULL AND employee != ''
           AND employee != 'Historical / Unassigned'
         ORDER BY employee ASC"
    );

    foreach ($names as $name) {
        $name = sanitize_text_field($name);
        if ($name === '') continue;

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$roster} (employee_name, active, created_at) VALUES (%s, 1, %s)",
            $name,
            current_time('mysql')
        ));
    }
}

function tlk_maybe_upgrade_employee_roster_table() {
    $db_version = '1.0.0';
    if (get_option('tlk_employee_roster_db_version') === $db_version) return;

    tlk_create_employee_roster_table();
    tlk_seed_employee_roster_from_production();
    update_option('tlk_employee_roster_db_version', $db_version);
}
add_action('init', 'tlk_maybe_upgrade_employee_roster_table', 6);

function tlk_can_manage_employee_roster() {
    if (!is_user_logged_in()) return false;
    $user = wp_get_current_user();
    return strtolower((string) $user->user_email) === 'connor@flexrockperformance.com';
}

function tlk_get_employee_roster($active_only = true) {
    global $wpdb;
    $table = $wpdb->prefix . 'tlk_employees';
    $where = $active_only ? 'WHERE active = 1' : '';

    return $wpdb->get_results(
        "SELECT id, employee_name, active FROM {$table} {$where} ORDER BY employee_name ASC"
    );
}

function tlk_handle_add_employee_roster() {
    if (!tlk_can_manage_employee_roster()) wp_die('You do not have permission to manage employees.', 'Permission Denied', array('response' => 403));
    check_admin_referer('tlk_manage_employee_roster');

    $name = isset($_POST['employee_name']) ? sanitize_text_field(wp_unslash($_POST['employee_name'])) : '';
    if ($name === '') wp_die('Please enter an employee name.');

    global $wpdb;
    $table = $wpdb->prefix . 'tlk_employees';
    $existing = $wpdb->get_row($wpdb->prepare("SELECT id, active FROM {$table} WHERE employee_name = %s LIMIT 1", $name));

    if ($existing) {
        $wpdb->update($table, array('active' => 1), array('id' => (int) $existing->id), array('%d'), array('%d'));
    } else {
        $wpdb->insert($table, array('employee_name' => $name, 'active' => 1, 'created_at' => current_time('mysql')), array('%s','%d','%s'));
    }

    $redirect = wp_get_referer() ?: home_url('/tlk-production-dashboard/');
    wp_safe_redirect(add_query_arg('tlk_employee_saved', '1', $redirect));
    exit;
}
add_action('admin_post_tlk_add_employee_roster', 'tlk_handle_add_employee_roster');

function tlk_handle_toggle_employee_roster() {
    if (!tlk_can_manage_employee_roster()) wp_die('You do not have permission to manage employees.', 'Permission Denied', array('response' => 403));
    check_admin_referer('tlk_manage_employee_roster');

    $id = isset($_POST['employee_id']) ? absint($_POST['employee_id']) : 0;
    $active = isset($_POST['active']) ? (int) (bool) absint($_POST['active']) : 0;
    if (!$id) wp_die('Invalid employee.');

    global $wpdb;
    $table = $wpdb->prefix . 'tlk_employees';
    $wpdb->update($table, array('active' => $active), array('id' => $id), array('%d'), array('%d'));

    $redirect = wp_get_referer() ?: home_url('/tlk-production-dashboard/');
    wp_safe_redirect(add_query_arg('tlk_employee_saved', '1', $redirect));
    exit;
}
add_action('admin_post_tlk_toggle_employee_roster', 'tlk_handle_toggle_employee_roster');

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
 * Store one successful schedule sync in today's digest log.
 * Individual success/change emails are intentionally not sent here.
 */
function tlk_log_schedule_sync_for_daily_digest($inserted, $changes, $sync_time) {
    $date_key = wp_date('Y-m-d');
    $option_key = 'tlk_schedule_digest_' . $date_key;
    $log = get_option($option_key, array());

    if (!is_array($log)) {
        $log = array();
    }

    $log[] = array(
        'time'     => (string) $sync_time,
        'rows'     => absint($inserted),
        'added'    => count($changes['added']),
        'removed'  => count($changes['removed']),
        'changed'  => count($changes['changed']),
        'changes'  => $changes,
    );

    update_option($option_key, $log, false);
}

/**
 * Immediate email for a failed sync. Failures are intentionally not delayed
 * until the daily digest because they may require attention right away.
 */
function tlk_send_schedule_sync_failure_email($error_message) {
    $subject = 'TLK Schedule Sync FAILED';
    $message = "The TLK schedule sync failed at " . wp_date('Y-m-d g:i A') . ".\n\n";
    $message .= "Error:\n" . sanitize_textarea_field((string) $error_message) . "\n";

    wp_mail(tlk_schedule_notification_email(), $subject, $message);
}

/**
 * Send one consolidated email containing every successful sync and every
 * schedule change recorded for a calendar day.
 */
function tlk_send_schedule_daily_digest($date_key = '') {
    if ($date_key === '') {
        $date_key = wp_date('Y-m-d');
    }

    $option_key = 'tlk_schedule_digest_' . $date_key;
    $log = get_option($option_key, array());

    if (!is_array($log)) {
        $log = array();
    }

    $timezone = wp_timezone();
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $date_key, $timezone);
    $pretty_date = $date ? $date->format('F j, Y') : $date_key;

    $sync_count = count($log);
    $added_total = 0;
    $removed_total = 0;
    $changed_total = 0;

    foreach ($log as $entry) {
        $added_total += absint($entry['added'] ?? 0);
        $removed_total += absint($entry['removed'] ?? 0);
        $changed_total += absint($entry['changed'] ?? 0);
    }

    $subject = 'TLK Schedule Daily Sync Summary - ' . $pretty_date;
    $message = "TLK SCHEDULE DAILY SYNC SUMMARY\n";
    $message .= $pretty_date . "\n\n";

    $message .= "SUMMARY\n";
    $message .= "-------\n";
    $message .= 'Successful syncs: ' . $sync_count . "\n";
    $message .= 'New POs: ' . $added_total . "\n";
    $message .= 'Removed POs: ' . $removed_total . "\n";
    $message .= 'Changed POs: ' . $changed_total . "\n\n";

    $message .= "SYNC ACTIVITY\n";
    $message .= "-------------\n";

    if (!$log) {
        $message .= "No successful schedule syncs were recorded for this day.\n";
    } else {
        foreach ($log as $entry) {
            $change_count = absint($entry['added'] ?? 0) + absint($entry['removed'] ?? 0) + absint($entry['changed'] ?? 0);
            $message .= sprintf(
                "%s - %d rows - %s\n",
                (string) ($entry['time'] ?? ''),
                absint($entry['rows'] ?? 0),
                $change_count > 0
                    ? sprintf('%d added, %d removed, %d changed', absint($entry['added'] ?? 0), absint($entry['removed'] ?? 0), absint($entry['changed'] ?? 0))
                    : 'No changes'
            );
        }
    }

    if (($added_total + $removed_total + $changed_total) > 0) {
        $message .= "\nCHANGES\n";
        $message .= "-------\n";

        foreach ($log as $entry) {
            $changes = isset($entry['changes']) && is_array($entry['changes']) ? $entry['changes'] : array();
            $entry_change_count = absint($entry['added'] ?? 0) + absint($entry['removed'] ?? 0) + absint($entry['changed'] ?? 0);

            if ($entry_change_count === 0) {
                continue;
            }

            $message .= "\n" . (string) ($entry['time'] ?? '') . "\n";

            foreach ((array) ($changes['added'] ?? array()) as $po => $rows) {
                $message .= "ADDED PO {$po}\n";
                $message .= tlk_schedule_notification_format_po_rows($rows) . "\n";
            }

            foreach ((array) ($changes['removed'] ?? array()) as $po => $rows) {
                $message .= "REMOVED PO {$po}\n";
                $message .= tlk_schedule_notification_format_po_rows($rows) . "\n";
            }

            foreach ((array) ($changes['changed'] ?? array()) as $po => $change) {
                $message .= "CHANGED PO {$po}\n";
                $message .= "Before:\n" . tlk_schedule_notification_format_po_rows($change['old'] ?? array()) . "\n";
                $message .= "After:\n" . tlk_schedule_notification_format_po_rows($change['new'] ?? array()) . "\n";
            }
        }
    } else {
        $message .= "\nNo schedule changes were detected today.\n";
    }

    $sent = wp_mail(tlk_schedule_notification_email(), $subject, $message);

    if ($sent) {
        update_option('tlk_schedule_last_digest_sent', $date_key, false);
        delete_option($option_key);
    }

    return $sent;
}

/**
 * WP-Cron callback for the once-daily digest.
 */
function tlk_schedule_daily_digest_cron_callback() {
    tlk_send_schedule_daily_digest(wp_date('Y-m-d'));
}
add_action('tlk_schedule_daily_digest_event', 'tlk_schedule_daily_digest_cron_callback');

/**
 * Schedule the digest for 5:00 PM in the WordPress site's timezone.
 */
function tlk_schedule_daily_digest_event() {
    if (wp_next_scheduled('tlk_schedule_daily_digest_event')) {
        return;
    }

    $timezone = wp_timezone();
    $now = new DateTimeImmutable('now', $timezone);
    $next = $now->setTime(17, 0, 0);

    if ($next <= $now) {
        $next = $next->modify('+1 day');
    }

    wp_schedule_event($next->getTimestamp(), 'daily', 'tlk_schedule_daily_digest_event');
}
add_action('init', 'tlk_schedule_daily_digest_event');
register_activation_hook(__FILE__, 'tlk_schedule_daily_digest_event');

function tlk_clear_schedule_daily_digest_event() {
    wp_clear_scheduled_hook('tlk_schedule_daily_digest_event');
}
register_deactivation_hook(__FILE__, 'tlk_clear_schedule_daily_digest_event');

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

    // Keep successful hourly syncs quiet and add them to today's digest.
    // One consolidated email is sent daily instead of one email per sync/change.
    tlk_log_schedule_sync_for_daily_digest($inserted, $schedule_changes, $now);

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
/**
 * Get on-time delivery history for an arbitrary reporting range.
 * The shipped date determines which reporting period owns the delivery.
 */
function tlk_get_on_time_delivery_range($start_date, $end_date) {
    global $wpdb;

    if (!tlk_order_history_table_exists()) {
        return array('percent' => null, 'on_time' => 0, 'total' => 0, 'orders' => array());
    }

    $table_name = $wpdb->prefix . 'tlk_order_history';
    $orders = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, po_number, due_date, shipped_date, on_time
             FROM {$table_name}
             WHERE shipped_date >= %s
               AND shipped_date <= %s
             ORDER BY shipped_date DESC, po_number ASC",
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

    return array(
        'percent' => $total > 0 ? ($on_time / $total) * 100 : null,
        'on_time' => $on_time,
        'total'   => $total,
        'orders'  => $orders,
    );
}

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
        tlk_send_schedule_sync_failure_email($result->get_error_message());
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

/* Select active employees from the dedicated roster. */
function tlk_select_employee() {
    $rows = tlk_get_employee_roster(true);
    return array_map(static function ($row) {
        return $row->employee_name;
    }, $rows);
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
        'brianj@tlkprecision.com',
        'todd@tlkprecision.com',
        'deric@tlkprecision.com',
    );

    if (!empty($user->user_email)) {
        $email = strtolower((string) $user->user_email);

        // Brian gets a one-time destination chooser immediately after login.
        // Choosing Production Dashboard removes the query string and loads the
        // normal dashboard, so there is no redirect loop.
        if ($email === 'brian@tlkprecision.com') {
            return add_query_arg(
                'tlk_choose_destination',
                '1',
                home_url('/tlk-production-dashboard/')
            );
        }

        if (in_array($email, $allowed_emails, true)) {
            return home_url('/tlk-production-dashboard/');
        }
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
 * Count required production days in an inclusive range.
 * Monday-Thursday always count. Friday counts only when the department
 * has at least one production entry that day. Saturday/Sunday never count.
 */
function tlk_count_production_days($department, $start_date, $end_date) {
    global $wpdb;

    $tz = wp_timezone();
    $start = new DateTimeImmutable($start_date, $tz);
    $end = new DateTimeImmutable($end_date, $tz);
    if ($start > $end) return 0;

    $production = $wpdb->prefix . 'tlk_production';
    $worked_fridays = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT DATE(entry_date)
         FROM {$production}
         WHERE department = %s
           AND entry_date BETWEEN %s AND %s
           AND WEEKDAY(entry_date) = 4",
        $department,
        $start_date . ' 00:00:00',
        $end_date . ' 23:59:59'
    ));
    $worked_fridays = array_fill_keys($worked_fridays, true);

    $count = 0;
    for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
        $day_number = (int) $day->format('N');
        if ($day_number >= 1 && $day_number <= 4) {
            $count++;
        } elseif ($day_number === 5 && isset($worked_fridays[$day->format('Y-m-d')])) {
            $count++;
        }
    }
    return $count;
}

/**
 * Frontend production metric for the current month.
 *
 * The department goal is a PER-PERSON daily goal. A person-day is counted when
 * an employee has production recorded for that department on that weekday.
 * Department performance is therefore total parts / total active person-days.
 *
 * Example: Monday Joe 30 + Bob 30 = 60 parts across 2 person-days = 30/person.
 * If the per-person goal is 60, that day is at 50% of goal.
 */
function tlk_get_department_daily_average($department, $year = null, $month = null) {
    global $wpdb;

    $year  = $year ?: (int) wp_date('Y');
    $month = $month ?: (int) wp_date('n');
    $table_name = $wpdb->prefix . 'tlk_production';
    $tz = wp_timezone();

    $month_start = sprintf('%04d-%02d-01', $year, $month);
    $month_end_obj = (new DateTimeImmutable($month_start, $tz))->modify('last day of this month');
    $today_obj = new DateTimeImmutable(wp_date('Y-m-d'), $tz);
    $range_end_obj = $month_end_obj < $today_obj ? $month_end_obj : $today_obj;
    $range_end = $range_end_obj->format('Y-m-d');

    $stats = tlk_get_department_person_day_stats($department, $month_start, $range_end);
    return $stats['person_days'] > 0 ? $stats['attributable_parts'] / $stats['person_days'] : 0;
}

/**
 * Return total production and active employee-days for a department/range.
 * Multiple entries by the same employee on the same date count as one person-day.
 */
function tlk_get_department_person_day_stats($department, $start_date, $end_date) {
    global $wpdb;
    $production = $wpdb->prefix . 'tlk_production';

    $historical = tlk_historical_unassigned_employee();
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT COALESCE(SUM(CAST(qty AS DECIMAL(10,2))), 0) AS total_parts,
                COALESCE(SUM(CASE WHEN employee != %s THEN CAST(qty AS DECIMAL(10,2)) ELSE 0 END), 0) AS attributable_parts,
                COALESCE(SUM(CASE WHEN employee = %s THEN CAST(qty AS DECIMAL(10,2)) ELSE 0 END), 0) AS historical_parts,
                COUNT(DISTINCT CASE WHEN employee != %s AND employee IS NOT NULL AND employee != ''
                    THEN CONCAT(DATE(entry_date), '|', employee) END) AS person_days
         FROM {$production}
         WHERE department = %s
           AND entry_date BETWEEN %s AND %s
           AND WEEKDAY(entry_date) BETWEEN 0 AND 4",
        $historical,
        $historical,
        $historical,
        $department,
        $start_date . ' 00:00:00',
        $end_date . ' 23:59:59'
    ), ARRAY_A);

    return array(
        'total_parts'       => isset($row['total_parts']) ? (float) $row['total_parts'] : 0,
        'attributable_parts'=> isset($row['attributable_parts']) ? (float) $row['attributable_parts'] : 0,
        'historical_parts'  => isset($row['historical_parts']) ? (float) $row['historical_parts'] : 0,
        'person_days'       => isset($row['person_days']) ? (int) $row['person_days'] : 0,
    );
}

/**
 * Department daily totals for a selected reporting range (weekdays only).
 */
function tlk_get_department_daily_totals_range($department, $start_date, $end_date) {
    global $wpdb;
    $production = $wpdb->prefix . 'tlk_production';

    return $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(entry_date) AS production_date,
                SUM(CAST(qty AS DECIMAL(10,2))) AS produced,
                SUM(CASE WHEN employee != 'Historical / Unassigned' THEN CAST(qty AS DECIMAL(10,2)) ELSE 0 END) AS attributable_produced,
                SUM(CASE WHEN employee = 'Historical / Unassigned' THEN CAST(qty AS DECIMAL(10,2)) ELSE 0 END) AS historical_produced
         FROM {$production}
         WHERE department = %s
           AND entry_date BETWEEN %s AND %s
           AND WEEKDAY(entry_date) BETWEEN 0 AND 4
         GROUP BY DATE(entry_date)
         ORDER BY production_date DESC",
        $department,
        $start_date . ' 00:00:00',
        $end_date . ' 23:59:59'
    ), ARRAY_A);
}

function tlk_department_quota($department) {
    $daily_target = tlk_get_department_target($department);
    $average = tlk_get_department_daily_average($department);

    return array(
        'total' => $average,
        'met'   => $daily_target > 0 && $average >= $daily_target,
    );
}

/* CNC average production per active employee-day this month */
function cnc_quota() {
    return tlk_department_quota('CNC');
}

/* Pouring average production per active employee-day this month */
function pouring_quota() {
    return tlk_department_quota('Pouring');
}

/* Building average production per active employee-day this month */
function building_quota() {
    return tlk_department_quota('Building');
}
/**
 * Department-level PER-PERSON DAILY goals used by the frontend production metric.
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

/**
 * Departments that should be visible in the public frontend statistics area.
 * This only controls display; it does not remove production history or disable entry logging.
 */
function tlk_get_visible_frontend_departments() {
    $all_departments = array('CNC', 'Pouring', 'Building');
    $saved = get_option('tlk_visible_frontend_departments', null);

    // Existing installs default to showing every department until the setting is saved.
    if (!is_array($saved)) {
        return $all_departments;
    }

    return array_values(array_intersect($all_departments, $saved));
}

function tlk_department_is_visible_on_frontend($department) {
    return in_array($department, tlk_get_visible_frontend_departments(), true);
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

    $posted_visible = isset($_POST['visible_departments'])
        ? (array) wp_unslash($_POST['visible_departments'])
        : array();
    $visible_departments = array_values(array_intersect($departments, array_map('sanitize_text_field', $posted_visible)));
    update_option('tlk_visible_frontend_departments', $visible_departments, false);

    wp_safe_redirect(add_query_arg(array(
        'page' => 'tlk-employee-performance',
        'department_targets_saved' => '1',
    ), admin_url('admin.php')));
    exit;
}
add_action('admin_post_tlk_save_department_targets', 'tlk_save_department_targets');

/**
 * Individual employee production targets + private performance reporting.
 * Individual employee DAILY production targets + private performance reporting.
 */
function tlk_create_employee_targets_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tlk_employee_targets';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        employee VARCHAR(100) NOT NULL,
        department VARCHAR(50) NOT NULL,
        daily_target DECIMAL(10,2) NOT NULL DEFAULT 60,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY employee_department (employee, department)
    ) {$charset_collate};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'tlk_create_employee_targets_table');

function tlk_maybe_upgrade_employee_targets_table() {
    if (get_option('tlk_employee_targets_db_version') === '1.1.0') return;
    tlk_create_employee_targets_table();
    update_option('tlk_employee_targets_db_version', '1.1.0');
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
        "SELECT daily_target FROM {$table} WHERE employee = %s AND department = %s LIMIT 1",
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
           AND employee != 'Historical / Unassigned'
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

    $average = tlk_get_department_daily_average($department, $year, $month);
    $daily_target = tlk_get_department_target($department);
    $percent = $daily_target > 0 ? ($average / $daily_target) * 100 : 0;

    return array(
        'average_parts' => $average,
        'percent' => $percent,
        'met' => $daily_target > 0 && $average >= $daily_target,
        'employee_count' => 0,
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

/**
 * On this admin screen only: drop other plugins' notices so production
 * numbers are the first thing on the page.
 */
function tlk_employee_performance_clean_admin_notices() {
    remove_all_actions('admin_notices');
    remove_all_actions('all_admin_notices');
    remove_all_actions('network_admin_notices');
    add_action('admin_notices', 'tlk_employee_performance_own_notices');
}
add_action('load-toplevel_page_tlk-employee-performance', 'tlk_employee_performance_clean_admin_notices');

function tlk_employee_performance_own_notices() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'tlk-employee-performance') {
        return;
    }
    if (isset($_GET['department_targets_saved'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Department targets saved.</p></div>';
    }
    if (isset($_GET['targets_saved'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Employee targets saved.</p></div>';
    }
    if (isset($_GET['delivery_saved']) && $_GET['delivery_saved'] !== '0') {
        echo '<div class="notice notice-success is-dismissible"><p>Delivery history ' . esc_html($_GET['delivery_saved'] === 'updated' ? 'updated' : 'added') . '.</p></div>';
    }
    if (isset($_GET['delivery_deleted'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Delivery history entry deleted.</p></div>';
    }
    if (isset($_GET['delivery_error'])) {
        $message = $_GET['delivery_error'] === 'duplicate' ? 'That PO / order number is already in delivery history.' : 'Enter a PO / order number and valid due and shipped dates.';
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}

function tlk_performance_percent_class($percent) {
    if ($percent >= 100) {
        return 'tlk-perf-good';
    }
    if ($percent >= 80) {
        return 'tlk-perf-ok';
    }
    return 'tlk-perf-low';
}

/**
 * Preserve the Employee Performance reporting range after delivery-history actions.
 */
function tlk_delivery_history_redirect_args() {
    $args = array('page' => 'tlk-employee-performance');
    $range = isset($_POST['return_range']) ? sanitize_key(wp_unslash($_POST['return_range'])) : 'this_month';
    $allowed = array('today', 'this_week', 'last_week', 'this_month', 'last_month', 'custom');
    $args['range'] = in_array($range, $allowed, true) ? $range : 'this_month';

    if ($args['range'] === 'custom') {
        foreach (array('start_date', 'end_date') as $key) {
            $value = isset($_POST['return_' . $key]) ? sanitize_text_field(wp_unslash($_POST['return_' . $key])) : '';
            if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) {
                $args[$key] = $value;
            }
        }
    }
    return $args;
}

function tlk_save_delivery_history() {
    if (!tlk_can_manage_employee_performance()) {
        wp_die('You are not allowed to manage delivery history.');
    }
    check_admin_referer('tlk_save_delivery_history');

    global $wpdb;
    tlk_order_history_table_exists();
    $table = $wpdb->prefix . 'tlk_order_history';

    $id = isset($_POST['history_id']) ? absint($_POST['history_id']) : 0;
    $po = isset($_POST['po_number']) ? sanitize_text_field(wp_unslash($_POST['po_number'])) : '';
    $due = isset($_POST['due_date']) ? sanitize_text_field(wp_unslash($_POST['due_date'])) : '';
    $shipped = isset($_POST['shipped_date']) ? sanitize_text_field(wp_unslash($_POST['shipped_date'])) : '';

    $valid_date = static function($date) {
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)) return false;
        $dt = DateTime::createFromFormat('!Y-m-d', $date);
        return $dt && $dt->format('Y-m-d') === $date;
    };

    $redirect = tlk_delivery_history_redirect_args();
    if ($po === '' || !$valid_date($due) || !$valid_date($shipped)) {
        $redirect['delivery_error'] = 'invalid';
        wp_safe_redirect(add_query_arg($redirect, admin_url('admin.php')));
        exit;
    }

    $duplicate = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE po_number = %s AND id != %d LIMIT 1",
        $po, $id
    ));
    if ($duplicate) {
        $redirect['delivery_error'] = 'duplicate';
        wp_safe_redirect(add_query_arg($redirect, admin_url('admin.php')));
        exit;
    }

    $data = array(
        'po_number'    => $po,
        'due_date'     => $due,
        'shipped_date' => $shipped,
        'on_time'      => ($shipped <= $due) ? 1 : 0,
    );

    if ($id) {
        $result = $wpdb->update($table, $data, array('id' => $id), array('%s','%s','%s','%d'), array('%d'));
        $redirect['delivery_saved'] = $result === false ? '0' : 'updated';
    } else {
        $result = $wpdb->insert($table, $data, array('%s','%s','%s','%d'));
        $redirect['delivery_saved'] = $result === false ? '0' : 'added';
    }

    wp_safe_redirect(add_query_arg($redirect, admin_url('admin.php')));
    exit;
}
add_action('admin_post_tlk_save_delivery_history', 'tlk_save_delivery_history');

function tlk_delete_delivery_history() {
    if (!tlk_can_manage_employee_performance()) {
        wp_die('You are not allowed to manage delivery history.');
    }
    check_admin_referer('tlk_delete_delivery_history');

    global $wpdb;
    $id = isset($_POST['history_id']) ? absint($_POST['history_id']) : 0;
    if ($id) {
        $wpdb->delete($wpdb->prefix . 'tlk_order_history', array('id' => $id), array('%d'));
    }

    $redirect = tlk_delivery_history_redirect_args();
    $redirect['delivery_deleted'] = '1';
    wp_safe_redirect(add_query_arg($redirect, admin_url('admin.php')));
    exit;
}
add_action('admin_post_tlk_delete_delivery_history', 'tlk_delete_delivery_history');

/**
 * Export the on-time delivery history for the currently selected reporting range.
 */
function tlk_export_delivery_history_csv() {
    if (!tlk_can_manage_employee_performance()) {
        wp_die('You are not allowed to export delivery history.');
    }
    check_admin_referer('tlk_export_delivery_history_csv');

    $period = tlk_employee_performance_period();
    $stats  = tlk_get_on_time_delivery_range($period['start'], $period['end']);

    $filename = sprintf(
        'tlk-on-time-delivery-%s-to-%s.csv',
        sanitize_file_name($period['start']),
        sanitize_file_name($period['end'])
    );

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        wp_die('Unable to create CSV export.');
    }

    // UTF-8 BOM helps Excel open the file cleanly.
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, array('Reporting Range', $period['label']));
    fputcsv($output, array('Start Date', $period['start']));
    fputcsv($output, array('End Date', $period['end']));
    fputcsv($output, array('Total Deliveries', (int) $stats['total']));
    fputcsv($output, array('On-Time Deliveries', (int) $stats['on_time']));
    fputcsv($output, array('On-Time Percentage', $stats['total'] > 0 ? number_format((float) $stats['percent'], 1) . '%' : 'N/A'));
    fputcsv($output, array());
    fputcsv($output, array('PO / Order Number', 'Due Date', 'Shipped Date', 'Status'));

    foreach ($stats['orders'] as $delivery) {
        fputcsv($output, array(
            $delivery['po_number'],
            $delivery['due_date'],
            $delivery['shipped_date'],
            ((int) $delivery['on_time'] === 1) ? 'On Time' : 'Late',
        ));
    }

    fclose($output);
    exit;
}
add_action('admin_post_tlk_export_delivery_history_csv', 'tlk_export_delivery_history_csv');

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
            'daily_target' => $target,
            'updated_at' => current_time('mysql'),
        ), array('%s','%s','%f','%s'));
    }
    wp_safe_redirect(add_query_arg(array('page'=>'tlk-employee-performance','targets_saved'=>'1'), admin_url('admin.php')));
    exit;
}
add_action('admin_post_tlk_save_employee_targets', 'tlk_save_employee_targets');

function tlk_get_employee_performance_range($department, $start_date, $end_date) {
    global $wpdb;
    $production = $wpdb->prefix . 'tlk_production';
    $start = $start_date . ' 00:00:00';
    $end   = $end_date . ' 23:59:59';

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT employee,
                SUM(CAST(qty AS DECIMAL(10,2))) AS produced,
                COUNT(DISTINCT DATE(entry_date)) AS active_days
         FROM {$production}
         WHERE department = %s
           AND entry_date BETWEEN %s AND %s
           AND employee IS NOT NULL AND employee != ''
           AND employee != 'Historical / Unassigned'
         GROUP BY employee
         ORDER BY employee ASC",
        $department, $start, $end
    ), ARRAY_A);

    foreach ($rows as &$row) {
        $row['produced'] = (float) $row['produced'];
        $row['active_days'] = (int) $row['active_days'];
        $row['daily_average'] = $row['active_days'] > 0 ? $row['produced'] / $row['active_days'] : 0;
        $row['target'] = tlk_get_employee_target($row['employee'], $department, 60);
        $row['workdays'] = $row['active_days'];
        $row['expected'] = $row['target'] * $row['active_days'];
        $row['percent'] = $row['expected'] > 0 ? ($row['produced'] / $row['expected']) * 100 : 0;
    }
    unset($row);
    return $rows;
}

function tlk_get_production_daily_breakdown($department, $start_date, $end_date) {
    global $wpdb;
    $production = $wpdb->prefix . 'tlk_production';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(entry_date) AS production_date, employee,
                SUM(CAST(qty AS DECIMAL(10,2))) AS produced
         FROM {$production}
         WHERE department = %s
           AND entry_date BETWEEN %s AND %s
           AND employee IS NOT NULL AND employee != ''
           AND employee != 'Historical / Unassigned'
         GROUP BY DATE(entry_date), employee
         ORDER BY production_date DESC, employee ASC",
        $department, $start_date . ' 00:00:00', $end_date . ' 23:59:59'
    ), ARRAY_A);
}

function tlk_get_production_weekly_breakdown($department, $start_date, $end_date) {
    global $wpdb;
    $production = $wpdb->prefix . 'tlk_production';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT YEARWEEK(entry_date, 1) AS year_week,
                DATE_SUB(DATE(entry_date), INTERVAL WEEKDAY(entry_date) DAY) AS week_start,
                employee,
                SUM(CAST(qty AS DECIMAL(10,2))) AS produced
         FROM {$production}
         WHERE department = %s
           AND entry_date BETWEEN %s AND %s
           AND employee IS NOT NULL AND employee != ''
           AND employee != 'Historical / Unassigned'
         GROUP BY YEARWEEK(entry_date, 1), week_start, employee
         ORDER BY week_start DESC, employee ASC",
        $department, $start_date . ' 00:00:00', $end_date . ' 23:59:59'
    ), ARRAY_A);
}

function tlk_employee_performance_period() {
    $today = wp_date('Y-m-d');
    $preset = isset($_GET['range']) ? sanitize_key(wp_unslash($_GET['range'])) : 'this_month';
    $tz = wp_timezone();
    $now = new DateTimeImmutable('now', $tz);

    switch ($preset) {
        case 'today':
            $start = $end = $today;
            $label = 'Today';
            break;
        case 'this_week':
            $start = $now->modify('monday this week')->format('Y-m-d');
            $end = $today;
            $label = 'This Week';
            break;
        case 'last_week':
            $start = $now->modify('monday last week')->format('Y-m-d');
            $end = $now->modify('sunday last week')->format('Y-m-d');
            $label = 'Last Week';
            break;
        case 'last_month':
            $first_last = $now->modify('first day of last month');
            $start = $first_last->format('Y-m-d');
            $end = $first_last->modify('last day of this month')->format('Y-m-d');
            $label = $first_last->format('F Y');
            break;
        case 'custom':
            $start = isset($_GET['start_date']) ? sanitize_text_field(wp_unslash($_GET['start_date'])) : $today;
            $end = isset($_GET['end_date']) ? sanitize_text_field(wp_unslash($_GET['end_date'])) : $today;
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $start)) $start = $today;
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $end)) $end = $today;
            if ($start > $end) { $tmp = $start; $start = $end; $end = $tmp; }
            $label = wp_date('M j, Y', strtotime($start)) . ' – ' . wp_date('M j, Y', strtotime($end));
            break;
        case 'this_month':
        default:
            $preset = 'this_month';
            $start = $now->modify('first day of this month')->format('Y-m-d');
            $end = $today;
            $label = $now->format('F Y') . ' to date';
            break;
    }

    return array('preset'=>$preset, 'start'=>$start, 'end'=>$end, 'label'=>$label);
}

function tlk_render_employee_performance_page() {
    if (!tlk_can_manage_employee_performance()) wp_die('You are not allowed to view employee performance.');
    $departments = array('CNC','Pouring','Building');
    $period = tlk_employee_performance_period();
    $department_targets = tlk_get_department_targets();
    $visible_frontend_departments = tlk_get_visible_frontend_departments();
    $delivery_stats = tlk_get_on_time_delivery_range($period['start'], $period['end']);
    $edit_delivery = null;
    if (isset($_GET['edit_delivery'])) {
        global $wpdb;
        $edit_id = absint($_GET['edit_delivery']);
        if ($edit_id) {
            $edit_delivery = $wpdb->get_row($wpdb->prepare(
                "SELECT id, po_number, due_date, shipped_date FROM {$wpdb->prefix}tlk_order_history WHERE id = %d",
                $edit_id
            ), ARRAY_A);
        }
    }
    ?>
    <style>
        .tlk-perf-wrap { max-width: 1180px; }
        .tlk-perf-lede { color: #50575e; max-width: 820px; }
        .tlk-perf-cards { display: flex; gap: 14px; flex-wrap: wrap; margin: 18px 0 8px; }
        .tlk-perf-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 14px 16px; min-width: 240px; flex: 1; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
        .tlk-perf-card h2 { margin: 0 0 10px; font-size: 15px; }
        .tlk-perf-card .tlk-perf-total { font-size: 28px; line-height: 1.1; font-weight: 600; }
        .tlk-perf-card .tlk-perf-total span { font-size: 13px; font-weight: 500; color: #646970; }
        .tlk-perf-meta { margin: 8px 0 0; color: #50575e; font-size: 13px; }
        .tlk-perf-good { color: #007017; font-weight: 600; }
        .tlk-perf-ok { color: #9a6700; font-weight: 600; }
        .tlk-perf-low { color: #b32d2e; font-weight: 600; }
        .tlk-perf-section { margin-top: 28px; }
        .tlk-perf-section table { width: 100%; }
        .tlk-perf-settings { margin-top: 36px; }
    </style>
    <div class="wrap tlk-perf-wrap">
        <h1>Employee Performance</h1>
        <p class="tlk-perf-lede">Period totals versus expected output using a per-person daily goal. Each employee counts once for each weekday where they recorded production; weekends never count. <a href="#tlk-counting-rules">View settings</a></p>
        <p><a href="/tlk-production-dashboard">Go To Dashboard Overview</a></p>

        <div class="card" style="margin:18px 0;padding:18px 22px;">
            <h2 style="margin-top:0;">Reporting Range</h2>
            <form method="get" id="tlk-performance-range" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                <input type="hidden" name="page" value="tlk-employee-performance">
                <label><strong>Range</strong><br>
                    <select name="range" id="tlk-range-select">
                        <?php foreach (array('today'=>'Today','this_week'=>'This Week','last_week'=>'Last Week','this_month'=>'This Month','last_month'=>'Last Month','custom'=>'Custom Range') as $value=>$text) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($period['preset'], $value); ?>><?php echo esc_html($text); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="tlk-custom-date"><strong>Start</strong><br><input type="date" name="start_date" value="<?php echo esc_attr($period['start']); ?>"></label>
                <label class="tlk-custom-date"><strong>End</strong><br><input type="date" name="end_date" value="<?php echo esc_attr($period['end']); ?>"></label>
                <button class="button button-primary">View</button>
            </form>
            <p style="margin-bottom:0;"><strong>Showing:</strong> <?php echo esc_html($period['label']); ?></p>
        </div>

        <div class="card" style="margin:18px 0;padding:18px 22px;max-width:none;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                <h2 style="margin:0;">On-Time Delivery History</h2>
                <form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                    <input type="hidden" name="action" value="tlk_export_delivery_history_csv">
                    <input type="hidden" name="range" value="<?php echo esc_attr($period['preset']); ?>">
                    <?php if ($period['preset'] === 'custom') : ?>
                        <input type="hidden" name="start_date" value="<?php echo esc_attr($period['start']); ?>">
                        <input type="hidden" name="end_date" value="<?php echo esc_attr($period['end']); ?>">
                    <?php endif; ?>
                    <?php wp_nonce_field('tlk_export_delivery_history_csv'); ?>
                    <button type="submit" class="button">Export CSV</button>
                </form>
            </div>
            <p style="color:#50575e;">Uses the Reporting Range above and groups deliveries by <strong>shipped date</strong>. Entries are automatically marked on time when the shipped date is on or before the due date.</p>

            <div style="display:flex;gap:28px;align-items:flex-start;flex-wrap:wrap;margin:18px 0 22px;">
                <div style="min-width:220px;">
                    <div style="font-size:13px;color:#646970;font-weight:600;">ON-TIME DELIVERY</div>
                    <?php if ($delivery_stats['total'] > 0) : ?>
                        <div style="font-size:34px;line-height:1.2;font-weight:600;margin-top:3px;"><?php echo esc_html(number_format_i18n($delivery_stats['percent'], 1)); ?>%</div>
                        <div style="color:#50575e;"><?php echo esc_html(number_format_i18n($delivery_stats['on_time'])); ?> of <?php echo esc_html(number_format_i18n($delivery_stats['total'])); ?> deliveries on time</div>
                    <?php else : ?>
                        <div style="font-size:34px;line-height:1.2;font-weight:600;color:#646970;margin-top:3px;">N/A</div>
                        <div style="color:#50575e;">No deliveries in this reporting range.</div>
                    <?php endif; ?>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;flex:1;">
                    <input type="hidden" name="action" value="tlk_save_delivery_history">
                    <input type="hidden" name="history_id" value="<?php echo esc_attr($edit_delivery['id'] ?? 0); ?>">
                    <input type="hidden" name="return_range" value="<?php echo esc_attr($period['preset']); ?>">
                    <input type="hidden" name="return_start_date" value="<?php echo esc_attr($period['start']); ?>">
                    <input type="hidden" name="return_end_date" value="<?php echo esc_attr($period['end']); ?>">
                    <?php wp_nonce_field('tlk_save_delivery_history'); ?>
                    <label><strong>PO / Order Number</strong><br><input type="text" name="po_number" required value="<?php echo esc_attr($edit_delivery['po_number'] ?? ''); ?>"></label>
                    <label><strong>Due Date</strong><br><input type="date" name="due_date" required value="<?php echo esc_attr($edit_delivery['due_date'] ?? ''); ?>"></label>
                    <label><strong>Shipped Date</strong><br><input type="date" name="shipped_date" required value="<?php echo esc_attr($edit_delivery['shipped_date'] ?? ''); ?>"></label>
                    <button type="submit" class="button button-primary"><?php echo $edit_delivery ? 'Update Delivery' : 'Add Delivery'; ?></button>
                    <?php if ($edit_delivery) : ?>
                        <a class="button" href="<?php echo esc_url(add_query_arg(array_filter(array('page'=>'tlk-employee-performance','range'=>$period['preset'],'start_date'=>$period['preset']==='custom'?$period['start']:null,'end_date'=>$period['preset']==='custom'?$period['end']:null)), admin_url('admin.php'))); ?>">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($delivery_stats['orders']) : ?>
                <table class="widefat striped">
                    <thead><tr><th>PO / Order</th><th>Due Date</th><th>Shipped Date</th><th>Status</th><th style="width:150px;">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($delivery_stats['orders'] as $delivery) :
                        $edit_args = array('page'=>'tlk-employee-performance','range'=>$period['preset'],'edit_delivery'=>(int)$delivery['id']);
                        if ($period['preset'] === 'custom') { $edit_args['start_date']=$period['start']; $edit_args['end_date']=$period['end']; }
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($delivery['po_number']); ?></strong></td>
                            <td><?php echo !empty($delivery['due_date']) ? esc_html(wp_date('M j, Y', strtotime($delivery['due_date']))) : '&mdash;'; ?></td>
                            <td><?php echo esc_html(wp_date('M j, Y', strtotime($delivery['shipped_date']))); ?></td>
                            <td><?php if ((int)$delivery['on_time'] === 1) : ?><span style="color:#007017;font-weight:600;">On Time</span><?php else : ?><span style="color:#b32d2e;font-weight:600;">Late</span><?php endif; ?></td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url(add_query_arg($edit_args, admin_url('admin.php'))); ?>">Edit</a>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('Delete this delivery history entry?');">
                                    <input type="hidden" name="action" value="tlk_delete_delivery_history">
                                    <input type="hidden" name="history_id" value="<?php echo esc_attr($delivery['id']); ?>">
                                    <input type="hidden" name="return_range" value="<?php echo esc_attr($period['preset']); ?>">
                                    <input type="hidden" name="return_start_date" value="<?php echo esc_attr($period['start']); ?>">
                                    <input type="hidden" name="return_end_date" value="<?php echo esc_attr($period['end']); ?>">
                                    <?php wp_nonce_field('tlk_delete_delivery_history'); ?>
                                    <button type="submit" class="button button-small">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p style="margin-bottom:0;color:#646970;">No delivery history entries were shipped during <?php echo esc_html($period['label']); ?>.</p>
            <?php endif; ?>
        </div>

        <div class="tlk-perf-cards">
        <?php
        $department_summaries = array();
        foreach ($departments as $department) {
            $rows = tlk_get_employee_performance_range($department, $period['start'], $period['end']);
            $daily = tlk_get_production_daily_breakdown($department, $period['start'], $period['end']);
            $weekly = tlk_get_production_weekly_breakdown($department, $period['start'], $period['end']);
            $department_days = tlk_get_department_daily_totals_range($department, $period['start'], $period['end']);
            $person_day_stats = tlk_get_department_person_day_stats($department, $period['start'], $period['end']);
            $dept_total = $person_day_stats['total_parts'];
            $attributable_parts = $person_day_stats['attributable_parts'];
            $historical_parts = $person_day_stats['historical_parts'];
            $person_days = $person_day_stats['person_days'];
            $counted_days = tlk_count_production_days($department, $period['start'], $period['end']);
            $recorded_days = count($department_days);
            $dept_goal = tlk_get_department_target($department);
            $expected_total = $dept_goal * $person_days;
            $dept_daily_avg = $person_days ? $attributable_parts / $person_days : 0;
            $dept_daily_avg_rounded = (int) round($dept_daily_avg);
            $pct_expected = $expected_total > 0 ? ($attributable_parts / $expected_total) * 100 : 0;
            $pct_daily = $dept_goal > 0 ? ($dept_daily_avg / $dept_goal) * 100 : 0;
            $department_summaries[$department] = compact('rows','daily','weekly','department_days','dept_total','attributable_parts','historical_parts','person_days','counted_days','recorded_days','dept_goal','expected_total','dept_daily_avg','pct_expected','pct_daily');
            $pct_class = tlk_performance_percent_class($pct_expected);
            ?>
            <div class="tlk-perf-card">
                <h2><?php echo esc_html($department); ?><?php if (!tlk_department_is_visible_on_frontend($department)) : ?> <span style="font-weight:400;color:#646970;">(hidden on frontend)</span><?php endif; ?></h2>
                <div class="tlk-perf-total"><?php echo esc_html(number_format_i18n($dept_total)); ?> <span>parts this period</span></div>
                <?php if ($historical_parts > 0) : ?>
                    <p style="margin:4px 0 8px;color:#646970;"><strong><?php echo esc_html(number_format_i18n($historical_parts)); ?></strong> historical/unassigned parts are included in the total above but excluded from per-person performance.</p>
                <?php endif; ?>
                <p class="tlk-perf-meta">
                    Expected: <strong><?php echo esc_html(number_format_i18n($expected_total)); ?></strong>
                    (<?php echo esc_html(number_format_i18n($dept_goal)); ?>/person/day × <?php echo esc_html(number_format_i18n($person_days)); ?> active person-days)<br>
                    Period vs expected: <span class="<?php echo esc_attr($pct_class); ?>"><?php echo esc_html(number_format_i18n($pct_expected, 1)); ?>%</span><br>
                    Avg parts per person/day (Assigned Parts / Active Person-Days): <strong><?php echo esc_html(number_format_i18n($dept_daily_avg_rounded)); ?></strong>
                    · Per-person daily goal: <strong><?php echo esc_html(number_format_i18n($dept_goal)); ?></strong><br>
                    <span style="color:#646970;">Exact average: <?php echo esc_html(number_format_i18n($dept_daily_avg, 2)); ?></span>
                </p>
            </div>
        <?php } ?>
        </div>

        <?php foreach ($departments as $department):
                $summary = $department_summaries[$department];
                $rows = $summary['rows'];
                $daily = $summary['daily'];
                $weekly = $summary['weekly'];
                $department_days = $summary['department_days'];
                $dept_goal = $summary['dept_goal'];
                ?>
                <div class="tlk-perf-section">
                <h2><?php echo esc_html($department); ?></h2>
                <?php if (!$rows): ?><p>No production entries for this department in this period.</p><?php else: ?>
                <table class="widefat striped">
                    <thead><tr><th>Employee</th><th>Period Total</th><th>Days With Output</th><th>Avg / Active Day</th></tr></thead>
                    <tbody><?php foreach ($rows as $row): ?>
                        <tr>
                            <td><strong><?php echo esc_html($row['employee']); ?></strong></td>
                            <td><strong><?php echo esc_html(number_format_i18n($row['produced'])); ?></strong></td>
                            <td><?php echo esc_html(number_format_i18n($row['active_days'])); ?></td>
                            <td><?php echo esc_html(number_format_i18n($row['daily_average'], 1)); ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table>

                <details style="margin-top:14px;">
                    <summary style="cursor:pointer;font-weight:600;">Department Daily Totals</summary>
                    <table class="widefat striped" style="margin-top:10px;">
                        <thead><tr><th>Date</th><th>Department Total</th><th>Historical / Unassigned</th><th>Active Employees</th><th>Expected</th><th>% of Goal</th></tr></thead>
                        <tbody><?php foreach ($department_days as $item):
                            $day_total = (float) $item['produced'];
                            $day_attributable = (float) $item['attributable_produced'];
                            $day_historical = (float) $item['historical_produced'];
                            $day_employees = array();
                            foreach ($daily as $daily_item) {
                                if ($daily_item['production_date'] === $item['production_date'] && !empty($daily_item['employee'])) {
                                    $day_employees[$daily_item['employee']] = true;
                                }
                            }
                            $day_employee_count = count($day_employees);
                            $day_expected = $dept_goal * $day_employee_count;
                            $day_percent = $day_expected > 0 ? ($day_attributable / $day_expected) * 100 : 0;
                        ?><tr>
                            <td><?php echo esc_html(wp_date('D, M j, Y', strtotime($item['production_date']))); ?></td>
                            <td><?php echo esc_html(number_format_i18n($day_total)); ?></td>
                            <td><?php echo $day_historical > 0 ? esc_html(number_format_i18n($day_historical)) : '&mdash;'; ?></td>
                            <td><?php echo esc_html(number_format_i18n($day_employee_count)); ?></td>
                            <td><?php echo esc_html(number_format_i18n($day_expected)); ?></td>
                            <td><?php echo esc_html(number_format_i18n($day_percent, 1)); ?>%</td>
                        </tr><?php endforeach; ?></tbody>
                    </table>
                </details>

                <details style="margin-top:14px;">
                    <summary style="cursor:pointer;font-weight:600;">Weekly Output</summary>
                    <table class="widefat striped" style="margin-top:10px;">
                        <thead><tr><th>Week Starting</th><th>Employee</th><th>Produced</th></tr></thead>
                        <tbody><?php foreach ($weekly as $item): ?><tr>
                            <td><?php echo esc_html(wp_date('M j, Y', strtotime($item['week_start']))); ?></td>
                            <td><?php echo esc_html($item['employee']); ?></td>
                            <td><?php echo esc_html(number_format_i18n((float)$item['produced'])); ?></td>
                        </tr><?php endforeach; ?></tbody>
                    </table>
                </details>

                <details style="margin-top:10px;">
                    <summary style="cursor:pointer;font-weight:600;">Daily Output by Employee</summary>
                    <table class="widefat striped" style="margin-top:10px;">
                        <thead><tr><th>Date</th><th>Employee</th><th>Produced</th></tr></thead>
                        <tbody><?php foreach ($daily as $item): ?><tr>
                            <td><?php echo esc_html(wp_date('D, M j, Y', strtotime($item['production_date']))); ?></td>
                            <td><?php echo esc_html($item['employee']); ?></td>
                            <td><?php echo esc_html(number_format_i18n((float)$item['produced'])); ?></td>
                        </tr><?php endforeach; ?></tbody>
                    </table>
                </details>
                <?php endif; ?>
                </div>
            <?php endforeach; ?>

        <details id="tlk-counting-rules" class="card tlk-perf-settings" style="padding:18px 22px;">
            <summary style="cursor:pointer;font-weight:600;">Settings &amp; Counting Rules</summary>
            <p>Set each department's per-person daily goal and choose whether its production statistics card is shown on the frontend. Hiding a department does not delete its production history or prevent new production entries.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tlk_save_department_targets">
                <?php wp_nonce_field('tlk_save_department_targets'); ?>
                <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end;">
                    <?php foreach ($departments as $target_department) : ?>
                        <div style="min-width:190px;">
                            <label><strong><?php echo esc_html($target_department); ?> Per-Person Daily Goal</strong><br>
                                <input type="number" min="0" step="1" name="department_targets[<?php echo esc_attr($target_department); ?>]" value="<?php echo esc_attr($department_targets[$target_department]); ?>" style="width:120px;">
                            </label>
                            <label style="display:block;margin-top:10px;">
                                <input type="checkbox" name="visible_departments[]" value="<?php echo esc_attr($target_department); ?>" <?php checked(in_array($target_department, $visible_frontend_departments, true)); ?>>
                                Show on frontend
                            </label>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" class="button button-primary">Save Department Settings</button>
                </div>
            </form>
            <p style="margin:16px 0 0;color:#50575e;">Per-person goal calculation: each employee counts once for each weekday where they recorded production. Expected period output = per-person daily goal × active person-days. Multiple entries by the same employee on the same date still count as one person-day. Historical / Unassigned production is included in department totals but excluded from employee performance, active person-days, and per-person goal percentages. Saturday and Sunday never count.</p>
        </details>
    </div>
    <script>
    (function(){
        var select = document.getElementById('tlk-range-select');
        var custom = document.querySelectorAll('.tlk-custom-date');
        function toggleCustom(){
            for (var i=0;i<custom.length;i++) custom[i].style.display = select.value === 'custom' ? 'block' : 'none';
        }
        if (select) { select.addEventListener('change', toggleCustom); toggleCustom(); }
    })();
    </script>
    <?php
}