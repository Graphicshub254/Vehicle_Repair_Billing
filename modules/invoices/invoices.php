<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = 'Customer Invoices';
$breadcrumbs = [
    ['text' => 'Invoices']
];

// Search and filter
$search = trim($_GET['search'] ?? '');
$typeFilter = $_GET['type'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$whereClause = '1=1';
$params = [];

if ($search) {
    $whereClause .= " AND (ci.invoice_number LIKE ? OR j.job_number LIKE ? OR v.number_plate LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

if ($typeFilter) {
    $whereClause .= " AND ci.invoice_type = ?";
    $params[] = $typeFilter;
}

if ($dateFrom) {
    $whereClause .= " AND ci.invoice_date >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $whereClause .= " AND ci.invoice_date <= ?";
    $params[] = $dateTo;
}

// Get invoices
$stmt = $pdo->prepare("
    SELECT ci.*, j.job_number, v.number_plate, v.make, v.model, u.full_name as generated_by_name
    FROM customer_invoices ci
    JOIN jobs j ON ci.job_id = j.id
    JOIN vehicles v ON j.vehicle_id = v.id
    JOIN users u ON ci.generated_by = u.id
    WHERE $whereClause
    ORDER BY ci.created_at DESC
");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// Calculate totals
$totalRevenue = 0;
$totalProfit = 0;
foreach ($invoices as $inv) {
    $totalRevenue += $inv['total_amount'];
    $totalProfit += $inv['total_profit'];
}

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-file-invoice-dollar"></i> Customer Invoices</h1>
</div>

<!-- Summary Stats -->
<?php if (!empty($invoices)): ?>
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon stat-icon-primary">
            <i class="fas fa-file-invoice"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo count($invoices); ?></h3>
            <p>Total Invoices</p>
            <?php if ($search || $typeFilter || $dateFrom || $dateTo): ?>
                <div class="stat-trend">
                    <span class="text-muted">Filtered results</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon stat-icon-success">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo formatCurrency($totalRevenue); ?></h3>
            <p>Total Revenue</p>
        </div>
    </div>
    
    <?php if (isDirector()): ?>
    <div class="stat-card">
        <div class="stat-icon stat-icon-info">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo formatCurrency($totalProfit); ?></h3>
            <p>Total Profit</p>
            <div class="stat-trend">
                <span class="text-muted">
                    <?php echo $totalRevenue > 0 ? number_format(($totalProfit / $totalRevenue) * 100, 1) : 0; ?>% margin
                </span>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Search and Filter -->
<div class="card">
    <div class="card-body">
        <form method="GET">
            <div style="display: grid; grid-template-columns: 1fr 150px 150px 150px auto; gap: 10px;">
                <input type="text" name="search" class="form-control" placeholder="Search by invoice #, job #, or vehicle..." value="<?php echo e($search); ?>">
                <select name="type" class="form-control">
                    <option value="">All Types</option>
                    <option value="progress" <?php echo $typeFilter === 'progress' ? 'selected' : ''; ?>>Progress</option>
                    <option value="final" <?php echo $typeFilter === 'final' ? 'selected' : ''; ?>>Final</option>
                </select>
                <input type="date" name="date_from" class="form-control" placeholder="From" value="<?php echo e($dateFrom); ?>">
                <input type="date" name="date_to" class="form-control" placeholder="To" value="<?php echo e($dateTo); ?>">
                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <?php if ($search || $typeFilter || $dateFrom || $dateTo): ?>
                    <a href="?" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Invoices Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($invoices)): ?>
            <p class="text-center text-muted">
                <?php if ($search || $typeFilter || $dateFrom || $dateTo): ?>
                    No invoices found matching your criteria
                <?php else: ?>
                    No invoices generated yet. Complete a job and <a href="<?php echo APP_URL; ?>/modules/jobs/jobs.php">generate your first invoice</a>
                <?php endif; ?>
            </p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Job #</th>
                        <th>Vehicle</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th>Total Amount</th>
                        <?php if (isDirector()): ?>
                        <th>Profit</th>
                        <?php endif; ?>
                        <th>Generated By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $invoice): ?>
                    <tr>
                        <td>
                            <strong><?php echo e($invoice['invoice_number']); ?></strong>
                            <?php if ($invoice['reprint_count'] > 0): ?>
                                <br><small class="text-muted">Reprinted <?php echo $invoice['reprint_count']; ?>x</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e($invoice['job_number']); ?></td>
                        <td>
                            <?php echo e($invoice['number_plate']); ?><br>
                            <small class="text-muted"><?php echo e($invoice['make'] . ' ' . $invoice['model']); ?></small>
                        </td>
                        <td><?php echo getStatusBadge($invoice['invoice_type']); ?></td>
                        <td><?php echo formatDate($invoice['invoice_date']); ?></td>
                        <td><strong><?php echo formatCurrency($invoice['total_amount']); ?></strong></td>
                        <?php if (isDirector()): ?>
                        <td><?php echo displayProfit($invoice['total_profit'], $invoice['profit_percentage']); ?></td>
                        <?php endif; ?>
                        <td><?php echo e($invoice['generated_by_name']); ?></td>
                        <td>
                            <div class="table-actions">
                                <a href="view_invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="view_invoice.php?id=<?php echo $invoice['id']; ?>&reprint=1" class="btn btn-sm btn-secondary">
                                    <i class="fas fa-copy"></i> Reprint
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

<?php include '../../includes/footer.php'; ?>
