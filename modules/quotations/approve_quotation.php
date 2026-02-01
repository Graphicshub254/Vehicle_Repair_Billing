<?php
require_once '../../config/config.php';
requireLogin();
requireDirector();

$pageTitle = 'Approve Quotations';
$breadcrumbs = [
    ['text' => 'Quotations', 'url' => APP_URL . '/modules/quotations/quotations.php'],
    ['text' => 'Approve']
];

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quotation_id = intval($_POST['quotation_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    
    if ($action === 'approve') {
        $stmt = $pdo->prepare("
            UPDATE quotations 
            SET status = 'approved', approved_by = ?, approval_date = CURRENT_TIMESTAMP 
            WHERE id = ? AND status = 'pending_approval'
        ");
        
        if ($stmt->execute([$_SESSION['user_id'], $quotation_id])) {
            // Update job status
            $stmt = $pdo->prepare("
                UPDATE jobs j
                JOIN quotations q ON j.id = q.job_id
                SET j.status = 'awaiting_parts'
                WHERE q.id = ?
            ");
            $stmt->execute([$quotation_id]);
            
            logActivity($pdo, 'Approved quotation', 'quotations', $quotation_id);
            setSuccessMessage('Quotation approved successfully');
        } else {
            setErrorMessage('Failed to approve quotation');
        }
        
    } elseif ($action === 'reject') {
        $rejection_reason = trim($_POST['rejection_reason'] ?? '');
        
        if (empty($rejection_reason)) {
            setErrorMessage('Rejection reason is required');
        } else {
            $stmt = $pdo->prepare("
                UPDATE quotations 
                SET status = 'rejected', rejection_reason = ? 
                WHERE id = ? AND status = 'pending_approval'
            ");
            
            if ($stmt->execute([$rejection_reason, $quotation_id])) {
                // Update job status back to open
                $stmt = $pdo->prepare("
                    UPDATE jobs j
                    JOIN quotations q ON j.id = q.job_id
                    SET j.status = 'open'
                    WHERE q.id = ?
                ");
                $stmt->execute([$quotation_id]);
                
                logActivity($pdo, 'Rejected quotation', 'quotations', $quotation_id, "Reason: $rejection_reason");
                setSuccessMessage('Quotation rejected');
            } else {
                setErrorMessage('Failed to reject quotation');
            }
        }
    }
    
    redirect(APP_URL . '/modules/quotations/approve_quotation.php');
}

// Get pending quotations
$stmt = $pdo->query("
    SELECT q.*, j.job_number, v.number_plate, v.make, v.model,
           s.name as supplier_name,
           u.full_name as prepared_by_name,
           (SELECT COUNT(*) FROM quotation_items WHERE quotation_id = q.id) as item_count,
           (SELECT SUM(total_amount) FROM quotation_items WHERE quotation_id = q.id) as total_amount
    FROM quotations q
    JOIN jobs j ON q.job_id = j.id
    JOIN vehicles v ON j.vehicle_id = v.id
    JOIN suppliers s ON q.supplier_id = s.id
    JOIN users u ON q.prepared_by = u.id
    WHERE q.status = 'pending_approval'
    ORDER BY q.created_at ASC
");
$pending_quotations = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-check-circle"></i> Approve Quotations</h1>
    <a href="<?php echo APP_URL; ?>/modules/quotations/quotations.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Quotations
    </a>
</div>

<?php if (empty($pending_quotations)): ?>
<div class="card">
    <div class="card-body">
        <p class="text-center text-muted">
            <i class="fas fa-check-circle" style="font-size: 48px; color: #10b981; margin-bottom: 15px;"></i><br>
            No pending quotations! All quotations have been reviewed.
        </p>
    </div>
</div>
<?php else: ?>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    You have <strong><?php echo count($pending_quotations); ?></strong> quotation(s) awaiting approval.
</div>

<?php foreach ($pending_quotations as $q): ?>
<div class="card">
    <div class="card-header">
        <h3>
            <i class="fas fa-file-invoice"></i> 
            <?php echo e($q['quotation_number']); ?> - 
            <?php echo e($q['job_number']); ?> 
            (<?php echo e($q['number_plate']); ?>)
        </h3>
        <a href="view_quotation.php?id=<?php echo $q['id']; ?>" class="btn btn-sm btn-info" target="_blank">
            <i class="fas fa-eye"></i> View Full Details
        </a>
    </div>
    <div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label>Vehicle</label>
                <p><?php echo e($q['number_plate'] . ' - ' . $q['make'] . ' ' . $q['model']); ?></p>
            </div>
            <div class="form-group">
                <label>Supplier</label>
                <p><?php echo e($q['supplier_name']); ?></p>
            </div>
            <div class="form-group">
                <label>Prepared By</label>
                <p><?php echo e($q['prepared_by_name']); ?></p>
            </div>
            <div class="form-group">
                <label>Date</label>
                <p><?php echo formatDate($q['quotation_date']); ?></p>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Items</label>
                <p><?php echo $q['item_count']; ?> parts</p>
            </div>
            <div class="form-group">
                <label>Total Amount</label>
                <p><strong style="font-size: 18px; color: #667eea;"><?php echo formatCurrency($q['total_amount']); ?></strong></p>
            </div>
        </div>
        
        <?php if ($q['notes']): ?>
        <div style="margin-top: 15px; padding: 15px; background: #f9fafb; border-left: 4px solid #667eea;">
            <strong>Notes:</strong><br>
            <?php echo nl2br(e($q['notes'])); ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-footer">
        <!-- Approve Button -->
        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to APPROVE this quotation?')">
            <input type="hidden" name="quotation_id" value="<?php echo $q['id']; ?>">
            <input type="hidden" name="action" value="approve">
            <button type="submit" class="btn btn-success">
                <i class="fas fa-check"></i> Approve
            </button>
        </form>
        
        <!-- Reject Button -->
        <button type="button" class="btn btn-danger" onclick="showRejectModal(<?php echo $q['id']; ?>)">
            <i class="fas fa-times"></i> Reject
        </button>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Reject Modal -->
<div id="reject-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: white; padding: 30px; border-radius: 10px; max-width: 500px; width: 90%;">
        <h3 style="margin-top: 0;"><i class="fas fa-times-circle" style="color: #ef4444;"></i> Reject Quotation</h3>
        <form method="POST" id="reject-form">
            <input type="hidden" name="quotation_id" id="reject-quotation-id">
            <input type="hidden" name="action" value="reject">
            
            <div class="form-group">
                <label for="rejection_reason">Reason for Rejection <span class="required">*</span></label>
                <textarea id="rejection_reason" name="rejection_reason" class="form-control" rows="4" required 
                          placeholder="Please provide a detailed reason for rejecting this quotation..."></textarea>
                <small class="form-hint">This will be visible to the person who created the quotation</small>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="hideRejectModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-times"></i> Reject Quotation
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showRejectModal(quotationId) {
    document.getElementById('reject-quotation-id').value = quotationId;
    document.getElementById('reject-modal').style.display = 'flex';
    document.getElementById('rejection_reason').value = '';
    document.getElementById('rejection_reason').focus();
}

function hideRejectModal() {
    document.getElementById('reject-modal').style.display = 'none';
}

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideRejectModal();
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
