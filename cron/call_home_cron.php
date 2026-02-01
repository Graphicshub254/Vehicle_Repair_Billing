#!/usr/bin/env php
<?php
/**
 * Call Home Cron Job
 *
 * This script should be run daily via cron to automatically
 * check for updates, validate license, send telemetry,
 * perform database backups, and monitor system health.
 *
 * Add to crontab:
 * 0 2 * * * /usr/bin/php /path/to/vehicle_repair_billing/cron/call_home_cron.php >> /path/to/vehicle_repair_billing/logs/call_home_cron.log 2>&1
 *
 * This runs daily at 2 AM
 */

// Change to project root directory
chdir(dirname(__DIR__));

// Load configuration and Call Home Service
require_once 'config/config.php';
require_once 'includes/call_home.php'; // This now contains scheduledCallHome()

// Start output buffering for logging
ob_start();

echo "========================================\n";
echo "Call Home Cron Job Started\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

try {
    // Execute the scheduled call home logic (includes backup, monitoring, and telemetry)
    scheduledCallHome();
    echo "\n========================================\n";
    echo "Call Home Cron Job Completed\n";
    echo "========================================\n";

} catch (Exception $e) {
    echo "✗ Error during Call Home Cron Job: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    echo "\n========================================\n";
    echo "Call Home Cron Job Completed with Errors\n";
    echo "========================================\n";
    exit(1); // Exit with error code
}

// Get output and log it
$output = ob_get_clean();
echo $output; // Also output to console if run manually

// Log to file
$logFile = __DIR__ . '/../logs/call_home_cron.log';
$logDir = dirname($logFile);

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

file_put_contents($logFile, $output, FILE_APPEND);

exit(0); // Exit successfully