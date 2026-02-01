<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = 'Add Part to Job';
$breadcrumbs = [
    ['text' => 'Jobs', 'url' => APP_URL . '/modules/jobs/jobs.php'],
    ['text' => 'Add Part']
];

$preselectedJob = intval($_GET['job_id'] ?? 0);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $job_id = intval($_POST['job_id'] ?? 0);
    $part_id = intval($_POST['part_id'] ?? 0);
    $quantity_used = intval($_POST['quantity_used'] ?? 0);
    
    // Validate
    if ($job_id <= 0 || $part_id <= 0 || $quantity_used <= 0) {
        setErrorMessage('Job, Part, and Quantity Used are required');
    } else {
        // Verify job exists
        $stmt = $pdo->prepare("SELECT id FROM jobs WHERE id = ?");
        $stmt->execute([$job_id]);
        $jobExists = $stmt->fetch();

        // Verify part exists and get details
        $stmt = $pdo->prepare("SELECT part_name, quantity_on_hand, selling_price FROM inventory_parts WHERE id = ?");
        $stmt->execute([$part_id]);
        $part = $stmt->fetch();
        
        if (!$jobExists) {
            setErrorMessage('Invalid job selected');
        } elseif (!$part) {
            setErrorMessage('Invalid part selected');
        } elseif ($quantity_used > $part['quantity_on_hand']) {
            setErrorMessage('Not enough quantity on hand for ' . e($part['part_name']) . '. Available: ' . $part['quantity_on_hand']);
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Insert into job_parts table
                $stmt = $pdo->prepare("
                    INSERT INTO job_parts (job_id, part_id, quantity_used, price_per_unit)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$job_id, $part_id, $quantity_used, $part['selling_price']]);
                $jobPartId = $pdo->lastInsertId();

                // 2. Decrement quantity_on_hand in inventory_parts table
                $stmt = $pdo->prepare("UPDATE inventory_parts SET quantity_on_hand = quantity_on_hand - ? WHERE id = ?");
                $stmt->execute([$quantity_used, $part_id]);

                // 3. Log activity
                logActivity($pdo, 'Added part to job', 'job_parts', $jobPartId, 
                            "Job ID: $job_id, Part: " . e($part['part_name']) . ", Qty: $quantity_used");
                setSuccessMessage('Part added to job successfully');
                
                $pdo->commit();
                redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $job_id);

            } catch (PDOException $e) {
                $pdo->rollBack();
                setErrorMessage('Failed to add part to job: ' . $e->getMessage());
            }
        }
    }
}

// Get all jobs for dropdown
$jobsStmt = $pdo->query("
    SELECT j.id, j.job_number, v.number_plate, v.make, v.model
    FROM jobs j
    JOIN vehicles v ON j.vehicle_id = v.id
    WHERE j.status != 'invoiced'
    ORDER BY j.created_at DESC
");
$allJobs = $jobsStmt->fetchAll();

// Get available inventory parts for dropdown (only show parts with quantity > 0)
$partsStmt = $pdo->query("
    SELECT id, part_name, part_number, quantity_on_hand, selling_price 
    FROM inventory_parts 
    WHERE quantity_on_hand > 0 
    ORDER BY part_name
");
$availableParts = $partsStmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-boxes"></i> Add Part to Job</h1>
    <a href="<?php echo APP_URL; ?>/modules/jobs/job_details.php?id=<?php echo $preselectedJob; ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Job Details
    </a>
</div>

<div class="card">
    <form method="POST" id="addPartToJobForm">
        <div class="card-header">
            <h3>Part Assignment Information</h3>
        </div>
        
        <div class="card-body">
            <div class="form-group">
                <label for="job_id">Select Job <span class="required">*</span></label>
                <select id="job_id" name="job_id" class="form-control" required>
                    <option value="">-- Select a job --</option>
                    <?php foreach ($allJobs as $j): ?>
                    <option value="<?php echo $j['id']; ?>" <?php echo $preselectedJob == $j['id'] ? 'selected' : ''; ?>>
                        <?php echo e($j['job_number']); ?> - <?php echo e($j['number_plate']); ?> (<?php echo e($j['make'] . ' ' . $j['model']); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="part_id">Select Part <span class="required">*</span></label>
                <select id="part_id" name="part_id" class="form-control" required>
                    <option value="">-- Select an inventory part --</option>
                    <?php foreach ($availableParts as $part): ?>
                    <option value="<?php echo $part['id']; ?>" 
                            data-quantity-on-hand="<?php echo $part['quantity_on_hand']; ?>"
                            data-selling-price="<?php echo $part['selling_price']; ?>">
                        <?php echo e($part['part_name']); ?> (<?php echo e($part['part_number']); ?>) - Stock: <?php echo $part['quantity_on_hand']; ?> - Price: <?php echo formatCurrency($part['selling_price']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($availableParts)): ?>
                    <small class="form-hint text-danger">No parts available in inventory. <a href="<?php echo APP_URL; ?>/modules/inventory/inventory.php?action=create">Add new parts</a> or <a href="<?php echo APP_URL; ?>/modules/inventory/inventory.php">adjust stock</a>.</small>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="quantity_used">Quantity Used <span class="required">*</span></label>
                <input type="number" id="quantity_used" name="quantity_used" class="form-control" 
                       min="1" required value="1" onchange="validateQuantity()">
                <small class="form-hint" id="quantity_hint"></small>
            </div>
        </div>
        
        <div class="card-footer">
            <a href="<?php echo APP_URL; ?>/modules/jobs/job_details.php?id=<?php echo $preselectedJob; ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Add Part
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const partSelect = document.getElementById('part_id');
    const quantityInput = document.getElementById('quantity_used');
    const quantityHint = document.getElementById('quantity_hint');

    function validateQuantity() {
        const selectedOption = partSelect.options[partSelect.selectedIndex];
        const quantityOnHand = parseInt(selectedOption.getAttribute('data-quantity-on-hand') || '0');
        const quantityUsed = parseInt(quantityInput.value || '0');

        if (selectedOption.value === "") {
            quantityHint.textContent = "";
            quantityInput.max = "";
            return;
        }

        quantityInput.max = quantityOnHand; // Set max attribute for browser validation

        if (quantityUsed > quantityOnHand) {
            quantityInput.classList.add('is-invalid');
            quantityHint.classList.add('text-danger');
            quantityHint.textContent = `Error: Only ${quantityOnHand} available in stock.`;
        } else {
            quantityInput.classList.remove('is-invalid');
            quantityHint.classList.remove('text-danger');
            quantityHint.textContent = `Available in stock: ${quantityOnHand}`;
        }
    }

    partSelect.addEventListener('change', validateQuantity);
    quantityInput.addEventListener('input', validateQuantity);
    
    // Initial call in case a job/part is pre-selected
    validateQuantity();
});
</script>

<?php include '../../includes/footer.php'; ?>
