# TLK Schedule Sync

WordPress displays a live schedule that starts in QuickBooks and is cleaned in Google Sheets.

Google Sheets is the source of truth. You upload a QuickBooks CSV, Apps Script builds the **Clean Schedule** sheet, and WordPress copies that sheet into its database when the dashboard loads.

## Features

- Import a QuickBooks CSV into Google Sheets
- Clean and normalize rows with Apps Script
- Expose the clean schedule as JSON from an Apps Script web app
- Sync that JSON into the WordPress table `wp_tlk_schedule`
- Render the saved rows as an HTML table on the dashboard

## Data flow

1. QuickBooks CSV
2. Google Sheet reads **Raw** tab
3. `buildCleanSchedule()` processes the raw data
4. Google Sheet makes **Clean Schedule** tab
5. `doGet()` reads the Clean Schedule
6. Apps Script outputs the data as **JSON**
7. WordPress `get_schedule_data()` retrieves the JSON
8. `tlk_sync_schedule_to_database()` saves the data to WordPress
9. Data is stored in **`wp_tlk_schedule`**
10. `tlk_get_saved_schedule()` reads the WordPress database table
11. Results are stored in **`$schedule_rows`**
12. WordPress **HTML table** displays the data

In short: Visit the dashboard and WordPress contacts Google. Then the WordPress table is rebuilt and the plugin displays the table.

Google owns the live schedule. WordPress stores a copy for display. The dashboard visit is what triggers the refresh. The plugin does not read Google directly at render time.

## Requirements

- A Google account with access to Google Sheets and Apps Script
- WordPress 6.0 or later
- PHP 8.0 or later
- Permission for WordPress to make outbound HTTPS requests

## WordPress setup

1. Install and activate the plugin.
2. Store the Apps Script web app URL in the plugin settings or `wp-config.php`.

```php
define( 'TLK_SCHEDULE_ENDPOINT', 'https://script.google.com/macros/s/XXXXXXXX/exec' );
```

3. Confirm WordPress can reach that URL.
4. Open the dashboard page that displays the schedule. That page load should trigger the sync.

### Database

The plugin stores the copied schedule in `wp_tlk_schedule`.

On each successful sync:

1. Existing rows are removed.
2. The latest Google rows are inserted.
3. The dashboard reads only from this table.

## Usage

1. Export the schedule CSV from QuickBooks.
2. Upload it to the Google Sheet **Raw** tab.
3. Run `buildCleanSchedule()` if it does not run automatically on edit.
4. Open the WordPress dashboard.
5. Confirm the HTML table matches **Clean Schedule**.

## Troubleshooting

**The WordPress table is empty**
- Confirm the Apps Script web app is deployed and the `/exec` URL is current.
- Open the URL in a browser and confirm it returns JSON, not an HTML Google login page.
- Check that `doGet()` is returning an array.

**WordPress shows old rows**
- The sync runs when the dashboard loads. Refresh that page.
- Confirm `tlk_sync_schedule_to_database()` is actually being called on load.
- Confirm `buildCleanSchedule()` has rebuilt **Clean Schedule** after the latest CSV upload.

**Apps Script returns HTML instead of JSON**
- The web app access setting is too strict, or the deployment is not the latest version.
- Redeploy the web app and update the URL in WordPress.