<?php
/**
 * Template Name: Schedule Dashboard
 */
get_header();

// Eventually remove this once the real hostinger cron is in place
$sync_result = tlk_sync_schedule_to_database();

$schedule_rows = tlk_get_saved_schedule();

$total_open = tlk_get_total_open_orders();
$past_due = tlk_get_past_due_open_quantity();

/*
 * Dashboard month/year filtering.
 */
$current_year = (int) wp_date("Y");
$current_month = (int) wp_date("n");

$available_periods = tlk_get_available_dashboard_periods();

/*
 * Default to current month/year.
 */
$selected_year = isset($_GET["year"]) ? absint($_GET["year"]) : $current_year;

$selected_month = isset($_GET["month"])
    ? absint($_GET["month"])
    : $current_month;

/*
 * Prevent invalid month values.
 */
if ($selected_month < 1 || $selected_month > 12) {
    $selected_month = $current_month;
}

/*
 * Build available years.
 */
$available_years = [];

foreach ($available_periods as $period) {
    $year = (int) $period["year"];

    if (!in_array($year, $available_years, true)) {
        $available_years[] = $year;
    }
}

/*
 * Check whether the currently selected period actually exists.
 */
$selected_period_exists = false;

foreach ($available_periods as $period) {
    if (
        (int) $period["year"] === $selected_year &&
        (int) $period["month"] === $selected_month
    ) {
        $selected_period_exists = true;
        break;
    }
}

/*
 * If the requested period doesn't exist, use the newest available period.
 */
if (!$selected_period_exists && !empty($available_periods)) {
    $selected_year = (int) $available_periods[0]["year"];

    $selected_month = (int) $available_periods[0]["month"];
}

$on_time = tlk_get_on_time_delivery($selected_year, $selected_month);

/* Employee select */
$select_employee = tlk_select_employee();
?>

<main class="dash-container">
    <div class="dash-container__form">
        <h1>Production Entry Log</h1>
        <form action="<?php echo esc_url(
            admin_url("admin-post.php")
        ); ?>" method="POST">

            <input type="hidden" name="action" value="save_custom_get_data">

            <?php wp_nonce_field(
                "tlk_production_entry",
                "tlk_production_nonce"
            ); ?>

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

                    <?php foreach ($select_employee as $employee): ?>

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
            <span>Filter by month & year:</span>
            <form class="prod-dash-form" method="GET">
                <select
                    name="month"
                    onchange="this.form.submit()"
                >

                    <?php foreach ($available_periods as $period): ?>

                        <?php
                        $period_year = (int) $period["year"];
                        $period_month = (int) $period["month"];

                        /*
                         * Only show months that exist
                         * for the currently selected year.
                         */
                        if ($period_year !== $selected_year) {
                            continue;
                        }
                        ?>

                        <option
                            value="<?php echo esc_attr($period_month); ?>"
                            <?php selected($selected_month, $period_month); ?>
                        >
                            <?php echo esc_html(
                                wp_date(
                                    "F",
                                    mktime(
                                        0,
                                        0,
                                        0,
                                        $period_month,
                                        1,
                                        $period_year
                                    )
                                )
                            ); ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <select
                    name="year"
                    onchange="this.form.submit()"
                >

                    <?php foreach ($available_years as $year): ?>

                        <option
                            value="<?php echo esc_attr($year); ?>"
                            <?php selected($selected_year, $year); ?>
                        >
                            <?php echo esc_html($year); ?>
                        </option>

                    <?php endforeach; ?>

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
            <p>On-Time Delivery:</p>&nbsp;

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

                <details class="dashboard-card__details">
                    <summary>View calculation details</summary>

                    <div class="dashboard-card__details-content">

                        <p>
                            <strong>Selected period:</strong>
                            <?php
                            echo esc_html(
                                wp_date(
                                    'F Y',
                                    mktime(
                                        0,
                                        0,
                                        0,
                                        $selected_month,
                                        1,
                                        $selected_year
                                    )
                                )
                            );
                            ?>
                        </p>

                        <p>
                            <strong>Total shipped orders:</strong>
                            <?php echo esc_html($on_time['total']); ?>
                        </p>

                        <p>
                            <strong>On-time orders:</strong>
                            <?php echo esc_html($on_time['on_time']); ?>
                        </p>

                        <p>
                            <strong>Late orders:</strong>
                            <?php
                            echo esc_html(
                                $on_time['total'] - $on_time['on_time']
                            );
                            ?>
                        </p>

                        <p>
                            <strong>Calculation:</strong>
                            <?php echo esc_html($on_time['on_time']); ?>
                            ÷
                            <?php echo esc_html($on_time['total']); ?>
                            × 100
                            =
                            <?php
                            echo esc_html(
                                number_format_i18n(
                                    $on_time['percent'],
                                    1
                                )
                            );
                            ?>%
                        </p>

                        <p class="dashboard-card__details-note">
                            Based on orders recorded in the order history table
                            with a shipped date in the selected month.
                        </p>

                    </div>
                </details>

            <?php endif; ?>
        </div>
    </div>
</main>

<?php get_footer(); ?>