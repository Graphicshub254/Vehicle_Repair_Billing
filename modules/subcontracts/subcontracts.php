<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = 'Subcontract Works';
$breadcrumbs = [
    ['text' => 'Subcontracts']
];

// Fetch all subcontract works
$stmt = $pdo->query("
    SELECT sw.*, j.job_number, v.number_plate, sub.name as subcontractor_name
    FROM subcontract_works sw
    JOIN jobs j ON sw.job_id = j.id
    JOIN vehicles v ON j.vehicle_id = v.id
    JOIN subcontractors sub ON sw.subcontractor_id = sub.id
    ORDER BY sw.created_at DESC
");
$subcontracts = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-users"></i> Subcontract Works</h1>
    <a href="<?php echo APP_URL; ?>/modules/subcontracts/add_subcontract.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> Add Subcontract
    </a>
</div>

<div class="card">
    <div class="card-body">
        <?php if (empty($subcontracts)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> No subcontract works found.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Work #</th>
                        <th>Job #</th>
                        <th>Vehicle</th>
                        <th>Subcontractor</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Total Cost</th>
                        <th>Status</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subcontracts as $subcontract): ?>
                    <tr>
                        <td><?php echo e($subcontract['work_number']); ?></td>
                        <td><?php echo e($subcontract['job_number']); ?></td>
                        <td><?php echo e($subcontract['number_plate']); ?></td>
                        <td><?php echo e($subcontract['subcontractor_name']); ?></td>
                        <td>
                            <?php if ($subcontract['work_type'] === 'parts'): ?>
                                <span class="badge badge-info">Parts</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Service</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e(substr($subcontract['work_description'], 0, 50)) . '...'; ?></td>
                        <td><?php echo formatCurrency($subcontract['total_cost']); ?></td>
                        <td><?php echo getStatusBadge($subcontract['status']); ?></td>
                        <td><?php echo formatDateTime($subcontract['created_at']); ?></td>
                        <td>
                            <a href="<?php echo APP_URL; ?>/modules/subcontracts/view_subcontract.php?id=<?php echo $subcontract['id']; ?>" class="btn btn-sm btn-info" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if ($subcontract['status'] === 'draft'): ?>
                            <a href="<?php echo APP_URL; ?>/modules/subcontracts/edit_subcontract.php?id=<?php echo $subcontract['id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php endif; ?>
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