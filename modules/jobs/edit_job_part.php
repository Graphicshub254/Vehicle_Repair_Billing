<?php
require_once '../../config/config.php';
requireLogin();

$jobPartId = intval($_GET['id'] ?? 0);

// Load job part with job and inventory part info
$stmt = $pdo->prepare("
    SELECT jp.*, j.job_number, j.id as job_id, v.number_plate,
           ip.part_name, ip.part_number, ip.quantity_on_hand as current_stock
    FROM job_parts jp
    JOIN jobs j ON jp.job_id = j.id
    JOIN inventory_parts ip ON jp.part_id = ip.id
    JOIN vehicles v ON j.vehicle_id = v.id
    WHERE jp.id = ?
");
$stmt->execute([$jobPartId]);
$jobPart = $stmt->fetch();

if (!$jobPart) {
    setErrorMessage('Job part not found');
    redirect(APP_URL . '/modules/jobs/jobs.php');
}

// Check if already invoiced
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM customer_invoice_items 
    WHERE item_type = 'inventory_part' AND reference_id = ?
");
$stmt->execute([$jobPartId]);
$invoiced = $stmt->fetch()['count'] > 0;

if ($invoiced) {
    setErrorMessage('Cannot edit or delete part that has already been invoiced');
    redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $jobPart['job_id']);
}

$pageTitle = 'Edit Job Part';
$breadcrumbs = [
    ['text' => 'Jobs', 'url' => APP_URL . '/modules/jobs/jobs.php'],
    ['text' => $jobPart['job_number'], 'url' => APP_URL . '/modules/jobs/job_details.php?id=' . $jobPart['job_id']],
    ['text' => 'Edit Part']
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update') {
        $new_quantity_used = intval($_POST['quantity_used'] ?? 0);
        $original_quantity_used = $jobPart['quantity_used'];
        $current_stock = $jobPart['current_stock'];
        $part_id = $jobPart['part_id'];

        if ($new_quantity_used <= 0) {
            setErrorMessage('Quantity used must be greater than zero.');
        } else {
            $quantity_difference = $new_quantity_used - $original_quantity_used;

            if ($quantity_difference > 0 && $quantity_difference > $current_stock) {
                setErrorMessage('Not enough stock to increase quantity. Available in inventory: ' . $current_stock);
            } else {
                try {
                    $pdo->beginTransaction();

                    // Update job_parts quantity
                    $stmt = $pdo->prepare("UPDATE job_parts SET quantity_used = ? WHERE id = ?");
                    $stmt->execute([$new_quantity_used, $jobPartId]);

                    // Adjust inventory_parts quantity_on_hand
                    $stmt = $pdo->prepare("UPDATE inventory_parts SET quantity_on_hand = quantity_on_hand - ? WHERE id = ?");
                    $stmt->execute([$quantity_difference, $part_id]);

                    logActivity($pdo, 'Updated part quantity in job', 'job_parts', $jobPartId, 
                                "Job ID: " . $jobPart['job_id'] . ", Part: " . e($jobPart['part_name']) . ", New Qty: $new_quantity_used");
                    setSuccessMessage('Part quantity updated successfully.');
                    
                    $pdo->commit();
                    redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $jobPart['job_id']);

                } catch (PDOException $e) {
                    $pdo->rollBack();
                    setErrorMessage('Failed to update part quantity: ' . $e->getMessage());
                }
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $part_id = $jobPart['part_id'];
        $quantity_used = $jobPart['quantity_used'];

        try {
            $pdo->beginTransaction();

            // Delete job_parts entry
            $stmt = $pdo->prepare("DELETE FROM job_parts WHERE id = ?");
            $stmt->execute([$jobPartId]);

            // Return quantity to inventory
            $stmt = $pdo->prepare("UPDATE inventory_parts SET quantity_on_hand = quantity_on_hand + ? WHERE id = ?");
            $stmt->execute([$quantity_used, $part_id]);

            logActivity($pdo, 'Deleted part from job', 'job_parts', $jobPartId, 
                        "Job ID: " . $jobPart['job_id'] . ", Part: " . e($jobPart['part_name']) . ", Returned Qty: $quantity_used");
            setSuccessMessage('Part successfully removed from job and returned to inventory.');
            
            $pdo->commit();
            redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $jobPart['job_id']);

        } catch (PDOException $e) {
            $pdo->rollBack();
            setErrorMessage('Failed to delete part from job: ' . $e->getMessage());
        }
    }
}

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-boxes"></i> Edit Job Part</h1>
    <a href="<?php echo APP_URL; ?>/modules/jobs/job_details.php?id=<?php echo $jobPart['job_id']; ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Job Details
    </a>
</div>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    <strong>Job:</strong> <?php echo e($jobPart['job_number']); ?> - <?php echo e($jobPart['number_plate']); ?><br>
    <strong>Part:</strong> <?php echo e($jobPart['part_name']); ?> (<?php echo e($jobPart['part_number']); ?>)<br>
    <strong>Current Stock:</strong> <?php echo e($jobPart['current_stock'] + $jobPart['quantity_used']); // current stock if this part wasn't used ?>
</div>

<div class="card">
    <div class="card-header">
        <h3>Adjust Part Quantity</h3>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="update">
        <div class="card-body">
            <div class="form-group">
                <label for="quantity_used">Quantity Used <span class="required">*</span></label>
                <input type="number" id="quantity_used" name="quantity_used" class="form-control" 
                       min="1" required 
                       value="<?php echo e($jobPart['quantity_used']); ?>">
                <small class="form-hint">Available in inventory: <?php echo e($jobPart['current_stock'] + $jobPart['quantity_used']); ?></small>
            </div>
        </div>
        
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Update Quantity
            </button>
            <a href="<?php echo APP_URL; ?>/modules/jobs/job_details.php?id=<?php echo $jobPart['job_id']; ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<div class="card mt-4">
    <div class="card-header">
        <h3>Remove Part from Job</h3>
    </div>
    <div class="card-body">
        <p>This action will remove the part from this job and return the quantity used (<?php echo e($jobPart['quantity_used']); ?>) to the inventory stock.</p>
        <form method="POST" onsubmit="return confirmDelete('Are you sure you want to remove this part from the job? This action cannot be undone.')">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-danger">
                <i class="fas fa-trash"></i> Remove Part
            </button>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
