<?php
require_once '../../config/config.php';
requireLogin();

$jobId = intval($_GET['id'] ?? 0);

// Load job with vehicle and user info
$stmt = $pdo->prepare("
    SELECT j.*, j.job_type, v.*, 
           u.full_name as created_by_name,
           v.id as vehicle_id, v.number_plate, v.make, v.model, v.year
    FROM jobs j
    JOIN vehicles v ON j.vehicle_id = v.id
    JOIN users u ON j.created_by = u.id
    WHERE j.id = ?
");
$stmt->execute([$jobId]);
$job = $stmt->fetch();

if (!$job) {
    setErrorMessage('Job not found');
    redirect(APP_URL . '/modules/jobs/jobs.php');
}

$pageTitle = 'Job Details - ' . $job['job_number'];
$breadcrumbs = [
    ['text' => 'Jobs', 'url' => APP_URL . '/modules/jobs/jobs.php'],
    ['text' => $job['job_number']]
];

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $newStatus = $_POST['status'] ?? '';
    $validStatuses = ['open', 'awaiting_quotation_approval', 'awaiting_parts', 'in_progress', 'with_subcontractor', 'returned_from_subcontractor', 'completed', 'invoiced'];
    
    if (in_array($newStatus, $validStatuses)) {
        $stmt = $pdo->prepare("UPDATE jobs SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        if ($stmt->execute([$newStatus, $jobId])) {
            logActivity($pdo, 'Updated job status', 'jobs', $jobId, "From {$job['status']} to $newStatus");
            setSuccessMessage('Job status updated successfully');
            redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $jobId);
        }
    }
}

// Get labor charges for this job
$stmt = $pdo->prepare("
    SELECT lc.*, u.full_name as created_by_name
    FROM labor_charges lc
    JOIN users u ON lc.created_by = u.id
    WHERE lc.job_id = ?
    ORDER BY lc.date_performed DESC
");
$stmt->execute([$jobId]);
$laborCharges = $stmt->fetchAll();

// Get customer invoices for this job
$stmt = $pdo->prepare("
    SELECT ci.*, u.full_name as generated_by_name
    FROM customer_invoices ci
    JOIN users u ON ci.generated_by = u.id
    WHERE ci.job_id = ?
    ORDER BY ci.created_at DESC
");
$stmt->execute([$jobId]);
$invoices = $stmt->fetchAll();

// Calculate totals
$totalLabor = 0;
foreach ($laborCharges as $labor) {
    $totalLabor += $labor['total_amount'];
}

$totalInvoiced = 0;
foreach ($invoices as $invoice) {
    $totalInvoiced += $invoice['total_amount'];
}

// Handle "Returned from Subcontractor" action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['returned_from_subcontractor'])) {
    if ($job['status'] === 'with_subcontractor') {
        $stmt = $pdo->prepare("UPDATE jobs SET status = 'in_progress', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        if ($stmt->execute([$jobId])) {
            logActivity($pdo, 'Job returned from subcontractor', 'jobs', $jobId, "Status changed to 'in_progress'");
            setSuccessMessage('Job status updated to "In Progress" (returned from subcontractor)');
            redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $jobId);
        } else {
            setErrorMessage('Failed to update job status.');
            redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $jobId);
        }
    } else {
        setErrorMessage('Job is not currently "With Subcontractor".');
        redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $jobId);
    }
}

// Handle "Mark as Completed" action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_as_completed'])) {
    // Check if job is already completed or invoiced
    if ($job['status'] === 'completed' || $job['status'] === 'invoiced') {
        setErrorMessage('Job is already completed or invoiced.');
        redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $jobId);
    }

    $canComplete = true;
    $pendingItems = [];

    // 1. Check Labor Charges
    $stmt = $pdo->prepare("
        SELECT lc.id, lc.description
        FROM labor_charges lc
        LEFT JOIN customer_invoice_items cii ON cii.reference_id = lc.id AND cii.item_type = 'labor'
        WHERE lc.job_id = ? AND cii.id IS NULL
    ");
    $stmt->execute([$jobId]);
    $pendingLabor = $stmt->fetchAll();
    if (!empty($pendingLabor)) {
        $canComplete = false;
        foreach ($pendingLabor as $item) {
            $pendingItems[] = "Labor: " . $item['description'];
        }
    }

    // 2. Check Supplier Invoice Items (Parts)
    $stmt = $pdo->prepare("
        SELECT sii.id, sii.description
        FROM supplier_invoice_items sii
        JOIN supplier_invoices si ON sii.supplier_invoice_id = si.id
        JOIN quotations q ON si.quotation_id = q.id
        LEFT JOIN customer_invoice_items cii ON cii.reference_id = sii.id AND cii.item_type = 'isuzu_part'
        WHERE q.job_id = ? AND sii.installation_status = 'fully_installed' AND cii.id IS NULL
    ");
    $stmt->execute([$jobId]);
    $pendingParts = $stmt->fetchAll();
    if (!empty($pendingParts)) {
        $canComplete = false;
        foreach ($pendingParts as $item) {
            $pendingItems[] = "Part: " . $item['description'];
        }
    }

    // 3. Check Subcontract Works
    $stmt = $pdo->prepare("
        SELECT sw.id, sw.work_description
        FROM subcontract_works sw
        LEFT JOIN customer_invoice_items cii ON cii.reference_id = sw.id
            AND (cii.item_type = 'subcontract_part' OR cii.item_type = 'subcontract_service')
        WHERE sw.job_id = ? AND sw.status = 'completed' AND cii.id IS NULL
    ");
    $stmt->execute([$jobId]);
    $pendingSubcontracts = $stmt->fetchAll();
    if (!empty($pendingSubcontracts)) {
        $canComplete = false;
        foreach ($pendingSubcontracts as $item) {
            $pendingItems[] = "Subcontract: " . $item['work_description'];
        }
    }

    if ($canComplete) {
        $stmt = $pdo->prepare("UPDATE jobs SET status = 'completed', completion_date = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        if ($stmt->execute([date('Y-m-d'), $jobId])) {
            logActivity($pdo, 'Job marked as completed', 'jobs', $jobId, "Job {$job['job_number']} completed.");
            setSuccessMessage('Job marked as completed successfully.');
            redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $jobId);
        } else {
            setErrorMessage('Failed to mark job as completed.');
            redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $jobId);
        }
    } else {
        $message = "Cannot mark job as completed. The following items are not yet invoiced: <br>- " . implode("<br>- ", $pendingItems);
        setErrorMessage($message);
        redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $jobId);
    }
}

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h1><i class="fas fa-wrench"></i> <?php echo e($job['job_number']); ?></h1>
        <p class="text-muted" style="margin: 5px 0 0 0;">
            <?php echo e($job['number_plate']); ?> - <?php echo e($job['make'] . ' ' . $job['model']); ?>
        </p>
    </div>
    <div>
        <a href="<?php echo APP_URL; ?>/modules/jobs/jobs.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Jobs
        </a>
        <?php if ($job['status'] === 'with_subcontractor'): ?>
        <form method="POST" style="display: inline-block; margin-left: 10px;">
            <input type="hidden" name="returned_from_subcontractor" value="1">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-truck-loading"></i> Returned from Subcontractor
            </button>
        </form>
        <?php endif; ?>
        <?php if ($job['status'] === 'open'): ?>
        <a href="<?php echo APP_URL; ?>/modules/jobs/edit_job.php?id=<?php echo $jobId; ?>" class="btn btn-warning">
            <i class="fas fa-edit"></i> Edit Job
        </a>
        <?php endif; ?>
        <?php if ($job['status'] !== 'completed' && $job['status'] !== 'invoiced'): ?>
        <form method="POST" style="display: inline-block; margin-left: 10px;">
            <input type="hidden" name="mark_as_completed" value="1">
            <button type="submit" class="btn btn-success">
                <i class="fas fa-check-double"></i> Mark as Completed
            </button>
        </form>
        <?php endif; ?>
        <?php if ($job['status'] !== 'invoiced'): ?>
        <a href="<?php echo APP_URL; ?>/modules/labor/add_labor.php?job_id=<?php echo $jobId; ?>" class="btn btn-info">
            <i class="fas fa-users-cog"></i> Add Labor
        </a>
        <?php if ($job['job_type'] === 'part' && ($job['status'] === 'open' || $job['status'] === 'awaiting_quotation_approval')): ?>
        <a href="<?php echo APP_URL; ?>/modules/quotations/create_quotation.php?job_id=<?php echo $jobId; ?>" class="btn btn-primary">
            <i class="fas fa-file-invoice"></i> Create Quotation
        </a>
        <?php endif; ?>
        <?php if (!empty($laborCharges)): ?>
        <a href="<?php echo APP_URL; ?>/modules/invoices/generate_invoice.php?job_id=<?php echo $jobId; ?>" class="btn btn-success">
            <i class="fas fa-file-invoice-dollar"></i> Generate Invoice
        </a>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Job Details Card -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-info-circle"></i> Job Information</h3>
    </div>
    <div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label>Job Number</label>
                <p><strong><?php echo e($job['job_number']); ?></strong></p>
            </div>
            <div class="form-group">
                <label>Status</label>
                <p><?php echo getStatusBadge($job['status']); ?></p>
            </div>
            <div class="form-group">
                <label>Start Date</label>
                <p><?php echo formatDate($job['start_date']); ?></p>
            </div>
            <div class="form-group">
                <label>Completion Date</label>
                <p><?php echo $job['completion_date'] ? formatDate($job['completion_date']) : '-'; ?></p>
            </div>
        </div>
        
        <div class="form-group">
            <label>Description</label>
            <p><?php echo nl2br(e($job['description'])); ?></p>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Created By</label>
                <p><?php echo e($job['created_by_name']); ?></p>
            </div>
            <div class="form-group">
                <label>Created At</label>
                <p><?php echo formatDateTime($job['created_at']); ?></p>
            </div>
            <div class="form-group">
                <label>Last Updated</label>
                <p><?php echo formatDateTime($job['updated_at']); ?></p>
            </div>
        </div>
        
        <!-- Update Status Form -->
        <?php if ($job['status'] !== 'invoiced'): ?>
        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
            <form method="POST" style="display: flex; gap: 10px; align-items: center;">
                <label style="margin: 0;">Update Status:</label>
                <select name="status" class="form-control" style="width: auto;">
                    <option value="open" <?php echo $job['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                    <option value="awaiting_quotation_approval" <?php echo $job['status'] === 'awaiting_quotation_approval' ? 'selected' : ''; ?>>Awaiting Quotation Approval</option>
                    <option value="awaiting_parts" <?php echo $job['status'] === 'awaiting_parts' ? 'selected' : ''; ?>>Awaiting Parts</option>
                    <option value="in_progress" <?php echo $job['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="with_subcontractor" <?php echo $job['status'] === 'with_subcontractor' ? 'selected' : ''; ?>>With Subcontractor</option>
                    <option value="completed" <?php echo $job['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                </select>
                <button type="submit" name="update_status" class="btn btn-primary">
                    <i class="fas fa-check"></i> Update
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon stat-icon-info">
            <i class="fas fa-users-cog"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo count($laborCharges); ?></h3>
            <p>Labor Entries</p>
            <div class="stat-trend">
                <span class="text-muted"><?php echo formatCurrency($totalLabor); ?></span>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon stat-icon-success">
            <i class="fas fa-file-invoice-dollar"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo count($invoices); ?></h3>
            <p>Invoices Generated</p>
            <div class="stat-trend">
                <span class="text-muted"><?php echo formatCurrency($totalInvoiced); ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Labor Charges -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-users-cog"></i> Labor Charges</h3>
        <a href="<?php echo APP_URL; ?>/modules/labor/add_labor.php?job_id=<?php echo $jobId; ?>" class="btn btn-sm btn-primary">
            <i class="fas fa-plus"></i> Add Labor
        </a>
    </div>
    <div class="card-body">
        <?php if (empty($laborCharges)): ?>
            <p class="text-center text-muted">
                No labor charges yet. <a href="<?php echo APP_URL; ?>/modules/labor/add_labor.php?job_id=<?php echo $jobId; ?>">Add the first labor charge</a>
            </p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Performed By</th>
                        <th>Date</th>
                        <th>Hours</th>
                        <th>Rate</th>
                        <th>Amount</th>
                        <th>Created By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($laborCharges as $labor): ?>
                    <tr>
                        <td><?php echo e($labor['description']); ?></td>
                        <td><?php echo e($labor['performed_by'] ?: '-'); ?></td>
                        <td><?php echo formatDate($labor['date_performed']); ?></td>
                        <td><?php echo $labor['hours'] ? number_format($labor['hours'], 2) : '-'; ?></td>
                        <td><?php echo $labor['rate'] ? formatCurrency($labor['rate']) : '-'; ?></td>
                        <td><strong><?php echo formatCurrency($labor['total_amount']); ?></strong></td>
                        <td><?php echo e($labor['created_by_name']); ?></td>
                        <td>
                            <a href="<?php echo APP_URL; ?>/modules/labor/edit_labor.php?id=<?php echo $labor['id']; ?>" class="btn btn-sm btn-warning">
                                <i class="fas fa-edit"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight: bold; background: #f9fafb;">
                        <td colspan="5" class="text-right">Total Labor:</td>
                        <td colspan="3"><?php echo formatCurrency($totalLabor); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Customer Invoices -->
<?php if (!empty($invoices)): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-file-invoice-dollar"></i> Customer Invoices</h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice Number</th>
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
                        <td><strong><?php echo e($invoice['invoice_number']); ?></strong></td>
                        <td><?php echo getStatusBadge($invoice['invoice_type']); ?></td>
                        <td><?php echo formatDate($invoice['invoice_date']); ?></td>
                        <td><?php echo formatCurrency($invoice['total_amount']); ?></td>
                        <?php if (isDirector()): ?>
                        <td><?php echo displayProfit($invoice['total_profit'], $invoice['profit_percentage']); ?></td>
                        <?php endif; ?>
                        <td><?php echo e($invoice['generated_by_name']); ?></td>
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
