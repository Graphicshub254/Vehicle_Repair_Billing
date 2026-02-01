<?php
require_once '../../config/config.php';
requireLogin();
requireDirector(); // Only directors can view full audit trail

$pageTitle = 'Audit Trail';
$breadcrumbs = [
    ['text' => 'Audit Trail']
];

// Filters
$search = trim($_GET['search'] ?? '');
$user_filter = intval($_GET['user_id'] ?? 0);
$action_filter = $_GET['action'] ?? '';
$table_filter = $_GET['table'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Build query
$whereClause = '1=1';
$params = [];

if ($search) {
    $whereClause .= " AND (al.action LIKE ? OR al.details LIKE ? OR u.full_name LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

if ($user_filter > 0) {
    $whereClause .= " AND al.user_id = ?";
    $params[] = $user_filter;
}

if ($action_filter) {
    $whereClause .= " AND al.action = ?";
    $params[] = $action_filter;
}

if ($table_filter) {
    $whereClause .= " AND al.table_name = ?";
    $params[] = $table_filter;
}

if ($date_from) {
    $whereClause .= " AND DATE(al.created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $whereClause .= " AND DATE(al.created_at) <= ?";
    $params[] = $date_to;
}

// Get audit logs
$stmt = $pdo->prepare("
    SELECT al.*, u.full_name as user_name, u.username
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE $whereClause
    ORDER BY al.created_at DESC
    LIMIT 500
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get unique actions
$actionsStmt = $pdo->query("SELECT DISTINCT action FROM activity_log ORDER BY action");
$actions = $actionsStmt->fetchAll(PDO::FETCH_COLUMN);

// Get unique tables
$tablesStmt = $pdo->query("SELECT DISTINCT table_name FROM activity_log ORDER BY table_name");
$tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

// Get all users
$usersStmt = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name");
$users = $usersStmt->fetchAll();

// Get summary statistics
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_entries,
        COUNT(DISTINCT user_id) as unique_users,
        COUNT(DISTINCT action) as unique_actions,
        COUNT(DISTINCT table_name) as unique_tables
    FROM activity_log
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$statsStmt->execute([$date_from, $date_to]);
$stats = $statsStmt->fetch();

include '../../includes/header.php';
?>

<div class="page-header" style="margin-bottom: 20px;">
    <h1><i class="fas fa-history"></i> Audit Trail</h1>
    <p class="text-muted">Complete system activity log and security audit</p>
</div>

<!-- Summary Statistics -->
<div class="stats-grid" style="margin-bottom: 20px;">
    <div class="stat-card">
        <div class="stat-icon stat-icon-primary">
            <i class="fas fa-list"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo number_format($stats['total_entries']); ?></h3>
            <p>Total Entries</p>
            <div class="stat-trend">
                <span class="text-muted">In selected period</span>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon stat-icon-info">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo $stats['unique_users']; ?></h3>
            <p>Active Users</p>
            <div class="stat-trend">
                <span class="text-muted">Made changes</span>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon stat-icon-warning">
            <i class="fas fa-cogs"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo $stats['unique_actions']; ?></h3>
            <p>Action Types</p>
            <div class="stat-trend">
                <span class="text-muted">Different operations</span>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon stat-icon-success">
            <i class="fas fa-database"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo $stats['unique_tables']; ?></h3>
            <p>Tables Modified</p>
            <div class="stat-trend">
                <span class="text-muted">Database changes</span>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-filter"></i> Filters</h3>
    </div>
    <div class="card-body">
        <form method="GET">
            <div class="form-row">
                <div class="form-group">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" class="form-control" 
                           placeholder="Search action, details, or user..." value="<?php echo e($search); ?>">
                </div>
                
                <div class="form-group">
                    <label for="user_id">User</label>
                    <select id="user_id" name="user_id" class="form-control">
                        <option value="0">All Users</option>
                        <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo $user_filter == $user['id'] ? 'selected' : ''; ?>>
                            <?php echo e($user['full_name']); ?> (<?php echo e($user['username']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="action">Action Type</label>
                    <select id="action" name="action" class="form-control">
                        <option value="">All Actions</option>
                        <?php foreach ($actions as $action): ?>
                        <option value="<?php echo e($action); ?>" <?php echo $action_filter === $action ? 'selected' : ''; ?>>
                            <?php echo e($action); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="table">Table</label>
                    <select id="table" name="table" class="form-control">
                        <option value="">All Tables</option>
                        <?php foreach ($tables as $table): ?>
                        <option value="<?php echo e($table); ?>" <?php echo $table_filter === $table ? 'selected' : ''; ?>>
                            <?php echo e($table); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="date_from">From Date</label>
                    <input type="date" id="date_from" name="date_from" class="form-control" value="<?php echo e($date_from); ?>">
                </div>
                
                <div class="form-group">
                    <label for="date_to">To Date</label>
                    <input type="date" id="date_to" name="date_to" class="form-control" value="<?php echo e($date_to); ?>">
                </div>
                
                <div class="form-group" style="align-self: flex-end;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <a href="?" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Audit Log Table -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-list"></i> Activity Log</h3>
        <small class="text-muted">Showing last 500 entries</small>
    </div>
    <div class="card-body">
        <?php if (empty($logs)): ?>
            <p class="text-center text-muted">No activity log entries found matching your filters</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Table</th>
                        <th>Record ID</th>
                        <th>Details</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo formatDateTime($log['created_at']); ?></td>
                        <td>
                            <?php if ($log['user_name']): ?>
                                <strong><?php echo e($log['user_name']); ?></strong><br>
                                <small class="text-muted"><?php echo e($log['username']); ?></small>
                            <?php else: ?>
                                <span class="text-muted">System</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php 
                                echo strpos($log['action'], 'Created') !== false ? 'badge-success' : 
                                    (strpos($log['action'], 'Updated') !== false ? 'badge-warning' : 
                                    (strpos($log['action'], 'Deleted') !== false ? 'badge-danger' : 
                                    (strpos($log['action'], 'Approved') !== false ? 'badge-info' : 
                                    'badge-secondary')));
                            ?>">
                                <?php echo e($log['action']); ?>
                            </span>
                        </td>
                        <td>
                            <code style="font-size: 12px; background: #f3f4f6; padding: 2px 6px; border-radius: 4px;">
                                <?php echo e($log['table_name']); ?>
                            </code>
                        </td>
                        <td><?php echo $log['record_id'] ?: '-'; ?></td>
                        <td>
                            <?php if ($log['details']): ?>
                                <small><?php echo e(substr($log['details'], 0, 100)) . (strlen($log['details']) > 100 ? '...' : ''); ?></small>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small>
                                <?php echo $log['ip_address'] ? e($log['ip_address']) : '-'; ?>
                            </small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Export Options -->
<div class="card">
    <div class="card-body">
        <div style="text-align: center;">
            <p class="text-muted">
                <i class="fas fa-info-circle"></i>
                Audit trail data is retained for compliance and security purposes. 
                Showing most recent 500 entries.
            </p>
            <button onclick="window.print()" class="btn btn-secondary">
                <i class="fas fa-print"></i> Print Audit Trail
            </button>
        </div>
    </div>
</div>

<style>
@media print {
    .navbar, .breadcrumb, .page-header, .card:not(:last-child), .btn, .form-row {
        display: none !important;
    }
    
    .card-header h3 {
        font-size: 18px;
        margin-bottom: 10px;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>
