<?php
require_once '../../config/config.php';
requireLogin();

$vehicleId = intval($_GET['id'] ?? 0);

// Load vehicle
$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?");
$stmt->execute([$vehicleId]);
$vehicle = $stmt->fetch();

if (!$vehicle) {
    setErrorMessage('Vehicle not found');
    redirect(APP_URL . '/modules/vehicles/vehicles.php');
}

$pageTitle = 'Vehicle History - ' . $vehicle['number_plate'];
$breadcrumbs = [
    ['text' => 'Vehicles', 'url' => APP_URL . '/modules/vehicles/vehicles.php'],
    ['text' => $vehicle['number_plate']]
];

// Get all jobs for this vehicle
$stmt = $pdo->prepare("
    SELECT j.*, u.full_name as created_by_name,
           (SELECT COUNT(*) FROM customer_invoices WHERE job_id = j.id) as invoice_count,
           (SELECT SUM(total_amount) FROM customer_invoices WHERE job_id = j.id) as total_invoiced
    FROM jobs j
    JOIN users u ON j.created_by = u.id
    WHERE j.vehicle_id = ?
    ORDER BY j.created_at DESC
");
$stmt->execute([$vehicleId]);
$jobs = $stmt->fetchAll();

// Calculate statistics
$totalJobs = count($jobs);
$totalSpent = 0;
$completedJobs = 0;

foreach ($jobs as $job) {
    if ($job['total_invoiced']) {
        $totalSpent += $job['total_invoiced'];
    }
    if ($job['status'] === 'completed' || $job['status'] === 'invoiced') {
        $completedJobs++;
    }
}

$avgJobCost = $totalJobs > 0 ? $totalSpent / $totalJobs : 0;

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h1><i class="fas fa-history"></i> Vehicle History</h1>
        <p class="text-muted" style="margin: 5px 0 0 0;">
            <?php echo e($vehicle['number_plate']); ?> - 
            <?php echo e($vehicle['make'] . ' ' . $vehicle['model']); ?>
            <?php if ($vehicle['year']): ?>
                (<?php echo e($vehicle['year']); ?>)
            <?php endif; ?>
        </p>
    </div>
    <div>
        <a href="<?php echo APP_URL; ?>/modules/vehicles/vehicles.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Vehicles
        </a>
        <a href="<?php echo APP_URL; ?>/modules/jobs/jobs.php?action=create&vehicle_id=<?php echo $vehicleId; ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> New Job
        </a>
    </div>
</div>

<!-- Vehicle Details Card -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-car"></i> Vehicle Details</h3>
    </div>
    <div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label>Number Plate</label>
                <p><strong><?php echo e($vehicle['number_plate']); ?></strong></p>
            </div>
            <div class="form-group">
                <label>Make & Model</label>
                <p><?php echo e($vehicle['make'] . ' ' . $vehicle['model']); ?></p>
            </div>
            <div class="form-group">
                <label>Year</label>
                <p><?php echo $vehicle['year'] ? e($vehicle['year']) : '-'; ?></p>
            </div>
            <div class="form-group">
                <label>VIN</label>
                <p><?php echo $vehicle['vin'] ? e($vehicle['vin']) : '-'; ?></p>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Owner</label>
                <p><?php echo e($vehicle['owner_name']); ?></p>
            </div>
            <div class="form-group">
                <label>Phone</label>
                <p><?php echo $vehicle['owner_phone'] ? e($vehicle['owner_phone']) : '-'; ?></p>
            </div>
            <div class="form-group">
                <label>Email</label>
                <p><?php echo $vehicle['owner_email'] ? e($vehicle['owner_email']) : '-'; ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon stat-icon-primary">
            <i class="fas fa-tasks"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo number_format($totalJobs); ?></h3>
            <p>Total Jobs</p>
            <div class="stat-trend">
                <span class="text-muted"><?php echo $completedJobs; ?> completed</span>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon stat-icon-success">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo formatCurrency($totalSpent); ?></h3>
            <p>Total Spent</p>
            <div class="stat-trend">
                <span class="text-muted">Lifetime</span>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon stat-icon-info">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo formatCurrency($avgJobCost); ?></h3>
            <p>Average Job Cost</p>
            <div class="stat-trend">
                <span class="text-muted">Per job</span>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon stat-icon-warning">
            <i class="fas fa-calendar"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo formatDate($vehicle['created_at']); ?></h3>
            <p>First Visit</p>
            <div class="stat-trend">
                <span class="text-muted">Registered</span>
            </div>
        </div>
    </div>
</div>

<!-- Job History -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-wrench"></i> Repair History</h3>
    </div>
    <div class="card-body">
        <?php if (empty($jobs)): ?>
            <p class="text-center text-muted">
                No repair history yet. <a href="<?php echo APP_URL; ?>/modules/jobs/jobs.php?action=create&vehicle_id=<?php echo $vehicleId; ?>">Create the first job</a>
            </p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Job Number</th>
                        <th>Description</th>
                        <th>Start Date</th>
                        <th>Completion Date</th>
                        <th>Status</th>
                        <th>Amount</th>
                        <th>Created By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><strong><?php echo e($job['job_number']); ?></strong></td>
                        <td><?php echo e(substr($job['description'], 0, 50)) . (strlen($job['description']) > 50 ? '...' : ''); ?></td>
                        <td><?php echo formatDate($job['start_date']); ?></td>
                        <td><?php echo $job['completion_date'] ? formatDate($job['completion_date']) : '-'; ?></td>
                        <td><?php echo getStatusBadge($job['status']); ?></td>
                        <td>
                            <?php if ($job['total_invoiced']): ?>
                                <?php echo formatCurrency($job['total_invoiced']); ?>
                            <?php else: ?>
                                <span class="text-muted">Not invoiced</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e($job['created_by_name']); ?></td>
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

<?php include '../../includes/footer.php'; ?>
