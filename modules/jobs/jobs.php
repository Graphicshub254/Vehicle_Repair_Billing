<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = 'Jobs';
$breadcrumbs = [
    ['text' => 'Jobs']
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $vehicle_id = intval($_POST['vehicle_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $job_type = $_POST['job_type'] ?? 'general'; // New: Get job_type
        $start_date = $_POST['start_date'] ?? date('Y-m-d');
        
        if ($vehicle_id <= 0 || empty($description)) {
            setErrorMessage('Vehicle and description are required');
        } else {
            // Verify vehicle exists
            $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = ?");
            $stmt->execute([$vehicle_id]);
            
            if (!$stmt->fetch()) {
                setErrorMessage('Invalid vehicle selected');
            } else {
                $job_number = generateJobNumber($pdo);
                
                $stmt = $pdo->prepare("
                    INSERT INTO jobs (job_number, vehicle_id, description, job_type, start_date, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                if ($stmt->execute([$job_number, $vehicle_id, $description, $job_type, $start_date, $_SESSION['user_id']])) {
                    $jobId = $pdo->lastInsertId();
                    logActivity($pdo, 'Created job', 'jobs', $jobId, "Job: $job_number");
                    setSuccessMessage("Job $job_number created successfully");
                    redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $jobId);
                } else {
                    setErrorMessage('Failed to create job');
                }
            }
        }
    } elseif ($action === 'update_status') {
        $id = intval($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        $validStatuses = ['open', 'awaiting_quotation_approval', 'awaiting_parts', 'in_progress', 'with_subcontractor', 'completed', 'invoiced'];
        
        if (!in_array($status, $validStatuses)) {
            setErrorMessage('Invalid status');
        } else {
            $stmt = $pdo->prepare("UPDATE jobs SET status = ? WHERE id = ?");
            if ($stmt->execute([$status, $id])) {
                logActivity($pdo, 'Updated job status', 'jobs', $id, "New status: $status");
                setSuccessMessage('Job status updated successfully');
            } else {
                setErrorMessage('Failed to update job status');
            }
        }
        redirect(APP_URL . '/modules/jobs/jobs.php');
    }
}

// Get action and ID from query string
$viewAction = $_GET['action'] ?? 'list';
$preselectedVehicle = intval($_GET['vehicle_id'] ?? 0);

// Search and filter
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$whereClause = '1=1';
$params = [];

if ($search) {
    $whereClause .= " AND (j.job_number LIKE ? OR v.number_plate LIKE ? OR j.description LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

if ($statusFilter) {
    $whereClause .= " AND j.status = ?";
    $params[] = $statusFilter;
}

// Get jobs
$stmt = $pdo->prepare("
    SELECT j.*, v.number_plate, v.make, v.model, u.full_name as created_by_name,
           (SELECT COUNT(*) FROM customer_invoices WHERE job_id = j.id) as invoice_count
    FROM jobs j
    JOIN vehicles v ON j.vehicle_id = v.id
    JOIN users u ON j.created_by = u.id
    WHERE $whereClause
    ORDER BY j.created_at DESC
");
$stmt->execute($params);
$jobs = $stmt->fetchAll();

// Get all vehicles for dropdown
$vehiclesStmt = $pdo->query("SELECT id, number_plate, make, model FROM vehicles ORDER BY number_plate");
$allVehicles = $vehiclesStmt->fetchAll();

include '../../includes/header.php';
?>

<?php if ($viewAction === 'list'): ?>
<!-- List View -->
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-wrench"></i> Jobs</h1>
    <a href="?action=create" class="btn btn-primary">
        <i class="fas fa-plus"></i> Create New Job
    </a>
</div>

<!-- Search and Filter Bar -->
<div class="card">
    <div class="card-body">
        <form method="GET" style="display: grid; grid-template-columns: 1fr 200px auto auto; gap: 10px;">
            <input type="text" name="search" class="form-control" placeholder="Search by job number, vehicle, or description..." value="<?php echo e($search); ?>">
            <select name="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="open" <?php echo $statusFilter === 'open' ? 'selected' : ''; ?>>Open</option>
                <option value="awaiting_quotation_approval" <?php echo $statusFilter === 'awaiting_quotation_approval' ? 'selected' : ''; ?>>Awaiting Approval</option>
                <option value="awaiting_parts" <?php echo $statusFilter === 'awaiting_parts' ? 'selected' : ''; ?>>Awaiting Parts</option>
                <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                <option value="with_subcontractor" <?php echo $statusFilter === 'with_subcontractor' ? 'selected' : ''; ?>>With Subcontractor</option>
                <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                <option value="invoiced" <?php echo $statusFilter === 'invoiced' ? 'selected' : ''; ?>>Invoiced</option>
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

<!-- Jobs Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($jobs)): ?>
            <p class="text-center text-muted">
                <?php if ($search || $statusFilter): ?>
                    No jobs found matching your criteria
                <?php else: ?>
                    No jobs yet. <a href="?action=create">Create your first job</a>
                <?php endif; ?>
            </p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Job Number</th>
                        <th>Vehicle</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Start Date</th>
                        <th>Invoices</th>
                        <th>Created By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><strong><?php echo e($job['job_number']); ?></strong></td>
                        <td>
                            <?php echo e($job['number_plate']); ?><br>
                            <small class="text-muted"><?php echo e($job['make'] . ' ' . $job['model']); ?></small>
                        </td>
                        <td><?php echo e(substr($job['description'], 0, 60)) . (strlen($job['description']) > 60 ? '...' : ''); ?></td>
                        <td><?php echo getStatusBadge($job['status']); ?></td>
                        <td><?php echo formatDate($job['start_date']); ?></td>
                        <td>
                            <?php if ($job['invoice_count'] > 0): ?>
                                <span class="badge badge-success"><?php echo $job['invoice_count']; ?> invoice(s)</span>
                            <?php else: ?>
                                <span class="text-muted">Not invoiced</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e($job['created_by_name']); ?></td>
                        <td>
                            <div class="table-actions">
                                <a href="job_details.php?id=<?php echo $job['id']; ?>" class="btn btn-sm btn-info">
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

<?php elseif ($viewAction === 'create'): ?>
<!-- Create Form -->
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-wrench"></i> Create New Job</h1>
    <a href="?" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>

<div class="card">
    <form method="POST" id="jobForm">
        <input type="hidden" name="action" value="create">
        
        <div class="card-header">
            <h3>Job Information</h3>
        </div>
        
        <div class="card-body">
            <div class="form-group">
                <label for="vehicle_id">Select Vehicle <span class="required">*</span></label>
                <select id="vehicle_id" name="vehicle_id" class="form-control" required>
                    <option value="">-- Select a vehicle --</option>
                    <?php foreach ($allVehicles as $v): ?>
                    <option value="<?php echo $v['id']; ?>" <?php echo $preselectedVehicle == $v['id'] ? 'selected' : ''; ?>>
                        <?php echo e($v['number_plate']); ?> - <?php echo e($v['make'] . ' ' . $v['model']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="form-hint">
                    Don't see the vehicle? <a href="<?php echo APP_URL; ?>/modules/vehicles/vehicles.php?action=create" target="_blank">Add a new vehicle</a>
                </small>
            </div>
            
            <div class="form-group">
                <label for="description">Job Description <span class="required">*</span></label>
                <textarea id="description" name="description" class="form-control" rows="5" required 
                          placeholder="Describe the repair work needed..."></textarea>
                <small class="form-hint">Be as detailed as possible about the work required</small>
            </div>
            
            <div class="form-group">
                <label for="job_type">Job Type <span class="required">*</span></label>
                <select id="job_type" name="job_type" class="form-control" required>
                    <option value="general">General Repair</option>
                    <option value="part">Part Replacement</option>
                    <option value="service">Service</option>
                </select>
                <small class="form-hint">Select the primary type of this job</small>
            </div>
            
            <div class="form-group">
                <label for="start_date">Start Date <span class="required">*</span></label>
                <input type="date" id="start_date" name="start_date" class="form-control" 
                       value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <strong>Next Steps:</strong> After creating the job, you can add quotations, labor charges, and eventually generate invoices.
            </div>
        </div>
        
        <div class="card-footer">
            <a href="?" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Create Job
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
