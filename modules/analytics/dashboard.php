<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = 'Analytics & Reports';
$breadcrumbs = [
    ['text' => 'Analytics']
];

// Date range filter
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$dateTo = $_GET['date_to'] ?? date('Y-m-d'); // Today

// Revenue by period
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(invoice_date, '%Y-%m') as period,
        COUNT(*) as invoice_count,
        SUM(total_amount) as total_revenue,
        SUM(total_cost) as total_cost,
        SUM(total_profit) as total_profit,
        AVG(profit_percentage) as avg_margin
    FROM customer_invoices
    WHERE invoice_date BETWEEN ? AND ?
    GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
    ORDER BY period DESC
    LIMIT 12
");
$stmt->execute([$dateFrom, $dateTo]);
$revenueByPeriod = $stmt->fetchAll();

// Overall statistics for selected period
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_invoices,
        SUM(total_amount) as total_revenue,
        SUM(total_cost) as total_cost,
        SUM(total_profit) as total_profit,
        AVG(profit_percentage) as avg_margin,
        SUM(labor_total) as labor_revenue,
        SUM(parts_total) as parts_revenue,
        SUM(subcontract_total) as subcontract_revenue
    FROM customer_invoices
    WHERE invoice_date BETWEEN ? AND ?
");
$stmt->execute([$dateFrom, $dateTo]);
$stats = $stmt->fetch();

// Top vehicles by revenue
$stmt = $pdo->prepare("
    SELECT 
        v.number_plate,
        v.make,
        v.model,
        v.owner_name,
        COUNT(DISTINCT j.id) as job_count,
        COUNT(ci.id) as invoice_count,
        SUM(ci.total_amount) as total_spent,
        MAX(ci.invoice_date) as last_invoice
    FROM vehicles v
    JOIN jobs j ON v.id = j.vehicle_id
    LEFT JOIN customer_invoices ci ON j.id = ci.job_id
    WHERE ci.invoice_date BETWEEN ? AND ?
    GROUP BY v.id
    ORDER BY total_spent DESC
    LIMIT 10
");
$stmt->execute([$dateFrom, $dateTo]);
$topVehicles = $stmt->fetchAll();

// Invoice type breakdown
$stmt = $pdo->prepare("
    SELECT 
        invoice_type,
        COUNT(*) as count,
        SUM(total_amount) as revenue
    FROM customer_invoices
    WHERE invoice_date BETWEEN ? AND ?
    GROUP BY invoice_type
");
$stmt->execute([$dateFrom, $dateTo]);
$invoiceTypes = $stmt->fetchAll();

// Revenue by category
$revenueByCategory = [
    'Labor' => $stats['labor_revenue'] ?? 0,
    'Parts' => $stats['parts_revenue'] ?? 0,
    'Subcontracts' => $stats['subcontract_revenue'] ?? 0
];

// Recent activity
$stmt = $pdo->prepare("
    SELECT 
        al.action,
        al.table_name,
        al.record_id,
        al.details,
        al.created_at,
        u.full_name as user_name
    FROM activity_log al
    JOIN users u ON al.user_id = u.id
    WHERE al.created_at BETWEEN ? AND ?
    ORDER BY al.created_at DESC
    LIMIT 20
");
$stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$recentActivity = $stmt->fetchAll();

// Job status breakdown
$stmt = $pdo->prepare("
    SELECT 
        status,
        COUNT(*) as count
    FROM jobs
    WHERE created_at BETWEEN ? AND ?
    GROUP BY status
");
$stmt->execute([$dateFrom, $dateTo]);
$jobStatuses = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-chart-line"></i> Analytics & Reports</h1>
    <button onclick="window.print()" class="btn btn-secondary">
        <i class="fas fa-print"></i> Print Report
    </button>
</div>

<!-- Date Range Filter -->
<div class="card">
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 10px; align-items: center;">
            <label>Period:</label>
            <input type="date" name="date_from" class="form-control" value="<?php echo e($dateFrom); ?>" style="width: auto;">
            <span>to</span>
            <input type="date" name="date_to" class="form-control" value="<?php echo e($dateTo); ?>" style="width: auto;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-filter"></i> Apply Filter
            </button>
            <a href="?" class="btn btn-secondary">Reset</a>
        </form>
    </div>
</div>

<!-- Key Metrics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon stat-icon-success">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo formatCurrency($stats['total_revenue'] ?? 0); ?></h3>
            <p>Total Revenue</p>
            <div class="stat-trend">
                <span class="text-muted"><?php echo $stats['total_invoices'] ?? 0; ?> invoices</span>
            </div>
        </div>
    </div>
    
    <?php if (isDirector()): ?>
    <div class="stat-card">
        <div class="stat-icon stat-icon-warning">
            <i class="fas fa-dollar-sign"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo formatCurrency($stats['total_cost'] ?? 0); ?></h3>
            <p>Total Cost</p>
            <div class="stat-trend">
                <span class="text-muted">Direct costs</span>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon stat-icon-info">
            <i class="fas fa-chart-pie"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo formatCurrency($stats['total_profit'] ?? 0); ?></h3>
            <p>Total Profit</p>
            <div class="stat-trend">
                <span class="<?php echo ($stats['avg_margin'] ?? 0) >= 15 ? 'trend-up' : 'trend-down'; ?>">
                    <?php echo number_format($stats['avg_margin'] ?? 0, 1); ?>% avg margin
                </span>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="stat-card">
        <div class="stat-icon stat-icon-primary">
            <i class="fas fa-file-invoice"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo $stats['total_invoices'] ?? 0; ?></h3>
            <p>Invoices Generated</p>
            <div class="stat-trend">
                <span class="text-muted">
                    <?php echo formatCurrency(($stats['total_revenue'] ?? 0) / max(1, $stats['total_invoices'] ?? 1)); ?> avg
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Revenue Charts -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
    <!-- Revenue Trend -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-chart-line"></i> Revenue Trend (Monthly)</h3>
        </div>
        <div class="card-body">
            <canvas id="revenueTrendChart" height="250"></canvas>
        </div>
    </div>
    
    <!-- Revenue by Category -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-chart-pie"></i> Revenue Breakdown</h3>
        </div>
        <div class="card-body">
            <canvas id="categoryChart" height="250"></canvas>
            <div style="margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                    <span><i class="fas fa-users-cog" style="color: #667eea;"></i> Labor</span>
                    <strong><?php echo formatCurrency($revenueByCategory['Labor']); ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                    <span><i class="fas fa-cogs" style="color: #f59e0b;"></i> Parts</span>
                    <strong><?php echo formatCurrency($revenueByCategory['Parts']); ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 8px 0;">
                    <span><i class="fas fa-users" style="color: #10b981;"></i> Subcontracts</span>
                    <strong><?php echo formatCurrency($revenueByCategory['Subcontracts']); ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Top Vehicles -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-trophy"></i> Top Vehicles by Revenue</h3>
    </div>
    <div class="card-body">
        <?php if (empty($topVehicles)): ?>
            <p class="text-center text-muted">No data available for selected period</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Vehicle</th>
                        <th>Owner</th>
                        <th>Jobs</th>
                        <th>Invoices</th>
                        <th>Total Spent</th>
                        <th>Last Invoice</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rank = 1; foreach ($topVehicles as $vehicle): ?>
                    <tr>
                        <td>
                            <?php if ($rank <= 3): ?>
                                <span style="font-size: 20px;">
                                    <?php echo $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : '🥉'); ?>
                                </span>
                            <?php else: ?>
                                <?php echo $rank; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo e($vehicle['number_plate']); ?></strong><br>
                            <small class="text-muted"><?php echo e($vehicle['make'] . ' ' . $vehicle['model']); ?></small>
                        </td>
                        <td><?php echo e($vehicle['owner_name']); ?></td>
                        <td><?php echo $vehicle['job_count']; ?></td>
                        <td><?php echo $vehicle['invoice_count']; ?></td>
                        <td><strong><?php echo formatCurrency($vehicle['total_spent']); ?></strong></td>
                        <td><?php echo formatDate($vehicle['last_invoice']); ?></td>
                    </tr>
                    <?php $rank++; endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Job Status & Invoice Types -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
    <!-- Job Status -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-tasks"></i> Job Status Distribution</h3>
        </div>
        <div class="card-body">
            <?php if (empty($jobStatuses)): ?>
                <p class="text-muted">No jobs in selected period</p>
            <?php else: ?>
                <?php foreach ($jobStatuses as $status): ?>
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e5e7eb;">
                    <span><?php echo getStatusBadge($status['status']); ?></span>
                    <strong><?php echo $status['count']; ?> jobs</strong>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Invoice Types -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-file-invoice"></i> Invoice Type Distribution</h3>
        </div>
        <div class="card-body">
            <?php if (empty($invoiceTypes)): ?>
                <p class="text-muted">No invoices in selected period</p>
            <?php else: ?>
                <?php foreach ($invoiceTypes as $type): ?>
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e5e7eb;">
                    <span><?php echo getStatusBadge($type['invoice_type']); ?></span>
                    <div style="text-align: right;">
                        <strong><?php echo $type['count']; ?> invoices</strong><br>
                        <small class="text-muted"><?php echo formatCurrency($type['revenue']); ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent Activity -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> Recent Activity</h3>
    </div>
    <div class="card-body">
        <?php if (empty($recentActivity)): ?>
            <p class="text-center text-muted">No activity in selected period</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($recentActivity, 0, 15) as $activity): ?>
                    <tr>
                        <td><?php echo formatDateTime($activity['created_at']); ?></td>
                        <td><?php echo e($activity['user_name']); ?></td>
                        <td>
                            <span class="badge badge-info">
                                <?php echo e($activity['action']); ?>
                            </span>
                        </td>
                        <td><?php echo e($activity['details']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Chart.js Scripts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Revenue Trend Chart
const revenueTrendCtx = document.getElementById('revenueTrendChart');
const revenueTrendChart = new Chart(revenueTrendCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_reverse(array_column($revenueByPeriod, 'period'))); ?>,
        datasets: [{
            label: 'Revenue',
            data: <?php echo json_encode(array_reverse(array_column($revenueByPeriod, 'total_revenue'))); ?>,
            borderColor: '#667eea',
            backgroundColor: 'rgba(102, 126, 234, 0.1)',
            tension: 0.4,
            fill: true
        }<?php if (isDirector()): ?>,
        {
            label: 'Profit',
            data: <?php echo json_encode(array_reverse(array_column($revenueByPeriod, 'total_profit'))); ?>,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            tension: 0.4,
            fill: true
        }
        <?php endif; ?>]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top'
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return 'KES ' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

// Category Pie Chart
const categoryCtx = document.getElementById('categoryChart');
const categoryChart = new Chart(categoryCtx, {
    type: 'doughnut',
    data: {
        labels: ['Labor', 'Parts', 'Subcontracts'],
        datasets: [{
            data: [
                <?php echo $revenueByCategory['Labor']; ?>,
                <?php echo $revenueByCategory['Parts']; ?>,
                <?php echo $revenueByCategory['Subcontracts']; ?>
            ],
            backgroundColor: ['#667eea', '#f59e0b', '#10b981']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'bottom'
            }
        }
    }
});
</script>

<style>
@media print {
    .navbar, .breadcrumb, .page-header button, .btn {
        display: none !important;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>
