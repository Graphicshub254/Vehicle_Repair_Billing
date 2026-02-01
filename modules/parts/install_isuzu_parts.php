<?php
require_once '../../config/config.php';
requireLogin();

$jobId = intval($_GET['job_id'] ?? 0);

// Load job
$stmt = $pdo->prepare("
    SELECT j.*, v.number_plate FROM jobs j JOIN vehicles v ON j.vehicle_id = v.id WHERE j.id = ?
");
$stmt->execute([$jobId]);
$job = $stmt->fetch();

if (!$job) {
    setErrorMessage('Job not found');
    redirect(APP_URL . '/modules/jobs/jobs.php');
}

// Handle installation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = intval($_POST['item_id'] ?? 0);
    $quantity_installing = intval($_POST['quantity_installing'] ?? 0);
    $installation_notes = trim($_POST['installation_notes'] ?? '');
    
    if ($quantity_installing > 0) {
        $stmt = $pdo->prepare("
            SELECT quantity_received, quantity_installed 
            FROM supplier_invoice_items 
            WHERE id = ?
        ");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch();
        
        if ($item) {
            $outstanding = $item['quantity_received'] - $item['quantity_installed'];
            
            if ($quantity_installing <= $outstanding) {
                $new_installed = $item['quantity_installed'] + $quantity_installing;
                $new_status = ($new_installed >= $item['quantity_received']) ? 'fully_installed' : 'partially_installed';
                
                $stmt = $pdo->prepare("
                    UPDATE supplier_invoice_items 
                    SET quantity_installed = ?, installation_status = ?, installed_date = CURRENT_TIMESTAMP, 
                        installed_by = ?, installation_notes = ?
                    WHERE id = ?
                ");
                
                if ($stmt->execute([$new_installed, $new_status, $_SESSION['user_id'], $installation_notes, $item_id])) {
                    logActivity($pdo, 'Installed Isuzu parts', 'supplier_invoice_items', $item_id, "Installed: $quantity_installing units");
                    setSuccessMessage("Parts installed successfully");
                    redirect(APP_URL . '/modules/parts/install_isuzu_parts.php?job_id=' . $jobId);
                }
            } else {
                setErrorMessage("Cannot install more than outstanding quantity ($outstanding)");
            }
        }
    }
}

// Get parts for this job
$stmt = $pdo->prepare("
    SELECT sii.*, si.invoice_number, q.quotation_number
    FROM supplier_invoice_items sii
    JOIN supplier_invoices si ON sii.supplier_invoice_id = si.id
    JOIN quotations q ON si.quotation_id = q.id
    WHERE q.job_id = ? AND sii.installation_status != 'fully_installed'
    ORDER BY si.invoice_date DESC, sii.item_no
");
$stmt->execute([$jobId]);
$parts = $stmt->fetchAll();

$pageTitle = 'Install Isuzu Parts';
$breadcrumbs = [
    ['text' => 'Jobs', 'url' => APP_URL . '/modules/jobs/jobs.php'],
    ['text' => $job['job_number'], 'url' => APP_URL . '/modules/jobs/job_details.php?id=' . $jobId],
    ['text' => 'Install Parts']
];

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-wrench"></i> Install Isuzu Parts</h1>
    <a href="<?php echo APP_URL; ?>/modules/jobs/job_details.php?id=<?php echo $jobId; ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Job
    </a>
</div>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    <strong>Job:</strong> <?php echo e($job['job_number']); ?> - <?php echo e($job['number_plate']); ?>
</div>

<?php if (empty($parts)): ?>
<div class="card">
    <div class="card-body">
        <p class="text-center text-muted">
            <i class="fas fa-check-circle" style="font-size: 48px; color: #10b981; margin-bottom: 15px;"></i><br>
            All Isuzu parts for this job have been installed!
        </p>
    </div>
</div>
<?php else: ?>

<div class="card">
    <div class="card-header">
        <h3>Parts Awaiting Installation</h3>
    </div>
    <div class="card-body">
        <?php foreach ($parts as $part): ?>
        <?php
        $outstanding = $part['quantity_received'] - $part['quantity_installed'];
        ?>
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-header" style="background: #f9fafb;">
                <strong><?php echo e($part['part_number']); ?></strong> - <?php echo e($part['description']); ?>
                <span style="float: right;">Invoice: <?php echo e($part['invoice_number']); ?></span>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="item_id" value="<?php echo $part['id']; ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Ordered</label>
                            <p><strong><?php echo $part['quantity']; ?></strong> units</p>
                        </div>
                        <div class="form-group">
                            <label>Received</label>
                            <p><strong><?php echo $part['quantity_received']; ?></strong> units</p>
                        </div>
                        <div class="form-group">
                            <label>Already Installed</label>
                            <p><strong><?php echo $part['quantity_installed']; ?></strong> units</p>
                        </div>
                        <div class="form-group">
                            <label>Outstanding</label>
                            <p><strong style="color: #f59e0b;"><?php echo $outstanding; ?></strong> units</p>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="quantity_installing_<?php echo $part['id']; ?>">Installing Now <span class="required">*</span></label>
                            <input type="number" id="quantity_installing_<?php echo $part['id']; ?>" name="quantity_installing" 
                                   class="form-control" min="1" max="<?php echo $outstanding; ?>" value="<?php echo $outstanding; ?>" required>
                            <small class="form-hint">Max: <?php echo $outstanding; ?> units</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="installation_notes_<?php echo $part['id']; ?>">Installation Notes</label>
                            <textarea id="installation_notes_<?php echo $part['id']; ?>" name="installation_notes" 
                                      class="form-control" rows="2" placeholder="e.g., Installed on front wheels"></textarea>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check"></i> Mark as Installed
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
