<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = 'Add Labor Charge';
$breadcrumbs = [
    ['text' => 'Jobs', 'url' => APP_URL . '/modules/jobs/jobs.php'],
    ['text' => 'Add Labor']
];

$preselectedJob = intval($_GET['job_id'] ?? 0);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $job_id = intval($_POST['job_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $performed_by = trim($_POST['performed_by'] ?? '');
    $date_performed = $_POST['date_performed'] ?? date('Y-m-d');
    $pricing_method = $_POST['pricing_method'] ?? 'hourly';
    
    // Validate
    if ($job_id <= 0 || empty($description)) {
        setErrorMessage('Job and description are required');
    } else {
        // Verify job exists
        $stmt = $pdo->prepare("SELECT id FROM jobs WHERE id = ?");
        $stmt->execute([$job_id]);
        
        if (!$stmt->fetch()) {
            setErrorMessage('Invalid job selected');
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
                    INSERT INTO labor_charges (job_id, description, hours, rate, fixed_amount, total_amount, performed_by, date_performed, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                if ($stmt->execute([$job_id, $description, $hours, $rate, $fixed_amount, $total_amount, $performed_by, $date_performed, $_SESSION['user_id']])) {
                    logActivity($pdo, 'Added labor charge', 'labor_charges', $pdo->lastInsertId(), "Job ID: $job_id, Amount: " . formatCurrency($total_amount));
                    setSuccessMessage('Labor charge added successfully');
                    redirect(APP_URL . '/modules/jobs/job_details.php?id=' . $job_id);
                } else {
                    setErrorMessage('Failed to add labor charge');
                }
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

// Get company settings for default labor rate
$settings = getCompanySettings($pdo);
$defaultRate = 1500; // Default labor rate per hour

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-users-cog"></i> Add Labor Charge</h1>
    <a href="<?php echo APP_URL; ?>/modules/jobs/jobs.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Jobs
    </a>
</div>

<div class="card">
    <form method="POST" id="laborForm">
        <div class="card-header">
            <h3>Labor Charge Information</h3>
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
                <label for="description">Work Description <span class="required">*</span></label>
                <textarea id="description" name="description" class="form-control" rows="4" required 
                          placeholder="Describe the labor/work performed..."></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="performed_by">Performed By</label>
                    <input type="text" id="performed_by" name="performed_by" class="form-control" 
                           placeholder="e.g., John Mechanic">
                    <small class="form-hint">Name of technician/mechanic who did the work</small>
                </div>
                
                <div class="form-group">
                    <label for="date_performed">Date Performed <span class="required">*</span></label>
                    <input type="date" id="date_performed" name="date_performed" class="form-control" 
                           value="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Pricing Method <span class="required">*</span></label>
                <div style="display: flex; gap: 20px; margin-top: 10px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="radio" name="pricing_method" value="hourly" checked onchange="togglePricingMethod()">
                        <span>Hourly Rate</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="radio" name="pricing_method" value="fixed" onchange="togglePricingMethod()">
                        <span>Fixed Amount</span>
                    </label>
                </div>
            </div>
            
            <!-- Hourly Pricing Fields -->
            <div id="hourly-fields" class="form-row">
                <div class="form-group">
                    <label for="hours">Hours Worked <span class="required">*</span></label>
                    <input type="number" id="hours" name="hours" class="form-control" 
                           placeholder="e.g., 3.5" step="0.25" min="0" value="1" onchange="calculateTotal()">
                </div>
                
                <div class="form-group">
                    <label for="rate">Rate per Hour (KES) <span class="required">*</span></label>
                    <input type="number" id="rate" name="rate" class="form-control" 
                           placeholder="e.g., 1500" step="0.01" min="0" value="<?php echo $defaultRate; ?>" onchange="calculateTotal()">
                </div>
                
                <div class="form-group">
                    <label>Total Amount</label>
                    <input type="text" id="hourly-total" class="form-control" readonly 
                           style="background: #f3f4f6; font-weight: bold;" value="KES 1,500.00">
                </div>
            </div>
            
            <!-- Fixed Pricing Fields -->
            <div id="fixed-fields" class="form-row" style="display: none;">
                <div class="form-group">
                    <label for="fixed_amount">Fixed Amount (KES) <span class="required">*</span></label>
                    <input type="number" id="fixed_amount" name="fixed_amount" class="form-control" 
                           placeholder="e.g., 5000" step="0.01" min="0" onchange="updateFixedTotal()">
                </div>
                
                <div class="form-group">
                    <label>Total Amount</label>
                    <input type="text" id="fixed-total" class="form-control" readonly 
                           style="background: #f3f4f6; font-weight: bold;" value="KES 0.00">
                </div>
            </div>
        </div>
        
        <div class="card-footer">
            <a href="<?php echo APP_URL; ?>/modules/jobs/jobs.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Add Labor Charge
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

// Initialize
calculateTotal();
</script>

<?php include '../../includes/footer.php'; ?>
