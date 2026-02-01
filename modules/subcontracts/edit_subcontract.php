<?php
require_once '../../config/config.php';
requireLogin();

// Only procurement officers and directors can edit subcontracts
if (!isProcurementOfficer() && !isDirector()) {
    setErrorMessage('You do not have permission to edit subcontracts');
    redirect(APP_URL . '/modules/dashboard/dashboard.php');
}

$subcontractId = intval($_GET['id'] ?? 0);

// Fetch existing subcontract work data
$stmt = $pdo->prepare("
    SELECT sw.*, j.job_number, v.number_plate, v.make, v.model, sub.name as subcontractor_name
    FROM subcontract_works sw
    JOIN jobs j ON sw.job_id = j.id
    JOIN vehicles v ON j.vehicle_id = v.id
    JOIN subcontractors sub ON sw.subcontractor_id = sub.id
    WHERE sw.id = ?
");
$stmt->execute([$subcontractId]);
$subcontract = $stmt->fetch();

if (!$subcontract) {
    setErrorMessage('Subcontract work not found.');
    redirect(APP_URL . '/modules/subcontracts/subcontracts.php');
}

// Check if subcontract is editable (only 'draft' or 'pending_approval' if it can be re-edited before approval)
// For now, let's allow editing if it's 'draft'
if ($subcontract['status'] !== 'draft') {
    setErrorMessage('Only subcontract works in "Draft" status can be edited.');
    redirect(APP_URL . '/modules/subcontracts/view_subcontract.php?id=' . $subcontractId);
}

$pageTitle = 'Edit Subcontract Work - ' . $subcontract['work_number'];
$breadcrumbs = [
    ['text' => 'Subcontracts', 'url' => APP_URL . '/modules/subcontracts/subcontracts.php'],
    ['text' => $subcontract['work_number'], 'url' => APP_URL . '/modules/subcontracts/view_subcontract.php?id=' . $subcontractId],
    ['text' => 'Edit Subcontract Work']
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $job_id = intval($_POST['job_id'] ?? 0);
    $subcontractor_id = intval($_POST['subcontractor_id'] ?? 0);
    $work_type = $_POST['work_type'] ?? '';
    $work_description = trim($_POST['work_description'] ?? '');
    $location = $_POST['location'] ?? 'n/a';
    $part_number = trim($_POST['part_number'] ?? '');
    $quantity = intval($_POST['quantity'] ?? 1);
    $unit_cost = floatval($_POST['unit_cost'] ?? 0);
    $start_date = $_POST['start_date'] ?? date('Y-m-d');
    $completion_date = $_POST['completion_date'] ?? null;
    $notes = trim($_POST['notes'] ?? '');
    
    $total_cost = $quantity * $unit_cost;

    // Validate
    if ($job_id <= 0 || $subcontractor_id <= 0 || empty($work_type) || empty($work_description) || $unit_cost <= 0 || $quantity <= 0) {
        setErrorMessage('All required fields must be filled and costs/quantity must be positive.');
    } elseif ($work_type === 'parts' && empty($part_number)) {
        setErrorMessage('Part Number is required for parts subcontract work.');
    } else {
        try {
            $pdo->beginTransaction();
            
            // Update subcontract work
            $stmt = $pdo->prepare("
                UPDATE subcontract_works SET
                    job_id = ?, subcontractor_id = ?, work_type = ?, work_description = ?,
                    location = ?, part_number = ?, quantity = ?, unit_cost = ?, total_cost = ?,
                    start_date = ?, completion_date = ?, notes = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $newStatus = ($subcontract['status'] === 'draft') ? 'pending_approval' : $subcontract['status'];
            
            $stmt->execute([
                $job_id, $subcontractor_id, $work_type, $work_description,
                $location, $part_number, $quantity, $unit_cost, $total_cost,
                $start_date, $completion_date, $notes, $newStatus, $subcontractId
            ]);
            
            // Log activity
            logActivity($pdo, 'Updated subcontract work', 'subcontract_works', $subcontractId, "Work: " . $subcontract['work_number']);
            
            $pdo->commit();
            
            setSuccessMessage("Subcontract work " . $subcontract['work_number'] . " updated successfully.");
            redirect(APP_URL . '/modules/subcontracts/view_subcontract.php?id=' . $subcontractId);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            setErrorMessage('Failed to update subcontract work: ' . $e->getMessage());
        }
    }
}

// Get jobs (open or in progress)
$jobsStmt = $pdo->query("
    SELECT j.id, j.job_number, v.number_plate, v.make, v.model
    FROM jobs j
    JOIN vehicles v ON j.vehicle_id = v.id
    WHERE j.status IN ('open', 'in_progress', 'awaiting_parts', 'with_subcontractor')
    ORDER BY j.created_at DESC
");
$jobs = $jobsStmt->fetchAll();

// Get subcontractors
$subcontractorsStmt = $pdo->query("
    SELECT * FROM subcontractors WHERE is_active = 1 ORDER BY name
");
$subcontractors = $subcontractorsStmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-hammer"></i> Edit Subcontract Work - <?php echo e($subcontract['work_number']); ?></h1>
    <a href="<?php echo APP_URL; ?>/modules/subcontracts/view_subcontract.php?id=<?php echo $subcontractId; ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Subcontract
    </a>
</div>

<form method="POST">
    <div class="card">
        <div class="card-header">
            <h3>Subcontract Details</h3>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label for="job_id">Select Job <span class="required">*</span></label>
                    <select id="job_id" name="job_id" class="form-control" required>
                        <option value="">-- Select a job --</option>
                        <?php foreach ($jobs as $j): ?>
                        <option value="<?php echo $j['id']; ?>" <?php echo $subcontract['job_id'] == $j['id'] ? 'selected' : ''; ?>>
                            <?php echo e($j['job_number']); ?> - <?php echo e($j['number_plate']); ?> (<?php echo e($j['make'] . ' ' . $j['model']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="subcontractor_id">Subcontractor <span class="required">*</span></label>
                    <select id="subcontractor_id" name="subcontractor_id" class="form-control" required>
                        <option value="">-- Select subcontractor --</option>
                        <?php foreach ($subcontractors as $s): ?>
                        <option value="<?php echo $s['id']; ?>" <?php echo $subcontract['subcontractor_id'] == $s['id'] ? 'selected' : ''; ?>>
                            <?php echo e($s['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="work_type">Work Type <span class="required">*</span></label>
                    <select id="work_type" name="work_type" class="form-control" onchange="togglePartDetails()" required>
                        <option value="">-- Select type --</option>
                        <option value="service" <?php echo $subcontract['work_type'] == 'service' ? 'selected' : ''; ?>>Service</option>
                        <option value="parts" <?php echo $subcontract['work_type'] == 'parts' ? 'selected' : ''; ?>>Parts</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="work_description">Work Description <span class="required">*</span></label>
                <textarea id="work_description" name="work_description" class="form-control" rows="3" required
                          placeholder="Detailed description of the subcontract work..."><?php echo e($subcontract['work_description']); ?></textarea>
            </div>

            <div class="form-row" id="part-details" style="display: none;">
                <div class="form-group">
                    <label for="part_number">Part Number</label>
                    <input type="text" id="part_number" name="part_number" class="form-control" 
                           placeholder="e.g., ABC-123" value="<?php echo e($subcontract['part_number']); ?>">
                </div>
                <div class="form-group">
                    <label for="quantity">Quantity <span class="required">*</span></label>
                    <input type="number" id="quantity" name="quantity" class="form-control" value="<?php echo $subcontract['quantity']; ?>" min="1" onchange="calculateTotalCost()">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="unit_cost">Unit Cost <span class="required">*</span></label>
                    <input type="number" id="unit_cost" name="unit_cost" class="form-control" step="0.01" min="0" value="<?php echo $subcontract['unit_cost']; ?>" onchange="calculateTotalCost()" required>
                </div>
                <div class="form-group">
                    <label for="total_cost">Total Cost</label>
                    <input type="number" id="total_cost" name="total_cost" class="form-control" step="0.01" min="0" value="<?php echo $subcontract['total_cost']; ?>" readonly>
                </div>
                <div class="form-group">
                    <label for="start_date">Start Date <span class="required">*</span></label>
                    <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo e($subcontract['start_date']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="completion_date">Completion Date</label>
                    <input type="date" id="completion_date" name="completion_date" class="form-control" value="<?php echo e($subcontract['completion_date']); ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="3" 
                          placeholder="Any additional notes or special instructions..."><?php echo e($subcontract['notes']); ?></textarea>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-footer">
            <a href="<?php echo APP_URL; ?>/modules/subcontracts/view_subcontract.php?id=<?php echo $subcontractId; ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-success btn-lg">
                <i class="fas fa-check-circle"></i> Update Subcontract Work
            </button>
        </div>
    </div>
</form>

<script>
function togglePartDetails() {
    const workType = document.getElementById('work_type').value;
    const partDetails = document.getElementById('part-details');
    const partNumberInput = document.getElementById('part_number');
    const quantityInput = document.getElementById('quantity');

    if (workType === 'parts') {
        partDetails.style.display = 'grid';
        partNumberInput.setAttribute('required', 'required');
        quantityInput.setAttribute('required', 'required');
    } else {
        partDetails.style.display = 'none';
        partNumberInput.removeAttribute('required');
        quantityInput.removeAttribute('required');
        partNumberInput.value = ''; // Clear if not parts
        quantityInput.value = '1'; // Reset quantity
    }
}

function calculateTotalCost() {
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const unitCost = parseFloat(document.getElementById('unit_cost').value) || 0;
    const totalCost = quantity * unitCost;
    document.getElementById('total_cost').value = totalCost.toFixed(2);
}

// Initial call to set visibility and calculate cost
togglePartDetails();
calculateTotalCost();
</script>

<?php include '../../includes/footer.php'; ?>
