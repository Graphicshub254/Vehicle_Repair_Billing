<?php
require_once '../../config/config.php';
require_once '../../includes/call_home.php';

requireLogin();
requireDirector(); // Only directors can manage call home settings

$pageTitle = 'Call Home Settings';
$breadcrumbs = [
    ['text' => 'Settings', 'url' => APP_URL . '/modules/settings/settings.php'],
    ['text' => 'Call Home']
];

$callHome = getCallHomeService();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'toggle_telemetry') {
        $enabled = isset($_POST['telemetry_enabled']) ? '1' : '0';
        $callHome->setSetting('telemetry_enabled', $enabled);
        
        logActivity($pdo, 'Updated telemetry settings', 'system_settings', 0, "Telemetry: $enabled");
        setSuccessMessage($enabled ? 'Telemetry enabled' : 'Telemetry disabled');
        redirect(APP_URL . '/modules/settings/call_home.php');
        
    } elseif ($action === 'check_updates') {
        $response = $callHome->checkForUpdates();
        
        if ($response && isset($response['status']) && $response['status'] === 'success') {
            if (isset($response['updates_available']) && $response['updates_available']) {
                setSuccessMessage('Update available: v' . $response['latest_version']);
            } else {
                setSuccessMessage('System is up to date!');
            }
        } else {
            setErrorMessage('Could not check for updates. Please try again later.');
        }
        redirect(APP_URL . '/modules/settings/call_home.php');
        
    } elseif ($action === 'validate_license') {
        $response = $callHome->validateLicense();
        
        if ($response && isset($response['license_status'])) {
            if ($response['license_status'] === 'valid') {
                setSuccessMessage('License is valid!');
            } else {
                setErrorMessage('License status: ' . $response['license_status']);
            }
        } else {
            setErrorMessage('Could not validate license. Please try again later.');
        }
        redirect(APP_URL . '/modules/settings/call_home.php');
        
    } elseif ($action === 'mark_read') {
        $notification_id = intval($_POST['notification_id'] ?? 0);
        $callHome->markNotificationRead($notification_id);
        setSuccessMessage('Notification marked as read');
        redirect(APP_URL . '/modules/settings/call_home.php');
        
    } elseif ($action === 'update_license') {
        $license_key = trim($_POST['license_key'] ?? '');
        
        if (!empty($license_key)) {
            $callHome->setSetting('license_key', $license_key);
            
            logActivity($pdo, 'Updated license key', 'system_settings', 0);
            setSuccessMessage('License key updated successfully');
        }
        redirect(APP_URL . '/modules/settings/call_home.php');
    
    } elseif ($action === 'update_backup_settings') {
        $backup_enabled = isset($_POST['backup_enabled']) ? '1' : '0';
        $backup_frequency_hours = intval($_POST['backup_frequency_hours'] ?? 24);
        if ($backup_frequency_hours <= 0) $backup_frequency_hours = 1; // Minimum 1 hour

        $callHome->setSetting('backup_enabled', $backup_enabled);
        $callHome->setSetting('backup_frequency_hours', $backup_frequency_hours);

        logActivity($pdo, 'Updated DB backup settings', 'system_settings', 0, "Enabled: $backup_enabled, Freq: $backup_frequency_hours hrs");
        setSuccessMessage('Database backup settings updated.');
        redirect(APP_URL . '/modules/settings/call_home.php');

    } elseif ($action === 'trigger_backup') {
        $backupResult = $callHome->backupDatabase();
        if ($backupResult['status'] === 'success') {
            setSuccessMessage('Manual database backup successful: ' . $backupResult['file']);
            logActivity($pdo, 'Manual DB backup', 'system_settings', 0, "File: " . $backupResult['file']);
        } else {
            setErrorMessage('Manual database backup failed: ' . $backupResult['message']);
            logActivity($pdo, 'Manual DB backup failed', 'system_settings', 0, "Error: " . $backupResult['message']);
        }
        redirect(APP_URL . '/modules/settings/call_home.php');

    } elseif ($action === 'update_monitoring_settings') {
        $api_monitoring_enabled = isset($_POST['api_monitoring_enabled']) ? '1' : '0';
        $api_monitor_endpoints_raw = trim($_POST['api_monitor_endpoints'] ?? '[]');
        $critical_tables_to_monitor_raw = trim($_POST['critical_tables_to_monitor'] ?? '[]');

        // Validate JSON
        json_decode($api_monitor_endpoints_raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            setErrorMessage('API Endpoints must be valid JSON array.');
            redirect(APP_URL . '/modules/settings/call_home.php');
        }
        json_decode($critical_tables_to_monitor_raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            setErrorMessage('Critical Tables must be valid JSON array.');
            redirect(APP_URL . '/modules/settings/call_home.php');
        }

        $callHome->setSetting('api_monitoring_enabled', $api_monitoring_enabled);
        $callHome->setSetting('api_monitor_endpoints', $api_monitor_endpoints_raw);
        $callHome->setSetting('critical_tables_to_monitor', $critical_tables_to_monitor_raw);

        logActivity($pdo, 'Updated monitoring settings', 'system_settings', 0, "API Monitor: $api_monitoring_enabled");
        setSuccessMessage('API & Table monitoring settings updated.');
        redirect(APP_URL . '/modules/settings/call_home.php');

    } elseif ($action === 'trigger_monitor') {
        $monitorResult = $callHome->monitorHealth();
        if ($monitorResult['overall_status'] === 'healthy') {
            setSuccessMessage('Manual health monitoring completed: System is healthy.');
            logActivity($pdo, 'Manual health monitor', 'system_settings', 0, "Status: Healthy");
        } else {
            setErrorMessage('Manual health monitoring completed: Issues detected. Check logs.');
            logActivity($pdo, 'Manual health monitor', 'system_settings', 0, "Status: Unhealthy");
        }
        redirect(APP_URL . '/modules/settings/call_home.php');
    }
}

// Get current settings
$telemetryEnabled = $callHome->isTelemetryEnabled();
$lastCallHome = $callHome->getSetting('last_call_home');
$licenseKey = $callHome->getSetting('license_key', 'FREE_LICENSE');
$licenseStatus = $callHome->getSetting('license_status', 'unknown');

// Backup settings
$backupEnabled = $callHome->isBackupEnabled();
$backupFrequencyHours = $callHome->getSetting('backup_frequency_hours', 24);
$lastBackupTime = $callHome->getSetting('last_backup_time');

// Monitoring settings
$apiMonitoringEnabled = $callHome->isApiMonitoringEnabled();
$apiMonitorEndpoints = $callHome->getSetting('api_monitor_endpoints', '[]');
$criticalTablesToMonitor = $callHome->getSetting('critical_tables_to_monitor', '[]');

// Get pending notifications
$notifications = $callHome->getPendingNotifications();

// Get call home statistics
$stmt = $pdo->query("
    SELECT
        call_type,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
        MAX(created_at) as last_attempt
    FROM call_home_log
    GROUP BY call_type
");
$callHomeStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>

<div class="page-header" style="margin-bottom: 20px;">
    <h1><i class="fas fa-satellite-dish"></i> Call Home Settings</h1>
    <p class="text-muted">Manage system updates, telemetry, and license</p>
</div>

<!-- Status Cards -->
<div class="stats-grid" style="margin-bottom: 20px;">
    <div class="stat-card">
        <div class="stat-icon stat-icon-<?php echo $licenseStatus === 'valid' ? 'success' : 'danger'; ?>">
            <i class="fas fa-key"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo ucfirst($licenseStatus); ?></h3>
            <p>License Status</p>
            <div class="stat-trend">
                <span class="text-muted"><?php echo $licenseKey === 'FREE_LICENSE' ? 'Free License' : 'Licensed'; ?></span>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon stat-icon-info">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo $lastCallHome ? formatDateTime($lastCallHome) : 'Never'; ?></h3>
            <p>Last Call Home</p>
            <div class="stat-trend">
                <span class="text-muted">
                    <?php 
                    if ($lastCallHome) {
                        $hours = (time() - strtotime($lastCallHome)) / 3600;
                        echo round($hours, 1) . ' hours ago';
                    }
                    ?>
                </span>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon stat-icon-<?php echo $telemetryEnabled ? 'success' : 'warning'; ?>">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo $telemetryEnabled ? 'Enabled' : 'Disabled'; ?></h3>
            <p>Telemetry</p>
            <div class="stat-trend">
                <span class="text-muted">Anonymous usage data</span>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon stat-icon-primary">
            <i class="fas fa-bell"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo count($notifications); ?></h3>
            <p>Pending Notifications</p>
            <div class="stat-trend">
                <span class="text-muted">Updates & alerts</span>
            </div>
        </div>
    </div>

    <!-- New Backup Status Card -->
    <div class="stat-card">
        <div class="stat-icon stat-icon-<?php echo $backupEnabled ? 'success' : 'warning'; ?>">
            <i class="fas fa-database"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo $backupEnabled ? 'Enabled' : 'Disabled'; ?></h3>
            <p>DB Backups</p>
            <div class="stat-trend">
                <span class="text-muted">Last: <?php echo $lastBackupTime ? formatDateTime($lastBackupTime) : 'Never'; ?></span>
            </div>
        </div>
    </div>

    <!-- New Monitoring Status Card -->
    <div class="stat-card">
        <div class="stat-icon stat-icon-<?php echo $apiMonitoringEnabled ? 'success' : 'warning'; ?>">
            <i class="fas fa-heartbeat"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo $apiMonitoringEnabled ? 'Enabled' : 'Disabled'; ?></h3>
            <p>API & Table Monitoring</p>
            <div class="stat-trend">
                <span class="text-muted">Checks critical services</span>
            </div>
        </div>
    </div>
</div>

<!-- Pending Notifications -->
<?php if (!empty($notifications)): ?>
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">
        <h3><i class="fas fa-bell"></i> Pending Notifications</h3>
    </div>
    <div class="card-body">
        <?php foreach ($notifications as $notification): ?>
        <div class="alert alert-<?php 
            echo $notification['type'] === 'security' ? 'danger' : 
                ($notification['type'] === 'update' ? 'info' : 
                ($notification['type'] === 'license' ? 'warning' : 'success')); 
        ?>" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <strong>
                    <i class="fas fa-<?php 
                        echo $notification['type'] === 'security' ? 'shield-alt' : 
                            ($notification['type'] === 'update' ? 'download' : 
                            ($notification['type'] === 'license' ? 'key' : 'info-circle')); 
                    ?>"></i>
                    <?php echo e($notification['title']); ?>
                </strong>
                <p style="margin: 5px 0 0 0;"><?php echo e($notification['message']); ?></p>
                <?php if (isset($notification['action_url']) && $notification['action_url']): ?>
                <a href="<?php echo e($notification['action_url']); ?>" target="_blank" class="btn btn-sm btn-primary" style="margin-top: 10px;">
                    Learn More
                </a>
                <?php endif; ?>
            </div>
            <form method="POST" style="margin: 0;">
                <input type="hidden" name="action" value="mark_read">
                <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                <button type="submit" class="btn btn-sm btn-secondary">
                    <i class="fas fa-check"></i> Dismiss
                </button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Quick Actions -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">
        <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
    </div>
    <div class="card-body">
        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
            <form method="POST">
                <input type="hidden" name="action" value="check_updates">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-sync"></i> Check for Updates
                </button>
            </form>
            
            <form method="POST">
                <input type="hidden" name="action" value="validate_license">
                <button type="submit" class="btn btn-info">
                    <i class="fas fa-key"></i> Validate License
                </button>
            </form>

            <form method="POST">
                <input type="hidden" name="action" value="trigger_backup">
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-database"></i> Manual DB Backup
                </button>
            </form>

            <form method="POST">
                <input type="hidden" name="action" value="trigger_monitor">
                <button type="submit" class="btn btn-warning">
                    <i class="fas fa-heartbeat"></i> Manual Health Check
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Telemetry Settings -->
<div class="card" style="margin-bottom: 20px;">
    <form method="POST">
        <input type="hidden" name="action" value="toggle_telemetry">
        
        <div class="card-header">
            <h3><i class="fas fa-chart-line"></i> Telemetry Settings</h3>
        </div>
        
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <strong>What is Telemetry?</strong>
                <p style="margin: 10px 0 0 0;">
                    Telemetry sends anonymous usage statistics to help improve the system. 
                    No personal data or customer information is ever collected. 
                    Data includes: number of vehicles, jobs, invoices, and feature usage.
                </p>
            </div>
            
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 15px; border: 2px solid #e5e7eb; border-radius: 8px;">
                <input type="checkbox" name="telemetry_enabled" value="1" <?php echo $telemetryEnabled ? 'checked' : ''; ?>>
                <div>
                    <strong>Enable Anonymous Telemetry</strong>
                    <p class="text-muted" style="margin: 5px 0 0 0; font-size: 14px;">
                        Help us improve the system by sharing anonymous usage data
                    </p>
                </div>
            </label>
        </div>
        
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Telemetry Settings
            </button>
        </div>
    </form>
</div>

<!-- Database Backup Settings -->
<div class="card" style="margin-bottom: 20px;">
    <form method="POST">
        <input type="hidden" name="action" value="update_backup_settings">
        
        <div class="card-header">
            <h3><i class="fas fa-database"></i> Database Backup Settings</h3>
        </div>
        
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <p style="margin: 0;">
                    Automated database backups are performed by the cron job. Ensure `mysqldump` is in your system's PATH.
                    Backup files are stored in `<?php echo e(BACKUP_PATH); ?>`.
                </p>
            </div>
            
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 15px; border: 2px solid #e5e7eb; border-radius: 8px; margin-bottom: 20px;">
                <input type="checkbox" name="backup_enabled" value="1" <?php echo $backupEnabled ? 'checked' : ''; ?>>
                <div>
                    <strong>Enable Automatic Database Backups</strong>
                    <p class="text-muted" style="margin: 5px 0 0 0; font-size: 14px;">
                        The system will create regular backups of your database.
                    </p>
                </div>
            </label>

            <div class="form-group">
                <label for="backup_frequency_hours">Backup Frequency (Hours)</label>
                <input type="number" id="backup_frequency_hours" name="backup_frequency_hours" class="form-control" 
                       value="<?php echo e($backupFrequencyHours); ?>" min="1">
                <small class="form-hint">
                    How often (in hours) the cron job should attempt to backup the database.
                </small>
            </div>

            <p class="text-muted">Last Backup: <?php echo $lastBackupTime ? formatDateTime($lastBackupTime) : 'Never'; ?></p>
        </div>
        
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Backup Settings
            </button>
        </div>
    </form>
</div>

<!-- API & Table Monitoring Settings -->
<div class="card" style="margin-bottom: 20px;">
    <form method="POST">
        <input type="hidden" name="action" value="update_monitoring_settings">
        
        <div class="card-header">
            <h3><i class="fas fa-heartbeat"></i> API & Table Monitoring Settings</h3>
        </div>
        
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <p style="margin: 0;">
                    The system can monitor external API endpoints and critical database tables for accessibility and health.
                </p>
            </div>
            
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 15px; border: 2px solid #e5e7eb; border-radius: 8px; margin-bottom: 20px;">
                <input type="checkbox" name="api_monitoring_enabled" value="1" <?php echo $apiMonitoringEnabled ? 'checked' : ''; ?>>
                <div>
                    <strong>Enable API & Table Monitoring</strong>
                    <p class="text-muted" style="margin: 5px 0 0 0; font-size: 14px;">
                        Receive alerts if critical services or tables are unreachable.
                    </p>
                </div>
            </label>

            <div class="form-group">
                <label for="api_monitor_endpoints">API Endpoints to Monitor (JSON Array)</label>
                <textarea id="api_monitor_endpoints" name="api_monitor_endpoints" class="form-control" rows="5"
                          placeholder='["https://api.example.com/health", "https://another.api/status"]'><?php echo e($apiMonitorEndpoints); ?></textarea>
                <small class="form-hint">
                    Enter a JSON array of URLs (e.g., `["https://api.example.com/health"]`).
                </small>
            </div>

            <div class="form-group">
                <label for="critical_tables_to_monitor">Critical Database Tables to Monitor (JSON Array)</label>
                <textarea id="critical_tables_to_monitor" name="critical_tables_to_monitor" class="form-control" rows="3"
                          placeholder='["users", "jobs", "vehicles"]'><?php echo e($criticalTablesToMonitor); ?></textarea>
                <small class="form-hint">
                    Enter a JSON array of table names (e.g., `["users", "jobs"]`).
                </small>
            </div>
        </div>
        
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Monitoring Settings
            </button>
        </div>
    </form>
</div>

<!-- License Management -->
<div class="card" style="margin-bottom: 20px;">
    <form method="POST">
        <input type="hidden" name="action" value="update_license">
        
        <div class="card-header">
            <h3><i class="fas fa-key"></i> License Management</h3>
        </div>
        
        <div class="card-body">
            <div class="form-group">
                <label for="license_key">License Key</label>
                <input type="text" id="license_key" name="license_key" class="form-control" 
                       value="<?php echo e($licenseKey); ?>" placeholder="Enter license key">
                <small class="form-hint">
                    Current status: 
                    <span class="badge badge-<?php echo $licenseStatus === 'valid' ? 'success' : 'warning'; ?>">
                        <?php echo ucfirst($licenseStatus); ?>
                    </span>
                </small>
            </div>
            
            <?php if ($licenseKey === 'FREE_LICENSE'): ?>
            <div class="alert alert-warning">
                <i class="fas fa-info-circle"></i>
                You are using the free version. 
                <a href="https://yourdomain.com/pricing" target="_blank">Upgrade to Pro</a> for advanced features.
            </div>
            <?php endif; ?>
        </div>
        
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Update License Key
            </button>
        </div>
    </form>
</div>

<!-- Call Home Statistics -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-chart-bar"></i> Call Home Statistics</h3>
    </div>
    <div class="card-body">
        <?php if (empty($callHomeStats)): ?>
            <p class="text-center text-muted">No call home activity yet</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Total Attempts</th>
                        <th>Successful</th>
                        <th>Success Rate</th>
                        <th>Last Attempt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($callHomeStats as $stat): ?>
                    <tr>
                        <td>
                            <span class="badge badge-info">
                                <?php echo ucwords(str_replace('_', ' ', $stat['call_type'])); ?>
                            </span>
                        </td>
                        <td><?php echo $stat['total']; ?></td>
                        <td><?php echo $stat['successful']; ?></td>
                        <td>
                            <?php 
                            $rate = ($stat['successful'] / $stat['total']) * 100;
                            $color = $rate >= 90 ? 'success' : ($rate >= 70 ? 'warning' : 'danger');
                            ?>
                            <span class="badge badge-<?php echo $color; ?>">
                                <?php echo round($rate, 1); ?>%
                            </span>
                        </td>
                        <td><?php echo formatDateTime($stat['last_attempt']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>