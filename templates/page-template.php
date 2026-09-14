<?php
/**
 * Template Name: Schedule Dashboard
 */
get_header();

// Eventually remove this once the real hostinger cron is in place
$sync_result = tlk_sync_schedule_to_database();

$schedule_rows = tlk_get_saved_schedule();

$total_open = tlk_get_total_open_orders();
$past_due   = tlk_get_past_due_open_quantity();

/*
 * Selected dashboard month.
 */
$current_year  = (int) wp_date('Y');
$current_month = (int) wp_date('n');

$selected_year = isset($_GET['year'])
    ? absint($_GET['year'])
    : $current_year;

$selected_month = isset($_GET['month'])
    ? absint($_GET['month'])
    : $current_month;

/*
 * Prevent invalid month values.
 */
if ($selected_month < 1 || $selected_month > 12) {
    $selected_month = $current_month;
}

$on_time = tlk_get_on_time_delivery(
    $selected_year,
    $selected_month
);

/* Employee select */
$select_employee = tlk_select_employee();

?>

<main class="dash-container">
    <div class="dash-container__form">
        <h1>Production Entry Log</h1>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="POST">

            <input type="hidden" name="action" value="save_custom_get_data">

            <?php wp_nonce_field('tlk_production_entry', 'tlk_production_nonce'); ?>

            <div class="dash-container__bg">
                <label for="department">Department:</label>
                <select name="department" id="department" required>
                    <option value="cnc">CNC</option>
                    <option value="pour">Pouring</option>
                    <option value="Build">Build</option>
                </select>
            </div>

            <div class="dash-container__bg">
                <label for="employee">Employee:</label>

                <select name="employee" id="employee" required>
                    <option value="">Select an employee</option>

                    <?php foreach ($select_employee as $employee) : ?>

                        <option value="<?php echo esc_attr($employee); ?>">
                            <?php echo esc_html($employee); ?>
                        </option>

                    <?php endforeach; ?>

                    <option value="__new__">+ Add new employee</option>
                </select>

                <div id="new-employee-wrap" style="display: none;">

                    <label for="new_employee">
                        New Employee:
                    </label>

                    <input
                        type="text"
                        id="new_employee"
                        name="new_employee"
                    >

                </div>
            </div>

            <div class="dash-container__bg">
                <label for="qty">Quantity:</label>
                <input
                    type="number"
                    id="qty"
                    name="qty"
                    min="0"
                    required
                >
            </div>

            <input type="submit" class="prod-entry-submit" value="Submit">

        </form>
    </div>
    <div class="dash-container__header">
        <div>
            <h1>Production Dashboard</h1>
        </div>
        <div class="dashboard-month-filter">

            <form class="prod-dash-form" method="GET">

                <select
                    name="month"
                    onchange="this.form.submit()"
                >
                    <?php for ($month = 1; $month <= 12; $month++) : ?>

                        <option
                            value="<?php echo esc_attr($month); ?>"
                            <?php selected($selected_month, $month); ?>
                        >
                            <?php
                            echo esc_html(
                                wp_date(
                                    'F',
                                    mktime(0, 0, 0, $month, 1)
                                )
                            );
                            ?>
                        </option>

                    <?php endfor; ?>
                </select>

                <select
                    name="year"
                    onchange="this.form.submit()"
                >
                    <?php
                    for (
                        $year = $current_year - 2;
                        $year <= $current_year;
                        $year++
                    ) :
                    ?>

                        <option
                            value="<?php echo esc_attr($year); ?>"
                            <?php selected($selected_year, $year); ?>
                        >
                            <?php echo esc_html($year); ?>
                        </option>

                    <?php endfor; ?>
                </select>

            </form>

        </div>
    </div>
    <div class="dash-container__overview">
        <div class="dashboard-card">
            <p>Open Quantity for Orders: </p>&nbsp;
                <strong>
                    <?php echo esc_html(number_format_i18n($total_open)); ?>
                </strong>
            </div>

            <div class="dashboard-card">
                <p>Past Due Quantity for Orders: </p>&nbsp;
                <strong>
                    <?php echo esc_html(number_format_i18n($past_due)); ?>
                </strong>
            </div>
            <div class="dashboard-card">

        <p>On-Time Delivery: </p>&nbsp;

        <strong>
            <?php if ($on_time['percent'] === null) : ?>

                N/A

            <?php else : ?>

                <?php
                echo esc_html(
                    number_format_i18n(
                        $on_time['percent'],
                        1
                    )
                );
                ?>%

            <?php endif; ?>
        </strong>

        <?php if ($on_time['total'] > 0) : ?>
            <small>
                <?php echo esc_html($on_time['on_time']); ?>
                of
                <?php echo esc_html($on_time['total']); ?>
                orders
            </small>
        <?php endif; ?>
    </div>
        <div>Four</div>

    </div>
    <!-- <div class="table-container">
        <?php
            if (empty($schedule_rows) || !is_array($schedule_rows)) : ?>
            <p>No schedule data available</p>

        <?php else :
            // Extract column headers from the first row
            $first_row = reset($schedule_rows);
            $headers = array_keys($first_row);
        ?>

        <table class="schedule-table">
            <thead>
                <tr>
                    <?php foreach ($headers as $header) : ?>
                        <th><?php echo esc_html($header); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schedule_rows as $row) : ?>
                    <tr>
                        <?php foreach ($headers as $header) : ?>
                            <td>
                                <?php
                                    $cell_value = isset($row[$header]) ? $row[$header] : '';
                                    echo esc_html($cell_value);
                                ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div> -->
</main>

<?php get_footer(); ?>