1. QuickBooks CSV
2. Google Sheet "Raw"
3. buildCleanSchedule()
4. Google Sheet "Clean Schedule"
5. Apps Script doGet()
6. JSON
7. WordPress get_schedule_data()
8. tlk_sync_schedule_to_database()
9. wp_tlk_schedule
10. tlk_get_saved_schedule()
11. $schedule_rows
12. WordPress HTML table

Need to know guide:

Google Sheets is the source of truth. You upload the QuickBooks CSV, and the Apps Script builds the Clean Schedule sheet.
WordPress copies the Clean Schedule into its database. When the dashboard loads, tlk_sync_schedule_to_database() calls get_schedule_data(), 
which hits the Apps Script /exec endpoint. It then clears wp_tlk_schedule and inserts the latest rows from Google.

The flow works like "visit dashboard and contact Google which rebuilds the WP table. The plugin reads tje WP table and displays it.