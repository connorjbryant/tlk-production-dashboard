<?php
/**
 * Template Name: Schedule Dashboard
 */
get_header();

// Fetch Google Apps Script key-value data
$schedule_rows = get_schedule_data();
?>

<main>
    <div class="table-container">
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
    </div>
</main>

<?php get_footer(); ?>

