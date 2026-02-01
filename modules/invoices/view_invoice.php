<?php
require_once '../../config/config.php';
requireLogin();

$invoiceId = intval($_GET['id'] ?? 0);

// Load invoice with all details
$stmt = $pdo->prepare("
    SELECT ci.*, j.job_number, v.number_plate, v.make, v.model, v.owner_name, v.owner_phone, v.owner_email,
           u.full_name as generated_by_name
    FROM customer_invoices ci
    JOIN jobs j ON ci.job_id = j.id
    JOIN vehicles v ON j.vehicle_id = v.id
    JOIN users u ON ci.generated_by = u.id
    WHERE ci.id = ?
");
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    setErrorMessage('Invoice not found');
    redirect(APP_URL . '/modules/invoices/invoices.php');
}

// Load invoice items
$stmt = $pdo->prepare("SELECT * FROM customer_invoice_items WHERE customer_invoice_id = ? ORDER BY item_no");
$stmt->execute([$invoiceId]);
$items = $stmt->fetchAll();

// Load company settings
$settings = getCompanySettings($pdo);

$pageTitle = 'Invoice - ' . $invoice['invoice_number'];
$breadcrumbs = [
    ['text' => 'Invoices', 'url' => APP_URL . '/modules/invoices/invoices.php'],
    ['text' => $invoice['invoice_number']]
];

// Handle reprint
if (isset($_GET['reprint'])) {
    $stmt = $pdo->prepare("UPDATE customer_invoices SET reprint_count = reprint_count + 1, last_printed_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$invoiceId]);
    logActivity($pdo, 'Reprinted invoice', 'customer_invoices', $invoiceId, "Invoice: " . $invoice['invoice_number']);
    redirect(APP_URL . '/modules/invoices/view_invoice.php?id=' . $invoiceId);
}

$isCopy = isset($_GET['reprint']) || $invoice['reprint_count'] > 0;

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-file-invoice-dollar"></i> <?php echo e($invoice['invoice_number']); ?></h1>
    <div>
        <a href="<?php echo APP_URL; ?>/modules/invoices/invoices.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Invoices
        </a>
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print"></i> Print
        </button>
        <a href="?id=<?php echo $invoiceId; ?>&reprint=1" class="btn btn-info">
            <i class="fas fa-copy"></i> Reprint
        </a>
    </div>
</div>

<!-- Invoice Display -->
<div class="card" id="invoice-content">
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
                <h2 style="margin: 0; font-size: 28px; color: #1f2937;">
                    INVOICE <?php echo $isCopy ? '- COPY' : ''; ?>
                </h2>
                <p style="margin: 10px 0 0 0; font-size: 16px;">
                    <strong><?php echo e($invoice['invoice_number']); ?></strong>
                </p>
                <p style="margin: 5px 0 0 0; color: #6b7280;">
                    Date: <?php echo formatDate($invoice['invoice_date']); ?>
                </p>
            </div>
        </div>
        
        <!-- Bill To / Vehicle Info -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px;">
            <div>
                <h3 style="margin: 0 0 10px 0; color: #667eea;">Bill To:</h3>
                <p style="margin: 0; line-height: 1.6;">
                    <strong><?php echo e($invoice['owner_name']); ?></strong><br>
                    <?php if ($invoice['owner_phone']): ?>
                        Phone: <?php echo e($invoice['owner_phone']); ?><br>
                    <?php endif; ?>
                    <?php if ($invoice['owner_email']): ?>
                        Email: <?php echo e($invoice['owner_email']); ?>
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <h3 style="margin: 0 0 10px 0; color: #667eea;">Vehicle:</h3>
                <p style="margin: 0; line-height: 1.6;">
                    <strong><?php echo e($invoice['number_plate']); ?></strong><br>
                    <?php echo e($invoice['make'] . ' ' . $invoice['model']); ?><br>
                    Job #: <?php echo e($invoice['job_number']); ?>
                </p>
            </div>
        </div>
        
        <!-- Invoice Type Badge -->
        <div style="margin-bottom: 20px;">
            <?php if ($invoice['invoice_type'] === 'progress'): ?>
                <span style="display: inline-block; padding: 8px 16px; background: #fef3c7; color: #92400e; border-radius: 6px; font-weight: 500;">
                    Progress Invoice
                </span>
            <?php else: ?>
                <span style="display: inline-block; padding: 8px 16px; background: #d1fae5; color: #065f46; border-radius: 6px; font-weight: 500;">
                    Final Invoice
                </span>
            <?php endif; ?>
        </div>
        
        <!-- Items Table -->
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
            <thead>
                <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                    <th style="padding: 12px; text-align: left;">#</th>
                    <th style="padding: 12px; text-align: left;">Description</th>
                    <th style="padding: 12px; text-align: center;">Qty</th>
                    <th style="padding: 12px; text-align: right;">Unit Price</th>
                    <th style="padding: 12px; text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr style="border-bottom: 1px solid #e5e7eb;">
                    <td style="padding: 12px;"><?php echo $item['item_no']; ?></td>
                    <td style="padding: 12px;"><?php echo nl2br(e($item['description'])); ?></td>
                    <td style="padding: 12px; text-align: center;"><?php echo $item['quantity']; ?></td>
                    <td style="padding: 12px; text-align: right;"><?php echo formatCurrency($item['unit_price_after_discount']); ?></td>
                    <td style="padding: 12px; text-align: right;"><strong><?php echo formatCurrency($item['total_amount']); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Totals -->
        <div style="max-width: 400px; margin: 0 0 30px auto;">
            <table style="width: 100%; font-size: 14px;">
                <tr>
                    <td style="padding: 8px 12px;">Subtotal:</td>
                    <td style="padding: 8px 12px; text-align: right;"><?php echo formatCurrency($invoice['subtotal_before_discount']); ?></td>
                </tr>
                <?php if ($invoice['overall_discount_percentage'] > 0): ?>
                <tr>
                    <td style="padding: 8px 12px;">Discount (<?php echo number_format($invoice['overall_discount_percentage'], 1); ?>%):</td>
                    <td style="padding: 8px 12px; text-align: right; color: #ef4444;">-<?php echo formatCurrency($invoice['total_discount']); ?></td>
                </tr>
                <tr style="border-top: 1px solid #e5e7eb;">
                    <td style="padding: 8px 12px;">Subtotal after discount:</td>
                    <td style="padding: 8px 12px; text-align: right;"><?php echo formatCurrency($invoice['subtotal_after_discount']); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td style="padding: 8px 12px;">VAT (<?php echo number_format($invoice['vat_percentage'], 0); ?>%):</td>
                    <td style="padding: 8px 12px; text-align: right;"><?php echo formatCurrency($invoice['vat_amount']); ?></td>
                </tr>
                <tr style="border-top: 2px solid #1f2937; font-size: 18px; font-weight: bold; background: #f9fafb;">
                    <td style="padding: 15px 12px;">TOTAL AMOUNT:</td>
                    <td style="padding: 15px 12px; text-align: right; color: #667eea;"><?php echo formatCurrency($invoice['total_amount']); ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Footer -->
        <div style="border-top: 1px solid #e5e7eb; padding-top: 20px; text-align: center; color: #6b7280; font-size: 13px;">
            <p style="margin: 5px 0;">Thank you for your business!</p>
            <p style="margin: 5px 0;">Generated by: <?php echo e($invoice['generated_by_name']); ?> on <?php echo formatDateTime($invoice['created_at']); ?></p>
            <?php if ($invoice['reprint_count'] > 0): ?>
                <p style="margin: 5px 0;">Reprinted <?php echo $invoice['reprint_count']; ?> time(s). Last printed: <?php echo formatDateTime($invoice['last_printed_at']); ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (isDirector()): ?>
<!-- Profit Analysis (Director Only) -->
<div class="card" style="margin-top: 20px;">
    <div class="card-header">
        <h3><i class="fas fa-chart-line"></i> Profit Analysis</h3>
    </div>
    <div class="card-body">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon stat-icon-warning">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo formatCurrency($invoice['total_cost']); ?></h3>
                    <p>Total Cost</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon stat-icon-success">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo formatCurrency($invoice['total_amount']); ?></h3>
                    <p>Total Revenue</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon stat-icon-info">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo formatCurrency($invoice['total_profit']); ?></h3>
                    <p>Total Profit</p>
                    <div class="stat-trend">
                        <span class="<?php echo $invoice['profit_percentage'] >= 15 ? 'trend-up' : 'trend-down'; ?>">
                            <?php echo number_format($invoice['profit_percentage'], 1); ?>% margin
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
@media print {
    .navbar, .breadcrumb, .page-header, .card:not(#invoice-content), .footer, .btn {
        display: none !important;
    }
    
    #invoice-content {
        box-shadow: none !important;
        margin: 0 !important;
    }
    
    body {
        background: white !important;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>
