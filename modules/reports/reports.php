<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = 'Advanced Reports';
$breadcrumbs = [
    ['text' => 'Reports']
];

$reportType = $_GET['type'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-file-alt"></i> Advanced Reports</h1>
    <?php if ($reportType): ?>
    <button onclick="window.print()" class="btn btn-primary">
        <i class="fas fa-print"></i> Print Report
    </button>
    <?php endif; ?>
</div>

<!-- Report Selection -->
<?php if (!$reportType): ?>
<div class="card">
    <div class="card-header">
        <h3>Select Report Type</h3>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <!-- Revenue Report -->
            <a href="?type=revenue&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" class="report-card">
                <div class="report-icon" style="background: #667eea;">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <h3>Revenue Report</h3>
                <p>Detailed breakdown of all revenue by category, period, and customer</p>
            </a>
            
            <!-- Profit Analysis -->
            <?php if (isDirector()): ?>
            <a href="?type=profit&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" class="report-card">
                <div class="report-icon" style="background: #10b981;">
                    <i class="fas fa-chart-line"></i>
                </div>
                <h3>Profit Analysis</h3>
                <p>Comprehensive profit breakdown by job, category, and time period</p>
            </a>
            <?php endif; ?>
            
            <!-- Customer Report -->
            <a href="?type=customer&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" class="report-card">
                <div class="report-icon" style="background: #f59e0b;">
                    <i class="fas fa-users"></i>
                </div>
                <h3>Customer Report</h3>
                <p>Customer spending analysis and vehicle service history</p>
            </a>
            
            <!-- Job Status Report -->
            <a href="?type=jobs&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" class="report-card">
                <div class="report-icon" style="background: #ef4444;">
                    <i class="fas fa-tasks"></i>
                </div>
                <h3>Job Status Report</h3>
                <p>Overview of all jobs by status, age, and completion rate</p>
            </a>
            
            <!-- Inventory Report -->
            <a href="?type=inventory&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" class="report-card">
                <div class="report-icon" style="background: #8b5cf6;">
                    <i class="fas fa-boxes"></i>
                </div>
                <h3>Parts Inventory</h3>
                <p>Parts received, installed, and pending installation</p>
            </a>
            
            <!-- Vendor Report -->
            <a href="?type=vendors&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" class="report-card">
                <div class="report-icon" style="background: #06b6d4;">
                    <i class="fas fa-truck"></i>
                </div>
                <h3>Vendor Report</h3>
                <p>Analysis of supplier and subcontractor performance</p>
            </a>
        </div>
    </div>
</div>

<!-- Date Range -->
<div class="card">
    <div class="card-header">
        <h3>Report Period</h3>
    </div>
    <div class="card-body">
        <form method="GET">
            <div style="display: flex; gap: 10px; align-items: center;">
                <label>From:</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo e($dateFrom); ?>" style="width: auto;">
                <label>To:</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo e($dateTo); ?>" style="width: auto;">
                <button type="submit" class="btn btn-primary">Update Period</button>
            </div>
        </form>
    </div>
</div>

<?php elseif ($reportType === 'revenue'): ?>
<!-- Revenue Report -->
<?php
$stmt = $pdo->prepare("
    SELECT 
        ci.invoice_number,
        ci.invoice_date,
        ci.invoice_type,
        j.job_number,
        v.number_plate,
        v.owner_name,
        ci.labor_total,
        ci.isuzu_parts_total AS parts_total,
        (ci.subcontract_parts_total + ci.subcontract_service_total) AS subcontract_total,
        ci.total_amount
    FROM customer_invoices ci
    JOIN jobs j ON ci.job_id = j.id
    JOIN vehicles v ON j.vehicle_id = v.id
    WHERE ci.invoice_date BETWEEN ? AND ?
    ORDER BY ci.invoice_date DESC
");
$stmt->execute([$dateFrom, $dateTo]);
$invoices = $stmt->fetchAll();

$totals = [
    'labor' => 0,
    'parts' => 0,
    'subcontract' => 0,
    'total' => 0
];

foreach ($invoices as $inv) {
    $totals['labor'] += $inv['labor_total'];
    $totals['parts'] += $inv['parts_total'];
    $totals['subcontract'] += $inv['subcontract_total'];
    $totals['total'] += $inv['total_amount'];
}
?>

<div class="card">
    <div class="card-header">
        <h3>Revenue Report</h3>
        <p class="text-muted">Period: <?php echo formatDate($dateFrom); ?> to <?php echo formatDate($dateTo); ?></p>
    </div>
    <div class="card-body">
        <!-- Summary -->
        <div class="stats-grid" style="margin-bottom: 30px;">
            <div class="stat-card">
                <div class="stat-content">
                    <h3><?php echo formatCurrency($totals['labor']); ?></h3>
                    <p>Labor Revenue</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <h3><?php echo formatCurrency($totals['parts']); ?></h3>
                    <p>Parts Revenue</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <h3><?php echo formatCurrency($totals['subcontract']); ?></h3>
                    <p>Subcontract Revenue</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <h3><?php echo formatCurrency($totals['total']); ?></h3>
                    <p>Total Revenue</p>
                </div>
            </div>
        </div>
        
        <!-- Detailed List -->
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th>Job #</th>
                        <th>Vehicle</th>
                        <th>Customer</th>
                        <th>Labor</th>
                        <th>Parts</th>
                        <th>Subcontract</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                    <tr>
                        <td><?php echo e($inv['invoice_number']); ?></td>
                        <td><?php echo formatDate($inv['invoice_date']); ?></td>
                        <td><?php echo e($inv['job_number']); ?></td>
                        <td><?php echo e($inv['number_plate']); ?></td>
                        <td><?php echo e($inv['owner_name']); ?></td>
                        <td><?php echo formatCurrency($inv['labor_total']); ?></td>
                        <td><?php echo formatCurrency($inv['parts_total']); ?></td>
                        <td><?php echo formatCurrency($inv['subcontract_total']); ?></td>
                        <td><strong><?php echo formatCurrency($inv['total_amount']); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight: bold; background: #f9fafb;">
                        <td colspan="5">TOTALS:</td>
                        <td><?php echo formatCurrency($totals['labor']); ?></td>
                        <td><?php echo formatCurrency($totals['parts']); ?></td>
                        <td><?php echo formatCurrency($totals['subcontract']); ?></td>
                        <td><?php echo formatCurrency($totals['total']); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<div style="margin-top: 20px; text-align: center;">
    <a href="?" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Report Selection
    </a>
</div>

<?php elseif ($reportType === 'customer'): ?>
<!-- Customer Report -->
<?php
$stmt = $pdo->prepare("
    SELECT 
        v.id,
        v.number_plate,
        v.make,
        v.model,
        v.owner_name,
        v.owner_phone,
        COUNT(DISTINCT j.id) as job_count,
        COUNT(ci.id) as invoice_count,
        SUM(ci.total_amount) as total_spent,
        MAX(ci.invoice_date) as last_invoice,
        MIN(j.created_at) as first_visit
    FROM vehicles v
    LEFT JOIN jobs j ON v.id = j.vehicle_id
    LEFT JOIN customer_invoices ci ON j.id = ci.job_id AND ci.invoice_date BETWEEN ? AND ?
    GROUP BY v.id
    HAVING invoice_count > 0
    ORDER BY total_spent DESC
");
$stmt->execute([$dateFrom, $dateTo]);
$customers = $stmt->fetchAll();
?>

<div class="card">
    <div class="card-header">
        <h3>Customer Report</h3>
        <p class="text-muted">Period: <?php echo formatDate($dateFrom); ?> to <?php echo formatDate($dateTo); ?></p>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Vehicle</th>
                        <th>Owner</th>
                        <th>Contact</th>
                        <th>Jobs</th>
                        <th>Invoices</th>
                        <th>Total Spent</th>
                        <th>Last Invoice</th>
                        <th>Customer Since</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td>
                            <strong><?php echo e($customer['number_plate']); ?></strong><br>
                            <small><?php echo e($customer['make'] . ' ' . $customer['model']); ?></small>
                        </td>
                        <td><?php echo e($customer['owner_name']); ?></td>
                        <td><?php echo e($customer['owner_phone']) ?: '-'; ?></td>
                        <td><?php echo $customer['job_count']; ?></td>
                        <td><?php echo $customer['invoice_count']; ?></td>
                        <td><strong><?php echo formatCurrency($customer['total_spent']); ?></strong></td>
                        <td><?php echo formatDate($customer['last_invoice']); ?></td>
                        <td><?php echo formatDate($customer['first_visit']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div style="margin-top: 20px; text-align: center;">
    <a href="?" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Report Selection
    </a>
</div>

<?php elseif ($reportType === 'profit'): ?>
<!-- Profit Analysis Report -->
<?php
$stmt = $pdo->prepare("
    SELECT 
        ci.invoice_number,
        ci.invoice_date,
        ci.total_amount AS revenue,
        ci.total_cost,
        ci.total_profit,
        ci.profit_percentage,
        j.job_number,
        v.number_plate,
        v.owner_name
    FROM customer_invoices ci
    JOIN jobs j ON ci.job_id = j.id
    JOIN vehicles v ON j.vehicle_id = v.id
    WHERE ci.invoice_date BETWEEN ? AND ?
    ORDER BY ci.invoice_date DESC
");
$stmt->execute([$dateFrom, $dateTo]);
$profitReports = $stmt->fetchAll();

$overallTotals = [
    'revenue' => 0,
    'cost' => 0,
    'profit' => 0,
];

foreach ($profitReports as $report) {
    $overallTotals['revenue'] += $report['revenue'];
    $overallTotals['cost'] += $report['total_cost'];
    $overallTotals['profit'] += $report['total_profit'];
}

$overallMargin = ($overallTotals['revenue'] > 0) ? ($overallTotals['profit'] / $overallTotals['revenue']) * 100 : 0;
?>

<div class="card">
    <div class="card-header">
        <h3>Profit Analysis Report</h3>
        <p class="text-muted">Period: <?php echo formatDate($dateFrom); ?> to <?php echo formatDate($dateTo); ?></p>
    </div>
    <div class="card-body">
        <!-- Summary -->
        <div class="stats-grid" style="margin-bottom: 30px;">
            <div class="stat-card">
                <div class="stat-content">
                    <h3><?php echo formatCurrency($overallTotals['revenue']); ?></h3>
                    <p>Total Revenue</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <h3><?php echo formatCurrency($overallTotals['cost']); ?></h3>
                    <p>Total Cost</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <h3><?php echo formatCurrency($overallTotals['profit']); ?></h3>
                    <p>Total Profit</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <h3><?php echo number_format($overallMargin, 1); ?>%</h3>
                    <p>Overall Margin</p>
                </div>
            </div>
        </div>
        
        <!-- Detailed List -->
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th>Job #</th>
                        <th>Vehicle</th>
                        <th>Customer</th>
                        <th>Revenue</th>
                        <th>Cost</th>
                        <th>Profit</th>
                        <th>Margin</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($profitReports as $report): ?>
                    <tr>
                        <td><?php echo e($report['invoice_number']); ?></td>
                        <td><?php echo formatDate($report['invoice_date']); ?></td>
                        <td><?php echo e($report['job_number']); ?></td>
                        <td><?php echo e($report['number_plate']); ?></td>
                        <td><?php echo e($report['owner_name']); ?></td>
                        <td><?php echo formatCurrency($report['revenue']); ?></td>
                        <td><?php echo formatCurrency($report['total_cost']); ?></td>
                        <td><?php echo formatCurrency($report['total_profit']); ?></td>
                        <td><?php echo number_format($report['profit_percentage'], 1); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight: bold; background: #f9fafb;">
                        <td colspan="5">OVERALL TOTALS:</td>
                        <td><?php echo formatCurrency($overallTotals['revenue']); ?></td>
                        <td><?php echo formatCurrency($overallTotals['cost']); ?></td>
                        <td><?php echo formatCurrency($overallTotals['profit']); ?></td>
                        <td><?php echo number_format($overallMargin, 1); ?>%</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<div style="margin-top: 20px; text-align: center;">
    <a href="?" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Report Selection
    </a>
</div>

<?php elseif ($reportType === 'jobs'): ?>
<!-- Job Status Report -->
<?php
$stmt = $pdo->prepare("
    SELECT 
        j.job_number,
        j.description,
        j.status,
        j.start_date,
        j.completion_date,
        j.created_at,
        v.number_plate,
        v.make,
        v.model,
        v.owner_name
    FROM jobs j
    JOIN vehicles v ON j.vehicle_id = v.id
    WHERE j.start_date BETWEEN ? AND ?
    ORDER BY j.created_at DESC
");
$stmt->execute([$dateFrom, $dateTo]);
$jobsReport = $stmt->fetchAll();

$statusCounts = [];
foreach ($jobsReport as $job) {
    $statusCounts[$job['status']] = ($statusCounts[$job['status']] ?? 0) + 1;
}

// Convert status counts to a display-friendly array
$displayStatusCounts = [
    'open' => ['label' => 'Open', 'count' => 0, 'class' => 'badge-open'],
    'awaiting_quotation_approval' => ['label' => 'Awaiting Approval', 'count' => 0, 'class' => 'badge-warning'],
    'awaiting_parts' => ['label' => 'Awaiting Parts', 'count' => 0, 'class' => 'badge-info'],
    'in_progress' => ['label' => 'In Progress', 'count' => 0, 'class' => 'badge-in_progress'],
    'with_subcontractor' => ['label' => 'With Subcontractor', 'count' => 0, 'class' => 'badge-secondary'],
    'completed' => ['label' => 'Completed', 'count' => 0, 'class' => 'badge-completed'],
    'invoiced' => ['label' => 'Invoiced', 'count' => 0, 'class' => 'badge-success'],
];

foreach ($statusCounts as $status => $count) {
    if (isset($displayStatusCounts[$status])) {
        $displayStatusCounts[$status]['count'] = $count;
    } else {
        // Handle any unexpected status
        $displayStatusCounts[$status] = ['label' => ucwords(str_replace('_', ' ', $status)), 'count' => $count, 'class' => 'badge-secondary'];
    }
}
?>

<div class="card">
    <div class="card-header">
        <h3>Job Status Report</h3>
        <p class="text-muted">Period: <?php echo formatDate($dateFrom); ?> to <?php echo formatDate($dateTo); ?></p>
    </div>
    <div class="card-body">
        <!-- Summary -->
        <div class="stats-grid" style="margin-bottom: 30px;">
            <?php foreach ($displayStatusCounts as $status => $data): ?>
            <div class="stat-card">
                <div class="stat-content">
                    <h3><?php echo $data['count']; ?></h3>
                    <p><?php echo $data['label']; ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Detailed List -->
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Job #</th>
                        <th>Vehicle</th>
                        <th>Owner</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Start Date</th>
                        <th>Completion Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobsReport as $job): ?>
                    <tr>
                        <td><?php echo e($job['job_number']); ?></td>
                        <td><?php echo e($job['number_plate'] . ' - ' . $job['make'] . ' ' . $job['model']); ?></td>
                        <td><?php echo e($job['owner_name']); ?></td>
                        <td><?php echo e(substr($job['description'], 0, 50)) . '...'; ?></td>
                        <td><?php echo getStatusBadge($job['status']); ?></td>
                        <td><?php echo formatDate($job['start_date']); ?></td>
                        <td><?php echo formatDate($job['completion_date']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div style="margin-top: 20px; text-align: center;">
    <a href="?" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Report Selection
    </a>
</div>

<?php elseif ($reportType === 'inventory'): ?>
<!-- Parts Inventory Report -->
<?php
$stmt = $pdo->prepare("
    SELECT 
        sii.part_number,
        sii.description,
        sii.quantity,
        sii.quantity_received,
        sii.quantity_installed,
        sii.installation_status,
        si.invoice_number,
        q.quotation_number,
        j.job_number,
        v.number_plate,
        s.name as supplier_name,
        sii.created_at
    FROM supplier_invoice_items sii
    JOIN supplier_invoices si ON sii.supplier_invoice_id = si.id
    JOIN quotations q ON si.quotation_id = q.id
    JOIN jobs j ON q.job_id = j.id
    JOIN vehicles v ON j.vehicle_id = v.id
    JOIN suppliers s ON si.supplier_id = s.id
    WHERE sii.created_at BETWEEN ? AND ?
    ORDER BY sii.created_at DESC
");
$stmt->execute([$dateFrom, $dateTo]);
$inventoryReport = $stmt->fetchAll();

$inventorySummary = [
    'total_received' => 0,
    'total_installed' => 0,
    'total_pending' => 0,
];

foreach ($inventoryReport as $item) {
    $inventorySummary['total_received'] += $item['quantity_received'];
    $inventorySummary['total_installed'] += $item['quantity_installed'];
    $inventorySummary['total_pending'] += ($item['quantity_received'] - $item['quantity_installed']);
}
?>

<div class="card">
    <div class="card-header">
        <h3>Parts Inventory Report</h3>
        <p class="text-muted">Period: <?php echo formatDate($dateFrom); ?> to <?php echo formatDate($dateTo); ?></p>
    </div>
    <div class="card-body">
        <!-- Summary -->
        <div class="stats-grid" style="margin-bottom: 30px;">
            <div class="stat-card">
                <div class="stat-content">
                    <h3><?php echo $inventorySummary['total_received']; ?></h3>
                    <p>Total Parts Received</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <h3><?php echo $inventorySummary['total_installed']; ?></h3>
                    <p>Total Parts Installed</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <h3><?php echo $inventorySummary['total_pending']; ?></h3>
                    <p>Total Parts Pending</p>
                </div>
            </div>
        </div>
        
        <!-- Detailed List -->
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Part Number</th>
                        <th>Description</th>
                        <th>Qty Recv.</th>
                        <th>Qty Inst.</th>
                        <th>Qty Pend.</th>
                        <th>Status</th>
                        <th>Job #</th>
                        <th>Supplier</th>
                        <th>Received Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventoryReport as $item): ?>
                    <tr>
                        <td><?php echo e($item['part_number']); ?></td>
                        <td><?php echo e($item['description']); ?></td>
                        <td><?php echo $item['quantity_received']; ?></td>
                        <td><?php echo $item['quantity_installed']; ?></td>
                        <td><?php echo ($item['quantity_received'] - $item['quantity_installed']); ?></td>
                        <td><?php echo getStatusBadge($item['installation_status']); ?></td>
                        <td><?php echo e($item['job_number']); ?></td>
                        <td><?php echo e($item['supplier_name']); ?></td>
                        <td><?php echo formatDate($item['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div style="margin-top: 20px; text-align: center;">
    <a href="?" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Report Selection
    </a>
</div>

<?php elseif ($reportType === 'vendors'): ?>
<!-- Vendor Report -->
<?php
// Fetch Supplier data
$suppliersStmt = $pdo->prepare("
    SELECT 
        s.name as vendor_name,
        'Supplier' as vendor_type,
        COUNT(si.id) as transaction_count,
        SUM(si.final_amount) as total_spent
    FROM suppliers s
    JOIN supplier_invoices si ON s.id = si.supplier_id
    WHERE si.invoice_date BETWEEN ? AND ?
    GROUP BY s.id
");
$suppliersStmt->execute([$dateFrom, $dateTo]);
$supplierData = $suppliersStmt->fetchAll();

// Fetch Subcontractor data
$subcontractorsStmt = $pdo->prepare("
    SELECT 
        sub.name as vendor_name,
        'Subcontractor' as vendor_type,
        COUNT(sw.id) as transaction_count,
        SUM(sw.total_cost) as total_spent
    FROM subcontractors sub
    JOIN subcontract_works sw ON sub.id = sw.subcontractor_id
    WHERE sw.start_date BETWEEN ? AND ?
    GROUP BY sub.id
");
$subcontractorsStmt->execute([$dateFrom, $dateTo]);
$subcontractorData = $subcontractorsStmt->fetchAll();

$vendorReport = array_merge($supplierData, $subcontractorData);

// Calculate overall totals
$overallVendorTotals = [
    'transaction_count' => 0,
    'total_spent' => 0,
];

foreach ($vendorReport as $vendor) {
    $overallVendorTotals['transaction_count'] += $vendor['transaction_count'];
    $overallVendorTotals['total_spent'] += $vendor['total_spent'];
}

usort($vendorReport, function($a, $b) {
    return $b['total_spent'] <=> $a['total_spent'];
});
?>

<div class="card">
    <div class="card-header">
        <h3>Vendor Report</h3>
        <p class="text-muted">Period: <?php echo formatDate($dateFrom); ?> to <?php echo formatDate($dateTo); ?></p>
    </div>
    <div class="card-body">
        <!-- Summary -->
        <div class="stats-grid" style="margin-bottom: 30px;">
            <div class="stat-card">
                <div class="stat-content">
                    <h3><?php echo $overallVendorTotals['transaction_count']; ?></h3>
                    <p>Total Transactions</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <h3><?php echo formatCurrency($overallVendorTotals['total_spent']); ?></h3>
                    <p>Total Spent</p>
                </div>
            </div>
        </div>
        
        <!-- Detailed List -->
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Vendor Name</th>
                        <th>Type</th>
                        <th>Transactions</th>
                        <th>Total Spent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vendorReport as $vendor): ?>
                    <tr>
                        <td><?php echo e($vendor['vendor_name']); ?></td>
                        <td><?php echo e($vendor['vendor_type']); ?></td>
                        <td><?php echo $vendor['transaction_count']; ?></td>
                        <td><?php echo formatCurrency($vendor['total_spent']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight: bold; background: #f9fafb;">
                        <td colspan="2">OVERALL TOTALS:</td>
                        <td><?php echo $overallVendorTotals['transaction_count']; ?></td>
                        <td><?php echo formatCurrency($overallVendorTotals['total_spent']); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<div style="margin-top: 20px; text-align: center;">
    <a href="?" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Report Selection
    </a>
</div>

<?php else: ?>
<!-- Other report types placeholder -->
<div class="card">
    <div class="card-body">
        <p class="text-center text-muted">
            <i class="fas fa-info-circle" style="font-size: 48px; margin-bottom: 15px;"></i><br>
            This report type is under development. Please select another report.
        </p>
        <div style="text-align: center; margin-top: 20px;">
            <a href="?" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Back to Report Selection
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.report-card {
    display: block;
    padding: 30px;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    text-decoration: none;
    color: inherit;
    transition: all 0.3s;
    text-align: center;
}

.report-card:hover {
    border-color: #667eea;
    box-shadow: 0 10px 25px rgba(102, 126, 234, 0.1);
    transform: translateY(-5px);
}

.report-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    font-size: 36px;
    color: white;
}

.report-card h3 {
    margin: 15px 0 10px 0;
    font-size: 20px;
    color: #1f2937;
}

.report-card p {
    margin: 0;
    color: #6b7280;
    font-size: 14px;
}

@media print {
    .navbar, .breadcrumb, .page-header button, .btn {
        display: none !important;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>
