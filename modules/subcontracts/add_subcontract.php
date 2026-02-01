<?php
require_once '../../config/config.php';
requireLogin();

// Only procurement officers and directors can create subcontracts
if (!isProcurementOfficer() && !isDirector()) {
    setErrorMessage('You do not have permission to create subcontracts');
    redirect(APP_URL . '/modules/dashboard/dashboard.php');
}

$pageTitle = 'Add Subcontract Work';
$breadcrumbs = [
    ['text' => 'Subcontracts', 'url' => APP_URL . '/modules/subcontracts/subcontracts.php'],
    ['text' => 'Add Subcontract']
];

$preselectedJob = intval($_GET['job_id'] ?? 0);

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
            
            // Generate work number
            $work_number = generateWorkNumber($pdo);
            
            // Create subcontract work
            $stmt = $pdo->prepare("
                INSERT INTO subcontract_works (
                    work_number, job_id, subcontractor_id, work_type, work_description,
                    location, part_number, quantity, unit_cost, total_cost,
                    start_date, completion_date, notes, status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)
            ");
            $stmt->execute([
                $work_number, $job_id, $subcontractor_id, $work_type, $work_description,
                $location, $part_number, $quantity, $unit_cost, $total_cost,
                $start_date, $completion_date, $notes, $_SESSION['user_id']
            ]);
            $subcontract_id = $pdo->lastInsertId();
            
            // Update job status (optional, depending on workflow)
            // For now, let's assume it moves to 'with_subcontractor'
            $stmt = $pdo->prepare("UPDATE jobs SET status = 'with_subcontractor' WHERE id = ?");
            $stmt->execute([$job_id]);
            
            // Log activity
            logActivity($pdo, 'Created subcontract work', 'subcontract_works', $subcontract_id, "Work: $work_number");
            
            $pdo->commit();
            
            setSuccessMessage("Subcontract work $work_number created successfully.");
            redirect(APP_URL . '/modules/subcontracts/view_subcontract.php?id=' . $subcontract_id);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            setErrorMessage('Failed to create subcontract work: ' . $e->getMessage());
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
    <h1><i class="fas fa-hammer"></i> Add Subcontract Work</h1>
    <a href="<?php echo APP_URL; ?>/modules/subcontracts/subcontracts.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Subcontracts
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
                        <option value="<?php echo $j['id']; ?>" <?php echo $preselectedJob == $j['id'] ? 'selected' : ''; ?>>
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
                        <option value="<?php echo $s['id']; ?>"><?php echo e($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="work_type">Work Type <span class="required">*</span></label>
                    <select id="work_type" name="work_type" class="form-control" onchange="togglePartDetails()" required>
                        <option value="">-- Select type --</option>
                        <option value="service">Service</option>
                        <option value="parts">Parts</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="work_description">Work Description <span class="required">*</span></label>
                <textarea id="work_description" name="work_description" class="form-control" rows="3" required
                          placeholder="Detailed description of the subcontract work..."></textarea>
            </div>

            <div class="form-row" id="part-details" style="display: none;">
                <div class="form-group">
                    <label for="part_number">Part Number</label>
                    <input type="text" id="part_number" name="part_number" class="form-control" 
                           placeholder="e.g., ABC-123">
                </div>
                <div class="form-group">
                    <label for="quantity">Quantity <span class="required">*</span></label>
                    <input type="number" id="quantity" name="quantity" class="form-control" value="1" min="1">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="unit_cost">Unit Cost <span class="required">*</span></label>
                    <input type="number" id="unit_cost" name="unit_cost" class="form-control" step="0.01" min="0" value="0" onchange="calculateTotalCost()" required>
                </div>
                <div class="form-group">
                    <label for="total_cost">Total Cost</label>
                    <input type="number" id="total_cost" name="total_cost" class="form-control" step="0.01" min="0" value="0" readonly>
                </div>
                <div class="form-group">
                    <label for="start_date">Start Date <span class="required">*</span></label>
                    <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="completion_date">Completion Date</label>
                    <input type="date" id="completion_date" name="completion_date" class="form-control">
                </div>
            </div>

            <div class="form-group">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="3" 
                          placeholder="Any additional notes or special instructions..."></textarea>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-footer">
            <a href="<?php echo APP_URL; ?>/modules/subcontracts/subcontracts.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-success btn-lg">
                <i class="fas fa-plus-circle"></i> Create Subcontract Work
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
