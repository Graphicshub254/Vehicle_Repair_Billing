<?php
// ================================================================
// VEHICLE REPAIR BILLING SYSTEM - DATABASE UPDATE SCRIPT (Job Parts)
// ================================================================

// Include the configuration file
require_once 'config/config.php';

try {
    // SQL for creating the job_parts table
    $sqlJobParts = "
    CREATE TABLE IF NOT EXISTS job_parts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        part_id INT NOT NULL,
        quantity_used INT NOT NULL DEFAULT 1,
        price_per_unit DECIMAL(10, 2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
        FOREIGN KEY (part_id) REFERENCES inventory_parts(id) ON DELETE RESTRICT
    );";

    // Execute the SQL statement
    $pdo->exec($sqlJobParts);
    echo "Table 'job_parts' created successfully (if it didn't exist).<br>";

    echo "<br><strong>Database update complete. You can now delete this file.</strong>";

} catch (PDOException $e) {
    die("Database update failed: " . $e->getMessage());
}
