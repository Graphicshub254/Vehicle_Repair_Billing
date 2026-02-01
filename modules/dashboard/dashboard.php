<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = 'Dashboard';
$breadcrumbs = [
    ['text' => 'Dashboard']
];

// Get statistics
$stats = [];

// Total Jobs
$stmt = $pdo->query("SELECT COUNT(*) as total FROM jobs");
$stats['total_jobs'] = $stmt->fetch()['total'];

// Open Jobs
$stmt = $pdo->query("SELECT COUNT(*) as total FROM jobs WHERE status IN ('open', 'awaiting_quotation_approval', 'awaiting_parts', 'in_progress', 'with_subcontractor', 'completed')");
$stats['open_jobs'] = $stmt->fetch()['total'];

// Total Revenue (from customer invoices)
$stmt = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM customer_invoices");
$stats['total_revenue'] = $stmt->fetch()['total'];

// Total Profit (from customer invoices)
$stmt = $pdo->query("SELECT COALESCE(SUM(total_profit), 0) as total FROM customer_invoices");
$stats['total_profit'] = $stmt->fetch()['total'];

// Average Profit Margin
if ($stats['total_revenue'] > 0) {
    $stats['avg_margin'] = ($stats['total_profit'] / $stats['total_revenue']) * 100;
} else {
    $stats['avg_margin'] = 0;
}

// Pending Approvals (for directors)
if (isDirector()) {
    $stmt = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM quotations WHERE status = 'pending_approval') +
            (SELECT COUNT(*) FROM subcontract_works WHERE status = 'pending_approval') as total
    ");
    $stats['pending_approvals'] = $stmt->fetch()['total'];
}

// Recent Jobs
$stmt = $pdo->query("
    SELECT j.*, v.number_plate, v.make, v.model, u.full_name as created_by_name
    FROM jobs j
    JOIN vehicles v ON j.vehicle_id = v.id
    JOIN users u ON j.created_by = u.id
    ORDER BY j.created_at DESC
    LIMIT 10
");
$recent_jobs = $stmt->fetchAll();

// Recent Invoices
$stmt = $pdo->query("
    SELECT ci.*, j.job_number, v.number_plate
    FROM customer_invoices ci
    JOIN jobs j ON ci.job_id = j.id
    JOIN vehicles v ON j.vehicle_id = v.id
    ORDER BY ci.created_at DESC
    LIMIT 5
");
$recent_invoices = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-home"></i> Dashboard</h1>
</div>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon stat-icon-primary">
            <i class="fas fa-tasks"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo number_format($stats['total_jobs']); ?></h3>
            <p>Total Jobs</p>
            <div class="stat-trend">
                <span class="text-muted"><?php echo number_format($stats['open_jobs']); ?> active</span>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon stat-icon-success">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo formatCurrency($stats['total_revenue']); ?></h3>
            <p>Total Revenue</p>
            <?php if (isDirector()): ?>
            <div class="stat-trend">
                <span class="text-muted">All time</span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (isDirector()): ?>
    <div class="stat-card">
        <div class="stat-icon stat-icon-info">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo formatCurrency($stats['total_profit']); ?></h3>
            <p>Total Profit</p>
            <div class="stat-trend">
                <span class="text-muted"><?php echo number_format($stats['avg_margin'], 1); ?>% margin</span>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon stat-icon-warning">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo number_format($stats['pending_approvals']); ?></h3>
            <p>Pending Approvals</p>
            <?php if ($stats['pending_approvals'] > 0): ?>
            <div class="stat-trend">
                <a href="<?php echo APP_URL; ?>/modules/quotations/approve_quotation.php" class="btn btn-sm btn-warning">Review Now</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="stat-card">
        <div class="stat-icon stat-icon-info">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo number_format($stats['avg_margin'], 1); ?>%</h3>
            <p>Average Margin</p>
            <div class="stat-trend">
                <?php echo getProfitMarginBand($stats['avg_margin']); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Quick Actions -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
            <a href="<?php echo APP_URL; ?>/modules/jobs/jobs.php?action=create" class="btn btn-primary">
                <i class="fas fa-plus"></i> New Job
            </a>
            <a href="<?php echo APP_URL; ?>/modules/vehicles/vehicles.php?action=create" class="btn btn-success">
                <i class="fas fa-car"></i> New Vehicle
            </a>
            <?php if (isProcurementOfficer() || isDirector()): ?>
            <a href="<?php echo APP_URL; ?>/modules/quotations/create_quotation.php" class="btn btn-info">
                <i class="fas fa-file-invoice"></i> New Quotation
            </a>
            <?php endif; ?>
            <a href="<?php echo APP_URL; ?>/modules/labor/add_labor.php" class="btn btn-secondary">
                <i class="fas fa-users-cog"></i> Add Labor
            </a>
        </div>
    </div>
</div>

<!-- Recent Jobs -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-wrench"></i> Recent Jobs</h3>
        <a href="<?php echo APP_URL; ?>/modules/jobs/jobs.php" class="btn btn-sm btn-primary">View All</a>
    </div>
    <div class="card-body">
        <?php if (empty($recent_jobs)): ?>
            <p class="text-muted text-center">No jobs yet. <a href="<?php echo APP_URL; ?>/modules/jobs/jobs.php?action=create">Create your first job</a></p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Job Number</th>
                        <th>Vehicle</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Created By</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_jobs as $job): ?>
                    <tr>
                        <td><strong><?php echo e($job['job_number']); ?></strong></td>
                        <td><?php echo e($job['number_plate']); ?> - <?php echo e($job['make'] . ' ' . $job['model']); ?></td>
                        <td><?php echo e(substr($job['description'], 0, 50)) . (strlen($job['description']) > 50 ? '...' : ''); ?></td>
                        <td><?php echo getStatusBadge($job['status']); ?></td>
                        <td><?php echo e($job['created_by_name']); ?></td>
                        <td><?php echo formatDate($job['created_at']); ?></td>
                        <td>
                            <div class="table-actions">
                                <a href="<?php echo APP_URL; ?>/modules/jobs/job_details.php?id=<?php echo $job['id']; ?>" class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Invoices -->
<?php if (!empty($recent_invoices)): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-file-invoice-dollar"></i> Recent Invoices</h3>
        <a href="<?php echo APP_URL; ?>/modules/invoices/invoices.php" class="btn btn-sm btn-primary">View All</a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice Number</th>
                        <th>Job</th>
                        <th>Vehicle</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <?php if (isDirector()): ?>
                        <th>Profit</th>
                        <?php endif; ?>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_invoices as $invoice): ?>
                    <tr>
                        <td><strong><?php echo e($invoice['invoice_number']); ?></strong></td>
                        <td><?php echo e($invoice['job_number']); ?></td>
                        <td><?php echo e($invoice['number_plate']); ?></td>
                        <td><?php echo getStatusBadge($invoice['invoice_type']); ?></td>
                        <td><?php echo formatCurrency($invoice['total_amount']); ?></td>
                        <?php if (isDirector()): ?>
                        <td><?php echo displayProfit($invoice['total_profit'], $invoice['profit_percentage']); ?></td>
                        <?php endif; ?>
                        <td><?php echo formatDate($invoice['invoice_date']); ?></td>
                        <td>
                            <div class="table-actions">
                                <a href="<?php echo APP_URL; ?>/modules/invoices/view_invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
