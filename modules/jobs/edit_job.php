<?php
require_once '../../config/config.php';
requireLogin();

// Only users with appropriate roles can edit jobs (e.g., procurement_officer, director, or original creator)
// For simplicity, let's allow director or procurement_officer for now, or the job's creator
if (!isDirector() && !isProcurementOfficer()) {
    setErrorMessage('You do not have permission to edit jobs');
    redirect(APP_URL . '/modules/dashboard/dashboard.php');
}

$jobId = intval($_GET['id'] ?? 0);

// Load job details
$stmt = $pdo->prepare("
    SELECT j.*, v.number_plate, v.make, v.model
    FROM jobs j
    JOIN vehicles v ON j.vehicle_id = v.id
    WHERE j.id = ?
");
$stmt->execute([$jobId]);
$job = $stmt->fetch();

if (!$job) {
    setErrorMessage('Job not found.');
    redirect(APP_URL . '/modules/jobs/jobs.php');
}

// Enforce editing restriction: only if job status is 'open'
if ($job['status'] !== 'open') {
    setErrorMessage('Job can only be edited when its status is "Open". Current status: ' . ucwords(str_replace('_', ' ', $job['status'])));
    redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $jobId);
}

$pageTitle = 'Edit Job - ' . $job['job_number'];
$breadcrumbs = [
    ['text' => 'Jobs', 'url' => APP_URL . '/modules/jobs/jobs.php'],
    ['text' => $job['job_number'], 'url' => APP_URL . '/modules/jobs/job_details.php?id=' . $jobId],
    ['text' => 'Edit Job']
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = trim($_POST['description'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    // Vehicle selection is usually not editable after job creation in many systems,
    // as it fundamentally changes the job's context.
    // If needed, vehicle_id can be added to the form and updated.
    // For now, we'll keep vehicle_id fixed.

    if (empty($description) || empty($start_date)) {
        setErrorMessage('Description and Start Date are required.');
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE jobs SET
                    description = ?,
                    start_date = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$description, $start_date, $jobId]);

            logActivity($pdo, 'Edited job', 'jobs', $jobId, "Job {$job['job_number']} details updated.");
            $pdo->commit();

            setSuccessMessage("Job {$job['job_number']} updated successfully.");
            redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $jobId);

        } catch (Exception $e) {
            $pdo->rollBack();
            setErrorMessage('Failed to update job: ' . $e->getMessage());
        }
    }
}

// Get all vehicles for potential change (though we decided not to allow vehicle change for now)
// Can be added if needed
// $vehiclesStmt = $pdo->query("SELECT id, number_plate, make, model FROM vehicles ORDER BY number_plate");
// $vehicles = $vehiclesStmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-edit"></i> Edit Job: <?php echo e($job['job_number']); ?></h1>
    <a href="<?php echo APP_URL; ?>/modules/jobs/job_details.php?id=<?php echo $jobId; ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Job Details
    </a>
</div>

<form method="POST">
    <div class="card">
        <div class="card-header">
            <h3>Job Information</h3>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label>Vehicle</label>
                <p><strong><?php echo e($job['number_plate']); ?></strong> (<?php echo e($job['make'] . ' ' . $job['model']); ?>)</p>
                <small class="form-hint">Vehicle cannot be changed after job creation.</small>
            </div>

            <div class="form-group">
                <label for="description">Description <span class="required">*</span></label>
                <textarea id="description" name="description" class="form-control" rows="5" required><?php echo e($job['description']); ?></textarea>
            </div>
            
            <div class="form-group">
                <label for="start_date">Start Date <span class="required">*</span></label>
                <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo e($job['start_date']); ?>" required>
            </div>
        </div>
        
        <div class="card-footer">
            <button type="submit" class="btn btn-success btn-lg">
                <i class="fas fa-save"></i> Update Job
            </button>
        </div>
    </div>
</form>

<?php include '../../includes/footer.php'; ?>
