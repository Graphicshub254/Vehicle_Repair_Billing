<?php
require_once '../../config/config.php';
requireLogin();

$quotationId = intval($_GET['id'] ?? 0);

// Load quotation
$stmt = $pdo->prepare("
    SELECT q.*, j.job_number, v.number_plate, v.make, v.model,
           s.name as supplier_name, s.contact_person, s.phone, s.email,
           u.full_name as prepared_by_name,
           approver.full_name as approved_by_name
    FROM quotations q
    JOIN jobs j ON q.job_id = j.id
    JOIN vehicles v ON j.vehicle_id = v.id
    JOIN suppliers s ON q.supplier_id = s.id
    JOIN users u ON q.prepared_by = u.id
    LEFT JOIN users approver ON q.approved_by = approver.id
    WHERE q.id = ?
");
$stmt->execute([$quotationId]);
$quotation = $stmt->fetch();

if (!$quotation) {
    setErrorMessage('Quotation not found');
    redirect(APP_URL . '/modules/quotations/quotations.php');
}

// Load quotation items
$stmt = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY item_no");
$stmt->execute([$quotationId]);
$items = $stmt->fetchAll();

// Calculate totals
$grandTotal = 0;
foreach ($items as $item) {
    $grandTotal += $item['total_amount'];
}

$pageTitle = 'Quotation - ' . $quotation['quotation_number'];
$breadcrumbs = [
    ['text' => 'Quotations', 'url' => APP_URL . '/modules/quotations/quotations.php'],
    ['text' => $quotation['quotation_number']]
];

// Load company settings
$settings = getCompanySettings($pdo);

// Load supplier invoice if exists
$supplierInvoice = null;
$stmt = $pdo->prepare("SELECT si.* FROM supplier_invoices si WHERE si.quotation_id = ?");
$stmt->execute([$quotationId]);
$supplierInvoice = $stmt->fetch();

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-file-invoice"></i> <?php echo e($quotation['quotation_number']); ?></h1>
    <div>
        <a href="<?php echo APP_URL; ?>/modules/quotations/quotations.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Quotations
        </a>
        <?php if ($quotation['status'] === 'draft'): ?>
        <a href="edit_quotation.php?id=<?php echo $quotationId; ?>" class="btn btn-warning">
            <i class="fas fa-edit"></i> Edit Quotation
        </a>
        <?php endif; ?>
        <?php if ($quotation['status'] === 'approved'): ?>
        <a href="enter_supplier_invoice.php?quotation_id=<?php echo $quotationId; ?>" class="btn btn-success">
            <i class="fas fa-file-invoice"></i> Enter Supplier Invoice
        </a>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print"></i> Print
        </button>
    </div>
</div>

<!-- Status Alert -->
<?php if ($quotation['status'] === 'pending_approval'): ?>
<div class="alert alert-warning">
    <i class="fas fa-clock"></i>
    <strong>Pending Approval</strong> - This quotation is awaiting director approval.
    <?php if (isDirector()): ?>
        <a href="approve_quotation.php" class="btn btn-sm btn-primary" style="margin-left: 15px;">
            <i class="fas fa-check"></i> Go to Approvals
        </a>
    <?php endif; ?>
</div>
<?php elseif ($quotation['status'] === 'approved'): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i>
    <strong>Approved</strong> by <?php echo e($quotation['approved_by_name']); ?> on <?php echo formatDateTime($quotation['approval_date']); ?>
</div>
<?php elseif ($quotation['status'] === 'rejected'): ?>
<div class="alert alert-error">
    <i class="fas fa-times-circle"></i>
    <strong>Rejected</strong> - <?php echo e($quotation['rejection_reason']); ?>
</div>
<?php endif; ?>

<!-- Quotation Display -->
<div class="card" id="quotation-content">
    <div class="card-body" style="padding: 40px;">
        <!-- Header -->
        <div style="display: flex; justify-content: space-between; margin-bottom: 30px; border-bottom: 2px solid #667eea; padding-bottom: 20px;">
            <div>
                <h1 style="margin: 0; font-size: 32px; color: #667eea;">
                    <?php echo e($settings['company_name']); ?>
                </h1>
                <p style="margin: 5px 0 0 0; color: #6b7280;">
                    <?php echo nl2br(e($settings['address'])); ?><br>
                    <?php echo e($settings['phone']); ?><br>
                    <?php echo e($settings['email']); ?>
                </p>
            </div>
            <div style="text-align: right;">
                <h2 style="margin: 0; font-size: 28px; color: #1f2937;">QUOTATION</h2>
                <p style="margin: 10px 0 0 0; font-size: 16px;">
                    <strong><?php echo e($quotation['quotation_number']); ?></strong>
                </p>
                <p style="margin: 5px 0 0 0; color: #6b7280;">
                    Date: <?php echo formatDate($quotation['quotation_date']); ?>
                </p>
            </div>
        </div>

        <!-- Meta Info -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; padding: 15px; background: #f9fafb; border-radius: 5px;">
            <div>
                <label>Created By</label>
                <p><strong><?php echo e($quotation['prepared_by_name']); ?></strong></p>
                <small class="text-muted"><?php echo formatDateTime($quotation['created_at']); ?></small>
            </div>
            <?php if ($quotation['status'] === 'approved'): ?>
            <div>
                <label>Approved By</label>
                <p><strong><?php echo e($quotation['approved_by_name']); ?></strong></p>
                <small class="text-muted"><?php echo formatDateTime($quotation['approval_date']); ?></small>
            </div>
            <?php endif; ?>
             <div>
                <label>Job</label>
                <p><a href="<?php echo APP_URL; ?>/modules/jobs/job_details.php?id=<?php echo $quotation['job_id']; ?>"><strong><?php echo e($quotation['job_number']); ?></strong></a></p>
            </div>
            <div>
                <label>Vehicle</label>
                <p><a href="<?php echo APP_URL; ?>/modules/vehicles/vehicle_history.php?id=<?php echo $quotation['vehicle_id']; ?>"><strong><?php echo e($quotation['number_plate']); ?></strong></a></p>
                <small class="text-muted"><?php echo e($quotation['make'] . ' ' . $quotation['model']); ?></small>
            </div>
        </div>
        
        <!-- Supplier & Job Info -->
        <div style="display: grid; grid-template-columns: 1fr; gap: 30px; margin-bottom: 30px;">
            <div>
                <h3 style="margin: 0 0 10px 0; color: #667eea;">Supplier Details:</h3>
                <p style="margin: 0; line-height: 1.6;">
                    <strong><?php echo e($quotation['supplier_name']); ?></strong><br>
                    <?php if ($quotation['contact_person']): ?>
                        Contact: <?php echo e($quotation['contact_person']); ?><br>
                    <?php endif; ?>
                    <?php if ($quotation['phone']): ?>
                        Phone: <?php echo e($quotation['phone']); ?><br>
                    <?php endif; ?>
                    <?php if ($quotation['email']): ?>
                        Email: <?php echo e($quotation['email']); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        
        <!-- Items Table -->
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
            <thead>
                <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                    <th style="padding: 12px; text-align: left;">#</th>
                    <th style="padding: 12px; text-align: left;">Part Number</th>
                    <th style="padding: 12px; text-align: left;">Description</th>
                    <th style="padding: 12px; text-align: center;">Qty</th>
                    <th style="padding: 12px; text-align: right;">List Price</th>
                    <th style="padding: 12px; text-align: right;">Disc. Price</th>
                    <th style="padding: 12px; text-align: right;">Subtotal</th>
                    <th style="padding: 12px; text-align: right;">VAT 16%</th>
                    <th style="padding: 12px; text-align: right;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr style="border-bottom: 1px solid #e5e7eb;">
                    <td style="padding: 12px;"><?php echo $item['item_no']; ?></td>
                    <td style="padding: 12px;"><?php echo e($item['part_number']); ?></td>
                    <td style="padding: 12px;"><?php echo e($item['description']); ?></td>
                    <td style="padding: 12px; text-align: center;"><?php echo $item['quantity']; ?></td>
                    <td style="padding: 12px; text-align: right;"><?php echo formatCurrency($item['list_price']); ?></td>
                    <td style="padding: 12px; text-align: right;"><?php echo formatCurrency($item['discount_price']); ?></td>
                    <td style="padding: 12px; text-align: right;"><?php echo formatCurrency($item['price_excluding_vat']); ?></td>
                    <td style="padding: 12px; text-align: right;"><?php echo formatCurrency($item['vat_amount']); ?></td>
                    <td style="padding: 12px; text-align: right;"><strong><?php echo formatCurrency($item['total_amount']); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background: #f9fafb; border-top: 2px solid #1f2937; font-size: 18px; font-weight: bold;">
                    <td colspan="8" style="padding: 15px; text-align: right;">GRAND TOTAL:</td>
                    <td style="padding: 15px; text-align: right; color: #667eea;"><?php echo formatCurrency($grandTotal); ?></td>
                </tr>
            </tfoot>
        </table>
        
        <?php if ($quotation['notes']): ?>
        <!-- Notes -->
        <div style="margin-top: 20px; padding: 15px; background: #f9fafb; border-left: 4px solid #667eea;">
            <strong>Notes:</strong><br>
            <?php echo nl2br(e($quotation['notes'])); ?>
        </div>
        <?php endif; ?>
        
        <!-- Footer -->
        <div style="margin-top: 30px; border-top: 1px solid #e5e7eb; padding-top: 20px; text-align: center; color: #6b7280; font-size: 13px;">
            <p style="margin: 5px 0;">This quotation is valid for 30 days from the date of issue.</p>
            <p style="margin: 5px 0;">Status: <?php echo getStatusBadge($quotation['status']); ?></p>
        </div>
    </div>
</div>

<style>
@media print {
    .navbar, .breadcrumb, .page-header, .alert, .footer, .btn {
        display: none !important;
    }
    
    #quotation-content {
        box-shadow: none !important;
        margin: 0 !important;
    }
    
    body {
        background: white !important;
    }
}
</style>

<?php if ($supplierInvoice): ?>
<div class="card" style="margin-top: 20px;">
    <div class="card-header">
        <h3><i class="fas fa-file-invoice-dollar"></i> Associated Supplier Invoice</h3>
    </div>
    <div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label>Supplier Invoice Number</label>
                <p><strong><?php echo e($supplierInvoice['invoice_number']); ?></strong></p>
            </div>
            <div class="form-group">
                <label>Invoice Date</label>
                <p><?php echo formatDate($supplierInvoice['invoice_date']); ?></p>
            </div>
            <div class="form-group">
                <label>Received Date</label>
                <p><?php echo formatDate($supplierInvoice['received_date']); ?></p>
            </div>
            <div class="form-group">
                <label>Final Amount</label>
                <p><strong><?php echo formatCurrency($supplierInvoice['final_amount']); ?></strong></p>
            </div>
        </div>
        <div class="text-right">
            <a href="enter_supplier_invoice.php?quotation_id=<?php echo $quotationId; ?>&view=true" class="btn btn-sm btn-info">
                <i class="fas fa-eye"></i> View Supplier Invoice Details
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
