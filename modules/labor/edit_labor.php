<?php
require_once '../../config/config.php';
requireLogin();

$laborId = intval($_GET['id'] ?? 0);

// Load labor charge
$stmt = $pdo->prepare("
    SELECT lc.*, j.job_number, v.number_plate
    FROM labor_charges lc
    JOIN jobs j ON lc.job_id = j.id
    JOIN vehicles v ON j.vehicle_id = v.id
    WHERE lc.id = ?
");
$stmt->execute([$laborId]);
$labor = $stmt->fetch();

if (!$labor) {
    setErrorMessage('Labor charge not found');
    redirect(APP_URL . '/modules/jobs/jobs.php');
}

// Check if already invoiced
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM customer_invoice_items 
    WHERE item_type = 'labor' AND reference_id = ?
");
$stmt->execute([$laborId]);
$invoiced = $stmt->fetch()['count'] > 0;

if ($invoiced) {
    setErrorMessage('Cannot edit labor charge that has already been invoiced');
    redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $labor['job_id']);
}

$pageTitle = 'Edit Labor Charge';
$breadcrumbs = [
    ['text' => 'Jobs', 'url' => APP_URL . '/modules/jobs/jobs.php'],
    ['text' => $labor['job_number'], 'url' => APP_URL . '/modules/jobs/job_details.php?id=' . $labor['job_id']],
    ['text' => 'Edit Labor']
];

// Determine pricing method
$pricingMethod = ($labor['hours'] && $labor['rate']) ? 'hourly' : 'fixed';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = trim($_POST['description'] ?? '');
    $performed_by = trim($_POST['performed_by'] ?? '');
    $date_performed = $_POST['date_performed'] ?? date('Y-m-d');
    $pricing_method = $_POST['pricing_method'] ?? 'hourly';
    
    if (empty($description)) {
        setErrorMessage('Description is required');
    } else {
        $hours = null;
        $rate = null;
        $fixed_amount = null;
        $total_amount = 0;
        
        if ($pricing_method === 'hourly') {
            $hours = floatval($_POST['hours'] ?? 0);
            $rate = floatval($_POST['rate'] ?? 0);
            
            if ($hours <= 0 || $rate <= 0) {
                setErrorMessage('Hours and rate must be greater than zero for hourly pricing');
            } else {
                $total_amount = $hours * $rate;
            }
        } else {
            $fixed_amount = floatval($_POST['fixed_amount'] ?? 0);
            
            if ($fixed_amount <= 0) {
                setErrorMessage('Fixed amount must be greater than zero');
            } else {
                $total_amount = $fixed_amount;
            }
        }
        
        if ($total_amount > 0) {
            $stmt = $pdo->prepare("
                UPDATE labor_charges 
                SET description = ?, hours = ?, rate = ?, fixed_amount = ?, 
                    total_amount = ?, performed_by = ?, date_performed = ?
                WHERE id = ?
            ");
            
            if ($stmt->execute([$description, $hours, $rate, $fixed_amount, $total_amount, $performed_by, $date_performed, $laborId])) {
                logActivity($pdo, 'Updated labor charge', 'labor_charges', $laborId, "New amount: " . formatCurrency($total_amount));
                setSuccessMessage('Labor charge updated successfully');
                redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $labor['job_id']);
            } else {
                setErrorMessage('Failed to update labor charge');
            }
        }
    }
}

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-users-cog"></i> Edit Labor Charge</h1>
    <a href="<?php echo APP_URL; ?>/modules/jobs/job_details.php?id=<?php echo $labor['job_id']; ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Job
    </a>
</div>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    <strong>Job:</strong> <?php echo e($labor['job_number']); ?> - <?php echo e($labor['number_plate']); ?>
</div>

<div class="card">
    <form method="POST" id="laborForm">
        <div class="card-header">
            <h3>Labor Charge Information</h3>
        </div>
        
        <div class="card-body">
            <div class="form-group">
                <label for="description">Work Description <span class="required">*</span></label>
                <textarea id="description" name="description" class="form-control" rows="4" required><?php echo e($labor['description']); ?></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="performed_by">Performed By</label>
                    <input type="text" id="performed_by" name="performed_by" class="form-control" 
                           value="<?php echo e($labor['performed_by']); ?>" placeholder="e.g., John Mechanic">
                </div>
                
                <div class="form-group">
                    <label for="date_performed">Date Performed <span class="required">*</span></label>
                    <input type="date" id="date_performed" name="date_performed" class="form-control" 
                           value="<?php echo $labor['date_performed']; ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Pricing Method <span class="required">*</span></label>
                <div style="display: flex; gap: 20px; margin-top: 10px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="radio" name="pricing_method" value="hourly" <?php echo $pricingMethod === 'hourly' ? 'checked' : ''; ?> onchange="togglePricingMethod()">
                        <span>Hourly Rate</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="radio" name="pricing_method" value="fixed" <?php echo $pricingMethod === 'fixed' ? 'checked' : ''; ?> onchange="togglePricingMethod()">
                        <span>Fixed Amount</span>
                    </label>
                </div>
            </div>
            
            <!-- Hourly Pricing Fields -->
            <div id="hourly-fields" class="form-row" style="display: <?php echo $pricingMethod === 'hourly' ? 'grid' : 'none'; ?>;">
                <div class="form-group">
                    <label for="hours">Hours Worked <span class="required">*</span></label>
                    <input type="number" id="hours" name="hours" class="form-control" 
                           placeholder="e.g., 3.5" step="0.25" min="0" 
                           value="<?php echo $labor['hours'] ?: '1'; ?>" onchange="calculateTotal()">
                </div>
                
                <div class="form-group">
                    <label for="rate">Rate per Hour (KES) <span class="required">*</span></label>
                    <input type="number" id="rate" name="rate" class="form-control" 
                           placeholder="e.g., 1500" step="0.01" min="0" 
                           value="<?php echo $labor['rate'] ?: '1500'; ?>" onchange="calculateTotal()">
                </div>
                
                <div class="form-group">
                    <label>Total Amount</label>
                    <input type="text" id="hourly-total" class="form-control" readonly 
                           style="background: #f3f4f6; font-weight: bold;">
                </div>
            </div>
            
            <!-- Fixed Pricing Fields -->
            <div id="fixed-fields" class="form-row" style="display: <?php echo $pricingMethod === 'fixed' ? 'grid' : 'none'; ?>;">
                <div class="form-group">
                    <label for="fixed_amount">Fixed Amount (KES) <span class="required">*</span></label>
                    <input type="number" id="fixed_amount" name="fixed_amount" class="form-control" 
                           placeholder="e.g., 5000" step="0.01" min="0" 
                           value="<?php echo $labor['fixed_amount'] ?: ''; ?>" onchange="updateFixedTotal()">
                </div>
                
                <div class="form-group">
                    <label>Total Amount</label>
                    <input type="text" id="fixed-total" class="form-control" readonly 
                           style="background: #f3f4f6; font-weight: bold;">
                </div>
            </div>
        </div>
        
        <div class="card-footer">
            <a href="<?php echo APP_URL; ?>/modules/jobs/job_details.php?id=<?php echo $labor['job_id']; ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Update Labor Charge
            </button>
        </div>
    </form>
</div>

<script>
function togglePricingMethod() {
    const method = document.querySelector('input[name="pricing_method"]:checked').value;
    const hourlyFields = document.getElementById('hourly-fields');
    const fixedFields = document.getElementById('fixed-fields');
    
    if (method === 'hourly') {
        hourlyFields.style.display = 'grid';
        fixedFields.style.display = 'none';
        document.getElementById('hours').required = true;
        document.getElementById('rate').required = true;
        document.getElementById('fixed_amount').required = false;
        calculateTotal();
    } else {
        hourlyFields.style.display = 'none';
        fixedFields.style.display = 'grid';
        document.getElementById('hours').required = false;
        document.getElementById('rate').required = false;
        document.getElementById('fixed_amount').required = true;
        updateFixedTotal();
    }
}

function calculateTotal() {
    const hours = parseFloat(document.getElementById('hours').value) || 0;
    const rate = parseFloat(document.getElementById('rate').value) || 0;
    const total = hours * rate;
    document.getElementById('hourly-total').value = formatCurrency(total);
}

function updateFixedTotal() {
    const amount = parseFloat(document.getElementById('fixed_amount').value) || 0;
    document.getElementById('fixed-total').value = formatCurrency(amount);
}

// Initialize on page load
<?php if ($pricingMethod === 'hourly'): ?>
calculateTotal();
<?php else: ?>
updateFixedTotal();
<?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>
