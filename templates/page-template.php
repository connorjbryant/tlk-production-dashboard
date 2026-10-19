<?php
/**
 * Template Name: Schedule Dashboard
 */

if ( post_password_required() ) {
    get_header();

    echo get_the_password_form();

    get_footer();
    exit;
}

get_header();

// Eventually remove this once the real hostinger cron is in place
// $sync_result = tlk_sync_schedule_to_database();

$schedule_rows = tlk_get_saved_schedule();

$total_open = tlk_get_total_open_orders();
$past_due = tlk_get_past_due_open_quantity();

// Quota
$cnc_quota = cnc_quota();
$pouring_quota = pouring_quota();
$building_quota = building_quota();

/*
 * Dashboard always displays the current month/year.
 * Historical order data remains stored in the database.
 */
$current_year  = (int) wp_date('Y');
$current_month = (int) wp_date('n');

$on_time = tlk_get_on_time_delivery($current_year, $current_month);

$current_period = wp_date('F Y', mktime(0, 0, 0, $current_month, 1, $current_year));
$cnc_performance = tlk_get_department_performance('Machining', $current_year, $current_month);
$pouring_performance = tlk_get_department_performance('Pouring', $current_year, $current_month);
$shipping_performance = tlk_get_department_performance('Assembly & Packaging', $current_year, $current_month);
$shipping_quota = tlk_department_quota('Assembly & Packaging');
$shipping_percent = min(100, (int) round($shipping_performance['percent']));
$building_performance = tlk_get_department_performance('Assembly & Packaging', $current_year, $current_month);
$cnc_percent = min(100, (int) round($cnc_performance['percent']));
$pouring_percent = min(100, (int) round($pouring_performance['percent']));
$building_percent = min(100, (int) round($building_performance['percent']));

/* Employee select */
$select_employee = tlk_select_employee();
$can_manage_employee_roster = tlk_can_manage_employee_roster();
$employee_roster = $can_manage_employee_roster ? tlk_get_employee_roster(false) : array();

/* Current user's entries that are still inside the 24-hour edit window. */
$editable_entries = tlk_get_current_user_editable_entries();

$edit_redirect = get_permalink();

$currentMonth = (int) date('n');

$selectedBg = plugin_dir_url(dirname(__FILE__)) . 'images/' . $currentMonth . '.jpg';

?>

<main class="dash-container" data-tlk-image-base="<?php echo esc_url(plugin_dir_url(dirname(__FILE__)) . 'images/'); ?>" data-tlk-month-image="<?php echo esc_url($selectedBg); ?>" style="width:100%;max-width:none;<?php echo $selectedBg ? "background-image:url('" . esc_url($selectedBg) . "');" : ''; ?>">

    <?php
    $current_user = wp_get_current_user();

    $allowed_emails = array(
        'connor@flexrockperformance.com',
        'josh@tlkprecision.com',
        'brian@tlkprecision.com',
        'brianj@tlkprecision.com',
        'todd@tlkprecision.com',
        'deric@tlkprecision.com',
    );


    $is_allowed_user = is_user_logged_in() && in_array(strtolower((string) $current_user->user_email), $allowed_emails, true);

    if ($is_allowed_user) { ?>

    <div class="tlk-internal-tools">
        <h1><?php echo esc_html($current_period); ?></h1>
        <a class="tlk-internal-tools__button" href="<?php echo esc_url(admin_url('admin.php?page=tlk-employee-performance')); ?>">Employee Performance &amp; Targets</a>
    </div>

    <div class="dash-container__form">
        <h1>Production Entry Log</h1>
        <p>Add everyone who worked in the department, then save all entries at once.</p>

        <?php if ($can_manage_employee_roster): ?>
        <details class="tlk-employee-manager" <?php echo isset($_GET['tlk_employee_saved']) ? 'open' : ''; ?>>
            <summary><strong>Manage Employees</strong></summary>
            <p>Add employees without creating a production entry. Inactive employees remain in historical reports but are hidden from new-entry dropdowns.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tlk-employee-add-form">
                <input type="hidden" name="action" value="tlk_add_employee_roster">
                <?php wp_nonce_field('tlk_manage_employee_roster'); ?>
                <label>Employee name: <input type="text" name="employee_name" required></label>
                <button type="submit">Add Employee</button>
            </form>

            <?php if ($employee_roster): ?>
                <div class="tlk-employee-roster-list">
                    <?php foreach ($employee_roster as $roster_employee): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tlk-employee-roster-row">
                            <input type="hidden" name="action" value="tlk_toggle_employee_roster">
                            <input type="hidden" name="employee_id" value="<?php echo absint($roster_employee->id); ?>">
                            <input type="hidden" name="active" value="<?php echo $roster_employee->active ? '0' : '1'; ?>">
                            <?php wp_nonce_field('tlk_manage_employee_roster'); ?>
                            <span><?php echo esc_html($roster_employee->employee_name); ?></span>
                            <span><?php echo $roster_employee->active ? 'Active' : 'Inactive'; ?></span>
                            <button type="submit"><?php echo $roster_employee->active ? 'Deactivate' : 'Reactivate'; ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </details>
        <?php endif; ?>

        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="POST">
            <input type="hidden" name="action" value="save_custom_get_data">

            <?php wp_nonce_field('tlk_production_entry', 'tlk_production_nonce'); ?>

            <div class="tlk-mode-switch" id="tlk-mode-switch"><strong>Entry mode</strong> <button type="button" data-mode="basic" class="active">Basic</button><button type="button" data-mode="advanced">Advanced</button></div>
            <input type="hidden" name="entry_mode" id="tlk-entry-mode" value="basic">
            <div class="dash-container__department-row">

                <div class="dash-container__bg">
                    <label for="department">Department:</label>

                    <select name="department" id="department" required>
                        <option value="Pouring">Pouring</option>
                        <option value="Machining">Machining</option>
                        <option value="Assembly &amp; Packaging">Assembly &amp; Packaging</option>
                    </select>
                </div>

                <div class="department-image">
                    <img
                        id="department-image"
                        src="<?php echo esc_url(plugin_dir_url(dirname(__FILE__)) . 'images/cnc.jpg'); ?>"
                        data-image-base="<?php echo esc_url(plugin_dir_url(dirname(__FILE__)) . 'images/'); ?>"
                        alt="Machining"
                    >
                </div>

            </div>

            <script type="application/json" id="tlk-entry-settings"><?php echo wp_json_encode(array('enabled' => get_option('tlk_advanced_departments', array()), 'parts' => array_combine(array('Pouring','Machining','Assembly & Packaging'), array_map('tlk_saved_parts', array('Pouring','Machining','Assembly & Packaging'))))); ?></script>
            <div class="production-entry-list" id="production-entry-list">
                <div class="production-entry-row">
                    <div class="production-entry-field">
                        <label>Employee:</label>
                        <select name="employee[]" class="production-employee" required>
                            <option value="">Select an employee</option>
                            <?php foreach ($select_employee as $employee): ?>
                                <option value="<?php echo esc_attr($employee); ?>">
                                    <?php echo esc_html($employee); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="production-entry-field">
                        <label>Quantity produced:</label>
                        <input type="number" name="qty[]" min="0" required>
                    </div>

                    <div class="tlk-advanced-parts" hidden><label>Part name / Quantity produced</label><div class="tlk-part-lines"></div><button type="button" class="tlk-add-part">+ Add another part</button></div>
                <button type="button" class="production-remove-row" aria-label="Remove employee entry">Remove</button>
                </div>
            </div>

            <button type="button" class="production-add-row" id="production-add-row">+ Add Another Person</button>
            <input type="submit" class="prod-entry-submit" value="Save Log(s)">
        </form>

        <template id="production-entry-template">
            <div class="production-entry-row">
                <div class="production-entry-field">
                    <label>Employee:</label>
                    <select name="employee[]" class="production-employee" required>
                        <option value="">Select an employee</option>
                        <?php foreach ($select_employee as $employee): ?>
                            <option value="<?php echo esc_attr($employee); ?>">
                                <?php echo esc_html($employee); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="production-entry-field">
                    <label>Quantity produced:</label>
                    <input type="number" name="qty[]" min="0" required>
                </div>

                <div class="tlk-advanced-parts" hidden><label>Part name / Quantity produced</label><div class="tlk-part-lines"></div><button type="button" class="tlk-add-part">+ Add another part</button></div>
                <button type="button" class="production-remove-row" aria-label="Remove employee entry">Remove</button>
            </div>
        </template>
    </div>

    <section class="dash-container__recent-entries">
        <div class="recent-entries__header">
            <div>
                <h2>My Recent Entries</h2>
                <p>Entries you submit can be corrected for 24 hours.</p>
            </div>
        </div>

        <?php if (isset($_GET['entry_updated']) && $_GET['entry_updated'] === '1') : ?>
            <div class="recent-entries__notice" role="status">
                Entry updated successfully.
            </div>
        <?php endif; ?>

        <?php if (empty($editable_entries)) : ?>
            <p class="recent-entries__empty">
                You do not have any entries available to edit right now.
            </p>
        <?php else : ?>

            <div class="recent-entries__list">
                <?php foreach ($editable_entries as $entry) : ?>
                    <?php
                    $entry_time = new DateTimeImmutable(
                        $entry['entry_date'],
                        wp_timezone()
                    );
                    $expires_at = $entry_time->modify('+24 hours');
                    ?>

                    <details class="recent-entry">
                        <summary class="recent-entry__summary">
                            <span class="recent-entry__main">
                                <strong><?php echo esc_html($entry['employee']); ?></strong>
                                <span><?php echo esc_html(ucfirst($entry['department'])); ?></span>
                                <span><?php echo esc_html(number_format_i18n((int) $entry['qty'])); ?> parts</span>
                            </span>

                            <span class="recent-entry__meta">
                                <?php echo esc_html(
                                    wp_date(
                                        'M j, g:i a',
                                        $entry_time->getTimestamp()
                                    )
                                ); ?>
                                · Edit
                            </span>
                        </summary>

                        <div class="recent-entry__edit">
                            <p class="recent-entry__expires">
                                Editable until
                                <strong>
                                    <?php echo esc_html(
                                        wp_date(
                                            'M j, Y g:i a',
                                            $expires_at->getTimestamp()
                                        )
                                    ); ?>
                                </strong>
                            </p>

                            <form
                                action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                method="POST"
                                class="recent-entry__form"
                            >
                                <input
                                    type="hidden"
                                    name="action"
                                    value="tlk_update_production_entry"
                                >
                                <input
                                    type="hidden"
                                    name="entry_id"
                                    value="<?php echo esc_attr($entry['id']); ?>"
                                >
                                <input
                                    type="hidden"
                                    name="redirect_to"
                                    value="<?php echo esc_url($edit_redirect); ?>"
                                >

                                <?php wp_nonce_field(
                                    'tlk_edit_production_entry',
                                    'tlk_edit_production_nonce'
                                ); ?>

                                <div class="recent-entry__field">
                                    <label for="edit-department-<?php echo esc_attr($entry['id']); ?>">
                                        Department
                                    </label>
                                    <select
                                        id="edit-department-<?php echo esc_attr($entry['id']); ?>"
                                        name="department"
                                        required
                                    >
                                        <option value="Machining" <?php selected($entry['department'], 'Machining'); ?>>Machining</option>
                                        <option value="Pouring" <?php selected($entry['department'], 'Pouring'); ?>>Pouring</option>
                                        <option value="Assembly &amp; Packaging" <?php selected($entry['department'], 'Assembly & Packaging'); ?>>Assembly &amp; Packaging</option>
                                    </select>
                                </div>

                                <div class="recent-entry__field">
                                    <label for="edit-employee-<?php echo esc_attr($entry['id']); ?>">
                                        Employee
                                    </label>
                                    <input
                                        type="text"
                                        id="edit-employee-<?php echo esc_attr($entry['id']); ?>"
                                        name="employee"
                                        value="<?php echo esc_attr($entry['employee']); ?>"
                                        required
                                    >
                                </div>

                                <div class="recent-entry__field">
                                    <label for="edit-qty-<?php echo esc_attr($entry['id']); ?>">
                                        Quantity
                                    </label>
                                    <input
                                        type="number"
                                        id="edit-qty-<?php echo esc_attr($entry['id']); ?>"
                                        name="qty"
                                        min="0"
                                        value="<?php echo esc_attr((int) $entry['qty']); ?>"
                                        required
                                    >
                                </div>

                                <button type="submit" class="recent-entry__save">
                                    Save Changes
                                </button>

                                <?php if (!empty($entry['updated_at'])) : ?>
                                    <small class="recent-entry__updated">
                                        Last corrected
                                        <?php echo esc_html(
                                            wp_date(
                                                'M j, g:i a',
                                                strtotime($entry['updated_at'])
                                            )
                                        ); ?>
                                    </small>
                                <?php endif; ?>
                            </form>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </section>

    <?php } ?>

    <section class="tlk-stats-section">
        <div class="tlk-stats-video-header">
            <video
                class="tlk-stats-motion-strip"
                autoplay
                muted
                loop
                playsinline
                preload="metadata"
                aria-hidden="true"
            >
                <source
                    src="<?php echo esc_url(plugin_dir_url(dirname(__FILE__)) . 'videos/work.mp4'); ?>"
                    type="video/mp4"
                >
            </video>

            <h1 class="tlk-stats-title" style="margin: 0 !important; background: rgba(35, 35, 35, 0.75);">
                Production Statistics by Department
                <span>(<?php echo esc_html($current_period); ?>)</span>
            </h1>
        </div>

        <div class="tlk-production-grid">
            <?php
            $departments = array(
                array('name' => 'Machining', 'quota' => $cnc_quota, 'performance' => $cnc_performance, 'percent' => $cnc_percent),
                array('name' => 'Pouring', 'quota' => $pouring_quota, 'performance' => $pouring_performance, 'percent' => $pouring_percent),
                
                array('name' => 'Assembly & Packaging', 'quota' => $shipping_quota, 'performance' => $shipping_performance, 'percent' => $shipping_percent),
            );

            $departments = array_values(array_filter($departments, function ($department) {
                return tlk_department_is_visible_on_frontend($department['name']);
            }));
            ?>

            <?php
            $single_department_card = count($departments) >= 1;
            ?>

            <?php foreach ($departments as $department) : ?>
                <?php
                if ($department['performance']['met']) {
                    $production_status_class = 'is-good';
                } elseif ($department['percent'] >= 50) {
                    $production_status_class = 'is-warning';
                } else {
                    $production_status_class = 'is-bad';
                }
                ?>

                <?php
                $background_files = array(
                    'Machining' => 'machining.jpg',
                    'Pouring' => 'pouring.jpg',
                    
                    'Assembly & Packaging' => 'assembly_packaging.jpg',
                );
                $background_file = $background_files[$department['name']] ?? 'building.jpg';
                $department_background_url = plugin_dir_url(dirname(__FILE__)) . 'images/' . $background_file;
                ?>
                <div class="tlk-production-card tlk-dept-<?php echo esc_attr(sanitize_title($department['name'])); ?> <?php echo esc_attr($production_status_class); ?><?php echo $single_department_card ? ' tlk-production-card--single' : ''; ?>" data-tlk-background="<?php echo esc_url($department_background_url); ?>">
                    <div class="tlk-production-card__content">
                    <div class="tlk-production-card__top">
                        <h2>
                            <?php echo esc_html($department['name']); ?>
                            
                        </h2>
                        <span class="tlk-quota-label"><?php echo esc_html($production_status_class === 'is-good' ? 'On Target' : ($production_status_class === 'is-warning' ? 'Approaching Target' : 'Below Target')); ?></span>
                        <span class="tlk-status-icon" aria-hidden="true">
                            <?php echo $department['performance']['met'] ? '&#10003;' : '&#8595;'; ?>
                        </span>
                    </div>

                    <div class="tlk-production-card__number">
                        <?php echo esc_html(number_format_i18n($department['quota']['total'])); ?>
                        <h3>Average Parts per Person / Day</h3>
                    </div>

                    <div class="tlk-production-card__bottom">
                        <div class="tlk-production-card__progress-info">
                            <strong>
                                <?php echo esc_html(number_format_i18n($department['performance']['percent'], 1)); ?>% / 100%
                            </strong>
                            <strong><?php echo esc_html($department['percent']); ?>%</strong>
                        </div>
                        <div class="tlk-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr($department['percent']); ?>">
                            <span style="width: <?php echo esc_attr($department['percent']); ?>%;"></span>
                        </div>
                    </div>
                    </div>

                    <?php if ($single_department_card) : ?>
                        <?php $department_image = $department['name'] === 'Machining' ? 'machining.jpg' : ($department['name'] === 'Pouring' ? 'pouring.jpg' : ($department['name'] === 'Assembly & Packaging' ? 'assembly_packaging.jpg' : 'building.jpg')); ?>
                        <div class="tlk-production-card__image-wrap" aria-hidden="true">
                            <img
                                class="tlk-production-card__image"
                                src="<?php echo esc_url(plugin_dir_url(dirname(__FILE__)) . 'images/' . $department_image); ?>"
                                alt=""
                            >
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="tlk-stats-section tlk-order-section">
        <h1 class="tlk-stats-title">Order Statistics (<?php echo esc_html($current_period); ?>)</h1>

        <div class="tlk-order-grid">
            <div class="tlk-order-card tlk-order-card--good">
                <h2>Open Quantity for<br>Orders:</h2>
                <div class="tlk-order-card__number">
                    <?php echo esc_html(number_format_i18n($total_open)); ?>
                </div>
            </div>

            <div class="tlk-order-card tlk-order-card--bad">
                <h2>Past Due Quantity<br>for Orders:</h2>
                <div class="tlk-order-card__number">
                    <?php echo esc_html(number_format_i18n($past_due)); ?>
                </div>
            </div>

            <?php
            // Change the On-Time Delivery card color based on the current ratio.
            if ($on_time['percent'] === null) {
                $on_time_status_class = 'tlk-order-card--neutral';
            } elseif ($on_time['percent'] >= 90) {
                $on_time_status_class = 'tlk-order-card--good';
            } elseif ($on_time['percent'] >= 50) {
                $on_time_status_class = 'tlk-order-card--warning';
            } else {
                $on_time_status_class = 'tlk-order-card--bad';
            }
            ?>

            <div class="tlk-order-card <?php echo esc_attr($on_time_status_class); ?>">
                <h2>On-Time Delivery:</h2>
                <div class="tlk-order-card__number">
                    <?php if ($on_time['percent'] === null) : ?>
                        N/A
                    <?php else : ?>
                        <?php echo esc_html(number_format_i18n($on_time['percent'], 1)); ?>%
                    <?php endif; ?>
                </div>

                <?php if ($on_time['total'] > 0) : ?>
                    <div class="tlk-order-card__ratio">
                        <?php echo esc_html($on_time['on_time']); ?> of <?php echo esc_html($on_time['total']); ?> orders
                    </div>

                    <details class="tlk-order-details">
                        <summary>
                            <span class="tlk-details-view">View Calculation</span>
                            <span class="tlk-details-hide">Hide Calculation</span>
                        </summary>
                        <div class="tlk-order-details__content">
                            <p><strong>Current period:</strong> <?php echo esc_html($current_period); ?></p>
                            <p><strong>Total shipped orders:</strong> <?php echo esc_html($on_time['total']); ?></p>
                            <p><strong>On-time orders:</strong> <?php echo esc_html($on_time['on_time']); ?></p>
                            <p><strong>Late orders:</strong> <?php echo esc_html($on_time['total'] - $on_time['on_time']); ?></p>
                            <p>
                                <strong>Calculation:</strong>
                                <?php echo esc_html($on_time['on_time']); ?> &divide; <?php echo esc_html($on_time['total']); ?> &times; 100 =
                                <?php echo esc_html(number_format_i18n($on_time['percent'], 1)); ?>%
                            </p>

                            <?php if (!empty($on_time['orders'])) : ?>
                                <div class="dashboard-card__order-list">
                                    <strong>Orders included:</strong>
                                    <div class="table-container">
                                        <table>
                                            <thead><tr><th>PO</th><th>Due</th><th>Shipped</th><th>Status</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($on_time['orders'] as $order) : ?>
                                                    <tr>
                                                        <td><?php echo esc_html($order['po_number']); ?></td>
                                                        <td><?php echo esc_html(wp_date('M j, Y', strtotime($order['due_date']))); ?></td>
                                                        <td><?php echo esc_html(wp_date('M j, Y', strtotime($order['shipped_date']))); ?></td>
                                                        <td><?php echo (int) $order['on_time'] === 1 ? 'On Time' : 'Late'; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>

<?php get_footer(); ?>