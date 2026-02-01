<?php
require_once '../../config/config.php';
requireLogin();

// Only procurement officers and directors can view quotations
if (!isProcurementOfficer() && !isDirector()) {
    setErrorMessage('You do not have permission to view quotations');
    redirect(APP_URL . '/modules/dashboard/dashboard.php');
}

$pageTitle = 'Quotations';
$breadcrumbs = [
    ['text' => 'Quotations']
];

// Search and filter
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$whereClause = '1=1';
$params = [];

if ($search) {
    $whereClause .= " AND (q.quotation_number LIKE ? OR j.job_number LIKE ? OR v.number_plate LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

if ($statusFilter) {
    $whereClause .= " AND q.status = ?";
    $params[] = $statusFilter;
}

// Get quotations
$stmt = $pdo->prepare("
    SELECT q.*, j.job_number, v.number_plate, v.make, v.model,
           s.name as supplier_name,
           u_prep.full_name as prepared_by_name,
           u_app.full_name as approved_by_name,
           (SELECT COUNT(*) FROM quotation_items WHERE quotation_id = q.id) as item_count,
           (SELECT SUM(total_amount) FROM quotation_items WHERE quotation_id = q.id) as total_amount
    FROM quotations q
    JOIN jobs j ON q.job_id = j.id
    JOIN vehicles v ON j.vehicle_id = v.id
    JOIN suppliers s ON q.supplier_id = s.id
    JOIN users u_prep ON q.prepared_by = u_prep.id
    LEFT JOIN users u_app ON q.approved_by = u_app.id
    WHERE $whereClause
    ORDER BY q.created_at DESC
");
$stmt->execute($params);
$quotations = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-file-invoice"></i> Quotations</h1>
    <div>
        <?php if (isDirector()): ?>
        <a href="approve_quotation.php" class="btn btn-warning">
            <i class="fas fa-clock"></i> Pending Approvals
            <?php
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM quotations WHERE status = 'pending_approval'");
            $pending = $stmt->fetch()['count'];
            if ($pending > 0):
            ?>
                <span class="badge" style="background: #ef4444; color: white; padding: 2px 6px; border-radius: 10px; font-size: 11px; margin-left: 5px;"><?php echo $pending; ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        <a href="create_quotation.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Create Quotation
        </a>
    </div>
</div>

<!-- Search and Filter -->
<div class="card">
    <div class="card-body">
        <form method="GET" style="display: grid; grid-template-columns: 1fr 200px auto auto; gap: 10px;">
            <input type="text" name="search" class="form-control" placeholder="Search by quotation #, job #, or vehicle..." value="<?php echo e($search); ?>">
            <select name="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                <option value="pending_approval" <?php echo $statusFilter === 'pending_approval' ? 'selected' : ''; ?>>Pending Approval</option>
                <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                <option value="ordered" <?php echo $statusFilter === 'ordered' ? 'selected' : ''; ?>>Ordered</option>
            </select>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i> Search
            </button>
            <?php if ($search || $statusFilter): ?>
            <a href="?" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Quotations Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($quotations)):
            ?>
            <p class="text-center text-muted">
                <?php if ($search || $statusFilter): ?>
                    No quotations found matching your criteria
                <?php else: ?>
                    No quotations yet. <a href="create_quotation.php">Create your first quotation</a>
                <?php endif; ?>
            </p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Quotation #</th>
                        <th>Job #</th>
                        <th>Vehicle</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Prepared By</th>
                        <th>Approved By / Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quotations as $q): ?>
                    <tr>
                        <td>
                            <strong><?php echo e($q['quotation_number']); ?></strong><br>
                            <small class="text-muted"><?php echo formatDate($q['quotation_date']); ?></small>
                        </td>
                        <td><?php echo e($q['job_number']); ?></td>
                        <td>
                            <?php echo e($q['number_plate']); ?><br>
                            <small class="text-muted"><?php echo e($q['make'] . ' ' . $q['model']); ?></small>
                        </td>
                        <td><strong><?php echo formatCurrency($q['total_amount']); ?></strong></td>
                        <td><?php echo getStatusBadge($q['status']); ?></td>
                        <td><?php echo e($q['prepared_by_name']); ?></td>
                        <td>
                            <?php if ($q['status'] === 'approved' && $q['approved_by_name']): ?>
                                <?php echo e($q['approved_by_name']); ?><br>
                                <small class="text-muted"><?php echo formatDateTime($q['approval_date']); ?></small>
                            <?php elseif ($q['status'] === 'rejected'): ?>
                                Rejected
                            <?php else: ?>
                                <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="table-actions">
                                <a href="view_quotation.php?id=<?php echo $q['id']; ?>" class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <?php if ($q['status'] !== 'approved' && $q['status'] !== 'rejected' && $q['status'] !== 'ordered'): ?>
                                <a href="edit_quotation.php?id=<?php echo $q['id']; ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <?php endif; ?>
                                <?php if ($q['status'] === 'approved'): ?>
                                <a href="enter_supplier_invoice.php?quotation_id=<?php echo $q['id']; ?>" class="btn btn-sm btn-success" title="Enter Supplier Invoice">
                                    <i class="fas fa-file-invoice"></i> Invoice
                                </a>
                                <?php endif; ?>
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
