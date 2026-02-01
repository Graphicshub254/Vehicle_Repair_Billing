<?php
/**
 * Call Home Feature
 *
 * This module handles:
 * - System updates checking
 * - License validation
 * - Anonymous usage statistics
 * - Security alerts
 * - Feature announcements
 * - **Automatic Database Backups**
 * - **API and Database Table Monitoring**
 *
 * Version: 1.1
 */

// Ensure config is loaded for DB details and paths
require_once __DIR__ . '/../config/config.php';

class CallHomeService {

    private $pdo;
    private $callHomeUrl = 'https://updates.yourdomain.com/api/callback.php';
    private $systemId;
    private $licenseKey;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->systemId = $this->getSystemId();
        $this->licenseKey = $this->getLicenseKey();
    }

    public function getSetting($key, $default = null) {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    }

    public function setSetting($key, $value) {
        $stmt = $this->pdo->prepare(
            "INSERT INTO system_settings (setting_key, setting_value)"
            . "VALUES (?, ?)"
            . "ON DUPLICATE KEY UPDATE setting_value = ?"
        );
        $stmt->execute([$key, $value, $value]);
    }

    private function getSystemId() {
        $systemId = $this->getSetting('system_id');
        if (!$systemId) {
            $systemId = bin2hex(random_bytes(16));
            $this->setSetting('system_id', $systemId);
        }
        return $systemId;
    }

    private function getLicenseKey() {
        return $this->getSetting('license_key', 'FREE_LICENSE');
    }

    public function callHome($type = 'heartbeat') {
        $data = $this->gatherSystemData($type);
        $response = $this->sendRequest($data);

        if ($response) {
            $this->processResponse($response);
            $this->logCallHome($type, 'success');
            return $response;
        }

        $this->logCallHome($type, 'failed');
        return null;
    }

    private function gatherSystemData($type) {
        $data = [
            'system_id' => $this->systemId,
            'license_key' => $this->licenseKey,
            'type' => $type,
            'timestamp' => time(),
            'version' => APP_VERSION,
            'php_version' => phpversion(),
            'mysql_version' => $this->pdo->query('SELECT VERSION()')->fetchColumn(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'installation_date' => $this->getInstallationDate(),
        ];

        // Add type-specific data
        switch ($type) {
            case 'heartbeat':
                $data['statistics'] = $this->getUsageStatistics();
                // Include monitoring data if enabled
                if ($this->isApiMonitoringEnabled()) {
                    $data['monitoring_data'] = $this->monitorHealth();
                }
                break;

            case 'update_check':
                $data['current_version'] = APP_VERSION;
                $data['enabled_features'] = $this->getEnabledFeatures();
                break;

            case 'error_report':
                $data['error_logs'] = $this->getRecentErrors();
                break;

            case 'license_validate':
                $data['domain'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $data['ip'] = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
                break;
        }

        return $data;
    }

    private function getUsageStatistics() {
        $stats = [];

        $stats['total_vehicles'] = $this->pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();
        $stats['total_jobs'] = $this->pdo->query("SELECT COUNT(*) FROM jobs")->fetchColumn();
        $stats['total_invoices'] = $this->pdo->query("SELECT COUNT(*) FROM customer_invoices")->fetchColumn();
        $stats['total_users'] = $this->pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();

        $stats['jobs_last_30_days'] = $this->pdo->query(
            "SELECT COUNT(*) FROM jobs"
            . " WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetchColumn();

        $stats['invoices_last_30_days'] = $this->pdo->query(
            "SELECT COUNT(*) FROM customer_invoices"
            . " WHERE invoice_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetchColumn();

        $stats['quotations_used'] = $this->pdo->query("SELECT COUNT(*) FROM quotations")->fetchColumn() > 0;
        $stats['subcontracts_used'] = $this->pdo->query("SELECT COUNT(*) FROM subcontract_works")->fetchColumn() > 0;

        return $stats;
    }

    private function getEnabledFeatures() {
        return [
            'analytics' => true,
            'quotations' => true,
            'subcontracts' => true,
            'user_management' => true,
            'audit_trail' => true,
            'advanced_reports' => true,
            'db_backup' => $this->isBackupEnabled(),
            'api_monitoring' => $this->isApiMonitoringEnabled(),
        ];
    }

    private function getRecentErrors() {
        $stmt = $this->pdo->query(
            "SELECT error_message, error_file, error_line, created_at"
            . " FROM error_log"
            . " ORDER BY created_at DESC"
            . " LIMIT 10"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getInstallationDate() {
        $stmt = $this->pdo->query("SELECT MIN(created_at) FROM users");
        return $stmt->fetchColumn();
    }

    private function sendRequest($data) {
        $ch = curl_init($this->callHomeUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-System-ID: ' . $this->systemId,
                'X-License-Key: ' . $this->licenseKey
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error || $httpCode !== 200) {
            error_log("Call home failed: $error (HTTP $httpCode)");
            return null;
        }

        return json_decode($response, true);
    }

    private function processResponse($response) {
        if (!$response || !isset($response['status'])) {
            return;
        }

        if (isset($response['updates_available']) && $response['updates_available']) {
            $this->saveUpdateNotification($response['latest_version'], $response['update_url']);
        }

        if (isset($response['security_alerts']) && !empty($response['security_alerts'])) {
            $this->saveSecurityAlerts($response['security_alerts']);
        }

        if (isset($response['announcements']) && !empty($response['announcements'])) {
            $this->saveAnnouncements($response['announcements']);
        }

        if (isset($response['license_status'])) {
            $this->updateLicenseStatus($response['license_status']);
        }

        $this->setSetting('last_call_home', date('Y-m-d H:i:s'));
    }

    private function saveUpdateNotification($version, $url) {
        $stmt = $this->pdo->prepare(
            "INSERT INTO system_notifications"
            . " (type, title, message, action_url, created_at)"
            . " VALUES ('update', ?, ?, ?, NOW())"
        );
        $stmt->execute([
            "Update Available: v$version",
            "A new version of the system is available. Click to learn more.",
            $url
        ]);
    }

    private function saveSecurityAlerts($alerts) {
        $stmt = $this->pdo->prepare(
            "INSERT INTO system_notifications"
            . " (type, title, message, severity, created_at)"
            . " VALUES ('security', ?, ?, ?, NOW())"
        );
        foreach ($alerts as $alert) {
            $stmt->execute([
                $alert['title'],
                $alert['message'],
                $alert['severity'] ?? 'medium'
            ]);
        }
    }

    private function saveAnnouncements($announcements) {
        $stmt = $this->pdo->prepare(
            "INSERT INTO system_notifications"
            . " (type, title, message, created_at)"
            . " VALUES ('announcement', ?, ?, NOW())"
        );
        foreach ($announcements as $announcement) {
            $stmt->execute([
                $announcement['title'],
                $announcement['message']
            ]);
        }
    }

    private function updateLicenseStatus($status) {
        $this->setSetting('license_status', $status);
        if ($status !== 'valid') {
            $stmt = $this->pdo->prepare(
                "INSERT INTO system_notifications"
                . " (type, title, message, severity, created_at)"
                . " VALUES ('license', 'License Issue', ?, 'high', NOW())"
            );
            $stmt->execute(["Your license status is: $status. Please contact support."]);
        }
    }

    private function logCallHome($type, $status) {
        $stmt = $this->pdo->prepare(
            "INSERT INTO call_home_log"
            . " (call_type, status, created_at)"
            . " VALUES (?, ?, NOW())"
        );
        $stmt->execute([$type, $status]);
    }

    public function shouldCallHome() {
        $lastCallHome = $this->getSetting('last_call_home');
        if (!$lastCallHome) return true;
        $lastTime = strtotime($lastCallHome);
        $now = time();
        $hoursSince = ($now - $lastTime) / 3600;
        return $hoursSince >= 24;
    }

    public function getPendingNotifications() {
        $stmt = $this->pdo->query(
            "SELECT * FROM system_notifications"
            . " WHERE is_read = 0"
            . " ORDER BY created_at DESC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markNotificationRead($id) {
        $stmt = $this->pdo->prepare(
            "UPDATE system_notifications"
            . " SET is_read = 1"
            . " WHERE id = ?"
        );
        $stmt->execute([$id]);
    }

    public function checkForUpdates() {
        return $this->callHome('update_check');
    }

    public function validateLicense() {
        return $this->callHome('license_validate');
    }

    public function sendErrorReport() {
        return $this->callHome('error_report');
    }

    public function optOutOfTelemetry() {
        $this->setSetting('telemetry_enabled', '0');
    }

    public function isTelemetryEnabled() {
        $enabled = $this->getSetting('telemetry_enabled');
        return $enabled === null || $enabled === '1'; // Default to enabled
    }

    // ================================================= ================
    // NEW FEATURES: DB BACKUP & MONITORING
    // ================================================= ================

    public function isBackupEnabled() {
        return $this->getSetting('backup_enabled') === '1';
    }

    private function getBackupFrequencyHours() {
        return (int)$this->getSetting('backup_frequency_hours', 24);
    }

    public function shouldBackupDb() {
        if (!$this->isBackupEnabled()) {
            return false;
        }
        $lastBackup = $this->getSetting('last_backup_time');
        if (!$lastBackup) {
            return true;
        }
        $lastTime = strtotime($lastBackup);
        $now = time();
        $hoursSince = ($now - $lastTime) / 3600;
        return $hoursSince >= $this->getBackupFrequencyHours();
    }

    public function backupDatabase() {
        if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASS') || !defined('DB_NAME') || !defined('BACKUP_PATH')) {
            error_log("DB backup failed: Database credentials or BACKUP_PATH not defined.");
            return ['status' => 'failed', 'message' => 'Configuration missing.'];
        }

        $dbHost = DB_HOST;
        $dbUser = DB_USER;
        $dbPass = DB_PASS;
        $dbName = DB_NAME;
        $backupPath = BACKUP_PATH;

        if (!is_dir($backupPath) && !mkdir($backupPath, 0755, true)) {
            error_log("DB backup failed: Backup path does not exist and could not be created: $backupPath");
            return ['status' => 'failed', 'message' => 'Backup path not found or creatable.'];
        }

        $filename = "{$dbName}_" . date('Ymd_His') . ".sql";
        $filePath = "$backupPath/$filename";

        // Escape password for shell command
        $command = "mysqldump --opt -h{$dbHost} -u{$dbUser} " . (!empty($dbPass) ? "-p'{$dbPass}'" : "") . " {$dbName} > {$filePath} 2>&1";
        
        // Execute the command
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);

        if ($returnVar === 0) {
            $this->setSetting('last_backup_time', date('Y-m-d H:i:s'));
            error_log("DB backup successful: $filePath");
            return ['status' => 'success', 'file' => $filePath];
        } else {
            $errorMessage = implode("\n", $output);
            error_log("DB backup failed: {$errorMessage}");
            return ['status' => 'failed', 'message' => $errorMessage];
        }
    }

    public function isApiMonitoringEnabled() {
        return $this->getSetting('api_monitoring_enabled') === '1';
    }

    private function getApiMonitorEndpoints() {
        $endpointsJson = $this->getSetting('api_monitor_endpoints', '[]');
        return json_decode($endpointsJson, true);
    }

    private function getCriticalTablesToMonitor() {
        $tablesJson = $this->getSetting('critical_tables_to_monitor', '[]');
        return json_decode($tablesJson, true);
    }

    public function shouldMonitorApis() {
        if (!$this->isApiMonitoringEnabled()) {
            return false;
        }
        // Decide frequency. For now, always run when monitoring is enabled and callHome happens.
        // Could add a last_api_monitor_time setting if desired.
        return true;
    }

    public function monitorHealth() {
        $results = [
            'api_status' => [],
            'table_status' => [],
            'overall_status' => 'healthy'
        ];

        // Monitor APIs
        $endpoints = $this->getApiMonitorEndpoints();
        foreach ($endpoints as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_NOBODY => true, // HEAD request
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if (!$error && $httpCode >= 200 && $httpCode < 400) {
                $results['api_status'][$url] = 'healthy';
            } else {
                $results['api_status'][$url] = 'unhealthy (HTTP ' . $httpCode . ') - ' . $error;
                $results['overall_status'] = 'unhealthy';
                error_log("API Monitor: $url unhealthy. HTTP: $httpCode, Error: $error");
            }
        }

        // Monitor Critical Tables
        $tables = $this->getCriticalTablesToMonitor();
        foreach ($tables as $tableName) {
            try {
                $stmt = $this->pdo->query("SELECT 1 FROM `{$tableName}` LIMIT 1");
                $results['table_status'][$tableName] = 'accessible';
            } catch (PDOException $e) {
                $results['table_status'][$tableName] = 'inaccessible (' . $e->getMessage() . ')';
                $results['overall_status'] = 'unhealthy';
                error_log("Table Monitor: {$tableName} inaccessible. Error: {$e->getMessage()}");
            }
        }

        return $results;
    }
}

/**
 * Initialize call home service
 */
function getCallHomeService() {
    global $pdo;
    return new CallHomeService($pdo);
}

/**
 * Scheduled call home (run via cron)
 */
function scheduledCallHome() {
    $callHome = getCallHomeService();

    // Perform database backup
    if ($callHome->shouldBackupDb()) {
        $backupResult = $callHome->backupDatabase();
        // Log backup result if needed, or add to callHome data
    }

    // Perform API and table monitoring
    if ($callHome->shouldMonitorApis()) {
        $monitorResult = $callHome->monitorHealth();
        // Log monitor result if needed, or add to callHome data
    }

    // Regular call home heartbeat
    if ($callHome->shouldCallHome() && $callHome->isTelemetryEnabled()) {
        $callHome->callHome('heartbeat');
    }
}

/**
 * Manual update check
 */
function manualUpdateCheck() {
    $callHome = getCallHomeService();
    return $callHome->checkForUpdates();
}