<?php
require_once '../../config/config.php';
requireLogin();

$workId = intval($_GET['work_id'] ?? 0);

// Load subcontract work
$stmt = $pdo->prepare("
    SELECT sw.*, j.job_number, v.number_plate, sub.name as subcontractor_name
    FROM subcontract_works sw
    JOIN jobs j ON sw.job_id = j.id
    JOIN vehicles v ON j.vehicle_id = v.id
    JOIN subcontractors sub ON sw.subcontractor_id = sub.id
    WHERE sw.id = ? AND sw.work_type = 'parts'
");
$stmt->execute([$workId]);
$work = $stmt->fetch();

if (!$work) {
    setErrorMessage('Subcontract work not found or is not a parts order');
    redirect(APP_URL . '/modules/subcontracts/subcontracts.php');
}

// Handle installation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quantity_installing = intval($_POST['quantity_installing'] ?? 0);
    $installation_notes = trim($_POST['installation_notes'] ?? '');
    
    if ($quantity_installing > 0 && $quantity_installing <= $work['quantity']) {
        $stmt = $pdo->prepare("
            UPDATE subcontract_works 
            SET installation_status = 'fully_installed', installed_date = CURRENT_TIMESTAMP, 
                installed_by = ?, installation_notes = ?, status = 'completed', completion_date = CURRENT_DATE
            WHERE id = ?
        ");
        
        if ($stmt->execute([$_SESSION['user_id'], $installation_notes, $workId])) {
            logActivity($pdo, 'Installed subcontract parts', 'subcontract_works', $workId, "Part: " . $work['part_number']);
            setSuccessMessage("Parts installed and work marked as completed");
            redirect(APP_URL . '/modules/subcontracts/view_subcontract.php?id=' . $workId);
        } else {
            setErrorMessage('Failed to mark parts as installed');
        }
    } else {
        setErrorMessage('Invalid quantity');
    }
}

$pageTitle = 'Install Subcontract Parts';
$breadcrumbs = [
    ['text' => 'Subcontracts', 'url' => APP_URL . '/modules/subcontracts/subcontracts.php'],
    ['text' => $work['work_number'], 'url' => APP_URL . '/modules/subcontracts/view_subcontract.php?id=' . $workId],
    ['text' => 'Install Parts']
];

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-wrench"></i> Install Subcontract Parts</h1>
    <a href="<?php echo APP_URL; ?>/modules/subcontracts/view_subcontract.php?id=<?php echo $workId; ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Subcontract
    </a>
</div>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    <strong>Work:</strong> <?php echo e($work['work_number']); ?> - 
    <strong>Job:</strong> <?php echo e($work['job_number']); ?> - 
    <strong>Vehicle:</strong> <?php echo e($work['number_plate']); ?>
</div>

<?php if ($work['installation_status'] === 'fully_installed'): ?>
<div class="card">
    <div class="card-body">
        <p class="text-center text-muted">
            <i class="fas fa-check-circle" style="font-size: 48px; color: #10b981; margin-bottom: 15px;"></i><br>
            These parts have already been installed!<br>
            <small>Installed on: <?php echo formatDateTime($work['installed_date']); ?></small>
        </p>
    </div>
</div>
<?php else: ?>

<div class="card">
    <div class="card-header">
        <h3>Parts to Install</h3>
    </div>
    <div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label>Subcontractor</label>
                <p><strong><?php echo e($work['subcontractor_name']); ?></strong></p>
            </div>
            <div class="form-group">
                <label>Part Number</label>
                <p><?php echo $work['part_number'] ? e($work['part_number']) : '-'; ?></p>
            </div>
            <div class="form-group">
                <label>Quantity</label>
                <p><strong><?php echo $work['quantity']; ?></strong> units</p>
            </div>
            <div class="form-group">
                <label>Cost</label>
                <p><?php echo formatCurrency($work['total_cost']); ?></p>
            </div>
        </div>
        
        <div class="form-group">
            <label>Description</label>
            <p><?php echo nl2br(e($work['work_description'])); ?></p>
        </div>
        
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label for="quantity_installing">Quantity Installing <span class="required">*</span></label>
                    <input type="number" id="quantity_installing" name="quantity_installing" class="form-control" 
                           min="1" max="<?php echo $work['quantity']; ?>" value="<?php echo $work['quantity']; ?>" required>
                    <small class="form-hint">Total ordered: <?php echo $work['quantity']; ?> units</small>
                </div>
                
                <div class="form-group">
                    <label for="installation_notes">Installation Notes</label>
                    <textarea id="installation_notes" name="installation_notes" class="form-control" rows="3" 
                              placeholder="e.g., Installed without issues, perfect fit"></textarea>
                </div>
            </div>
            
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Note:</strong> Marking these parts as installed will also mark the entire subcontract work as <strong>completed</strong>.
            </div>
            
            <button type="submit" class="btn btn-success btn-lg">
                <i class="fas fa-check-circle"></i> Mark as Installed & Complete Work
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
