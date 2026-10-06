# Deleted Record Retention

Work orders and their linked service requests, plus unlinked service requests, are moved into the application Trash. Their archived data remains recoverable for at least seven months. Trash is restricted to admin and office roles.

## Schedule Permanent Cleanup on Windows

Create a daily task in Windows Task Scheduler:

1. Choose **Create Basic Task** and set the trigger to **Daily**.
2. Choose **Start a program**.
3. Set **Program/script** to the PHP CLI executable, for example `C:\wamp64\bin\php\php8.2.29\php.exe`.
4. Set **Add arguments** to `"C:\wamp64\www\sps\scripts\purge_deleted_records.php"`.
5. Finish and use **Run** once to verify the task.

Adjust the PHP executable path if this WAMP installation uses a different PHP version. The cleanup query only removes archive entries older than seven months. Opening the Trash page also performs the same expired-record cleanup.