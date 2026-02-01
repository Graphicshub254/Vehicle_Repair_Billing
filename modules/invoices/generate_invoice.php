<?php
require_once '../../config/config.php';
requireLogin();

$jobId = intval($_GET['job_id'] ?? 0);

// Load job
$stmt = $pdo->prepare("
    SELECT j.*, v.number_plate, v.make, v.model, v.owner_name, v.owner_phone, v.owner_email
    FROM jobs j
    JOIN vehicles v ON j.vehicle_id = v.id
    WHERE j.id = ?
");
$stmt->execute([$jobId]);
$job = $stmt->fetch();

if (!$job) {
    setErrorMessage('Job not found');
    redirect(APP_URL . '/modules/jobs/jobs.php');
}

$pageTitle = 'Generate Invoice - ' . $job['job_number'];
$breadcrumbs = [
    ['text' => 'Jobs', 'url' => APP_URL . '/modules/jobs/jobs.php'],
    ['text' => $job['job_number'], 'url' => APP_URL . '/modules/jobs/job_details.php?id=' . $jobId],
    ['text' => 'Generate Invoice']
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_invoice'])) {
    $invoice_type = $_POST['invoice_type'] ?? 'final';
    $selected_items = $_POST['selected_items'] ?? [];
    $markups = $_POST['markup'] ?? [];
    $item_discounts = $_POST['item_discount'] ?? [];
    $overall_discount = floatval($_POST['overall_discount'] ?? 0);
    
    if (empty($selected_items)) {
        setErrorMessage('Please select at least one item to invoice');
    } else {
        try {
            $pdo->beginTransaction();
            
            // Generate invoice number
            $invoice_number = generateInvoiceNumber($pdo);
            $invoice_date = date('Y-m-d');
            
            // Get company settings
            $settings = getCompanySettings($pdo);
            $vat_rate = $settings['vat_rate'];
            
            // Calculate totals
            $total_cost = 0;
            $subtotal_before_discount = 0;
            $items_data = [];
            
            // Process each selected item
            foreach ($selected_items as $item_id) {
                // Get labor charge
                $stmt = $pdo->prepare("SELECT * FROM labor_charges WHERE id = ?");
                $stmt->execute([$item_id]);
                $labor = $stmt->fetch();
                
                if (!$labor) continue;
                
                $quantity = 1;
                $unit_cost = $labor['total_amount'];
                $total_cost_item = $unit_cost * $quantity;
                
                // Markup
                $markup_pct = floatval($markups[$item_id] ?? 0);
                $unit_price_before_discount = $unit_cost * (1 + $markup_pct / 100);
                
                // Item discount
                $discount_pct = floatval($item_discounts[$item_id] ?? 0);
                $discount_amount = $unit_price_before_discount * ($discount_pct / 100);
                $unit_price_after_discount = $unit_price_before_discount - $discount_amount;
                
                // Subtotal (before overall discount and VAT)
                $item_subtotal = $unit_price_after_discount * $quantity;
                
                $items_data[] = [
                    'labor_id' => $item_id,
                    'description' => $labor['description'],
                    'quantity' => $quantity,
                    'unit_cost' => $unit_cost,
                    'total_cost' => $total_cost_item,
                    'markup_pct' => $markup_pct,
                    'unit_price_before_discount' => $unit_price_before_discount,
                    'discount_pct' => $discount_pct,
                    'discount_amount' => $discount_amount,
                    'unit_price_after_discount' => $unit_price_after_discount,
                    'subtotal' => $item_subtotal
                ];
                
                $total_cost += $total_cost_item;
                $subtotal_before_discount += $item_subtotal;
            }
            
            // Apply overall discount
            $overall_discount_amount = $subtotal_before_discount * ($overall_discount / 100);
            $subtotal_after_discount = $subtotal_before_discount - $overall_discount_amount;
            
            // Calculate VAT
            $vat_amount = $subtotal_after_discount * ($vat_rate / 100);
            
            // Final total
            $total_amount = $subtotal_after_discount + $vat_amount;
            
            // Calculate profit
            $total_profit = $total_amount - $total_cost;
            $profit_percentage = $total_amount > 0 ? ($total_profit / $total_amount) * 100 : 0;
            
            // Create invoice
            $stmt = $pdo->prepare("
                INSERT INTO customer_invoices (
                    invoice_number, job_id, invoice_date, invoice_type,
                    labor_cost, total_cost, labor_total,
                    subtotal_before_discount, overall_discount_percentage, total_discount,
                    subtotal_after_discount, vat_percentage, vat_amount, total_amount,
                    total_profit, profit_percentage, generated_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $invoice_number, $jobId, $invoice_date, $invoice_type,
                $total_cost, $total_cost, $subtotal_before_discount,
                $subtotal_before_discount, $overall_discount, $overall_discount_amount,
                $subtotal_after_discount, $vat_rate, $vat_amount, $total_amount,
                $total_profit, $profit_percentage, $_SESSION['user_id']
            ]);
            
            $invoice_id = $pdo->lastInsertId();
            
            // Create invoice items
            $item_no = 1;
            foreach ($items_data as $item) {
                // Apply overall discount proportionally
                $item_subtotal_after_overall = $item['subtotal'] * (1 - $overall_discount / 100);
                $item_vat = $item_subtotal_after_overall * ($vat_rate / 100);
                $item_total = $item_subtotal_after_overall + $item_vat;
                $item_profit = $item_total - $item['total_cost'];
                
                $stmt = $pdo->prepare("
                    INSERT INTO customer_invoice_items (
                        customer_invoice_id, item_type, reference_id, item_no,
                        description, quantity, unit_cost, total_cost,
                        markup_percentage, unit_price_before_discount,
                        discount_percentage, discount_amount, unit_price_after_discount,
                        subtotal, vat_amount, total_amount, profit_amount
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $invoice_id, 'labor', $item['labor_id'], $item_no++,
                    $item['description'], $item['quantity'], $item['unit_cost'], $item['total_cost'],
                    $item['markup_pct'], $item['unit_price_before_discount'],
                    $item['discount_pct'], $item['discount_amount'], $item['unit_price_after_discount'],
                    $item_subtotal_after_overall, $item_vat, $item_total, $item_profit
                ]);
            }
            
            // Update job status if final invoice
            if ($invoice_type === 'final') {
                $stmt = $pdo->prepare("UPDATE jobs SET status = 'invoiced', completion_date = ? WHERE id = ?");
                $stmt->execute([date('Y-m-d'), $jobId]);
            }
            
            // Log activity
            logActivity($pdo, 'Generated invoice', 'customer_invoices', $invoice_id, "Invoice: $invoice_number, Amount: " . formatCurrency($total_amount));
            
            $pdo->commit();
            
            setSuccessMessage("Invoice $invoice_number generated successfully");
            redirect(APP_URL . '/modules/invoices/view_invoice.php?id=' . $invoice_id);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            setErrorMessage('Failed to generate invoice: ' . $e->getMessage());
        }
    }
}

// Get company settings for default markups
$settings = getCompanySettings($pdo);
$default_labor_markup = $settings['default_labor_markup'];
$default_isuzu_parts_markup = $settings['default_isuzu_parts_markup'];
$default_subcontract_parts_markup = $settings['default_subcontract_parts_markup'];
$default_subcontract_service_markup = $settings['default_subcontract_service_markup'];


// Fetch Eligible Labor Charges (not yet invoiced)
$stmt = $pdo->prepare("
    SELECT 'labor' as item_type, lc.id, lc.description, lc.total_amount as cost, lc.date_performed as date,
           NULL as part_number, NULL as supplier_id, NULL as subcontractor_id
    FROM labor_charges lc
    WHERE lc.job_id = ?
    AND lc.id NOT IN (
        SELECT reference_id FROM customer_invoice_items 
        WHERE item_type = 'labor' AND reference_id IS NOT NULL
    )
");
$stmt->execute([$jobId]);
$labor_items = $stmt->fetchAll();

// Fetch Eligible Quoted Parts (Approved, Installed, Not Invoiced)
// Joins quotation_items, quotations, supplier_invoices, supplier_invoice_items
$stmt = $pdo->prepare("
    SELECT 'isuzu_part' as item_type, -- Assuming all quoted parts are Isuzu parts for simplicity for now
           qi.id,
           CONCAT(qi.description, ' (Part #', qi.part_number, ')') as description,
           qi.price_excluding_vat as cost,
           q.quotation_date as date, -- Using quotation date as reference
           qi.part_number,
           q.supplier_id,
           NULL as subcontractor_id
    FROM quotation_items qi
    JOIN quotations q ON qi.quotation_id = q.id
    LEFT JOIN supplier_invoices si ON q.id = si.quotation_id
    LEFT JOIN supplier_invoice_items sii ON si.id = sii.supplier_invoice_id AND qi.part_number = sii.part_number
    WHERE q.job_id = ?
      AND q.status = 'approved'
      AND sii.installation_status = 'fully_installed'
      AND qi.id NOT IN (
          SELECT reference_id FROM customer_invoice_items
          WHERE item_type IN ('isuzu_part', 'subcontract_part') AND reference_id IS NOT NULL
      )
");
$stmt->execute([$jobId]);
$part_items = $stmt->fetchAll();


// Fetch Eligible Subcontract Works (Completed/Billed, Not Invoiced)
$stmt = $pdo->prepare("
    SELECT CASE
               WHEN sw.work_type = 'parts' THEN 'subcontract_part'
               WHEN sw.work_type = 'service' THEN 'subcontract_service'
               ELSE 'subcontract_service' -- Default to service if unknown type
           END as item_type,
           sw.id,
           CONCAT('Subcontract: ', sw.work_description, ' (', sw.work_type, ')') as description,
           sw.total_cost as cost,
           sw.completion_date as date,
           sw.part_number, -- Might be null if service
           NULL as supplier_id,
           sw.subcontractor_id
    FROM subcontract_works sw
    WHERE sw.job_id = ?
      AND (sw.status = 'completed' OR sw.status = 'billed')
      AND sw.id NOT IN (
          SELECT reference_id FROM customer_invoice_items
          WHERE item_type IN ('subcontract_part', 'subcontract_service') AND reference_id IS NOT NULL
      )
");
$stmt->execute([$jobId]);
$subcontract_items = $stmt->fetchAll();


// Merge all eligible items
$eligible_items = array_merge($labor_items, $part_items, $subcontract_items);

// Sort by date for consistent display
usort($eligible_items, function($a, $b) {
    // Handle potential null dates (e.g. if 'date' is not set for all item types consistently)
    $dateA = isset($a['date']) ? strtotime($a['date']) : 0;
    $dateB = isset($b['date']) ? strtotime($b['date']) : 0;
    return $dateB - $dateA; // Newest first
});

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-file-invoice-dollar"></i> Generate Invoice</h1>
    <a href="<?php echo APP_URL; ?>/modules/jobs/job_details.php?id=<?php echo $jobId; ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Job
    </a>
</div>

<!-- Job Info -->
<div class="card">
    <div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label>Job Number</label>
                <p><strong><?php echo e($job['job_number']); ?></strong></p>
            </div>
            <div class="form-group">
                <label>Vehicle</label>
                <p><?php echo e($job['number_plate'] . ' - ' . $job['make'] . ' ' . $job['model']); ?></p>
            </div>
            <div class="form-group">
                <label>Owner</label>
                <p><?php echo e($job['owner_name']); ?></p>
            </div>
        </div>
    </div>
</div>

<?php if (empty($eligible_items)): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>No items to invoice!</strong> All labor charges for this job have already been invoiced, or there are no labor charges yet.
    <a href="<?php echo APP_URL; ?>/modules/labor/add_labor.php?job_id=<?php echo $jobId; ?>">Add labor charges</a>
</div>
<?php else: ?>

<form method="POST" id="invoiceForm">
    <!-- Invoice Type -->
    <div class="card">
        <div class="card-header">
            <h3>Step 1: Select Invoice Type</h3>
        </div>
        <div class="card-body">
            <div style="display: flex; gap: 20px;">
                <label style="display: flex; align-items: center; gap: 10px; padding: 15px; border: 2px solid #e5e7eb; border-radius: 8px; cursor: pointer; flex: 1;">
                    <input type="radio" name="invoice_type" value="progress" required>
                    <div>
                        <strong>Progress Invoice</strong>
                        <p class="text-muted" style="margin: 5px 0 0 0; font-size: 13px;">Partial billing, more invoices can be created later</p>
                    </div>
                </label>
                <label style="display: flex; align-items: center; gap: 10px; padding: 15px; border: 2px solid #e5e7eb; border-radius: 8px; cursor: pointer; flex: 1;">
                    <input type="radio" name="invoice_type" value="final" required checked>
                    <div>
                        <strong>Final Invoice</strong>
                        <p class="text-muted" style="margin: 5px 0 0 0; font-size: 13px;">Complete billing, job will be locked</p>
                    </div>
                </label>
            </div>
        </div>
    </div>
    
    <!-- Select Items -->
    <div class="card">
        <div class="card-header">
            <h3>Step 2: Select Items to Invoice</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th width="50">
                                <input type="checkbox" id="select-all" onchange="toggleAllItems()">
                            </th>
                            <th>Description</th>
                            <th>Date</th>
                            <th>Our Cost</th>
                            <th>Markup %</th>
                            <th>Item Discount %</th>
                            <th>Price</th>
                        </tr>
                    </thead>
                    <tbody id="items-tbody">
                        <?php foreach ($eligible_items as $item):
                            $default_markup = 0;
                            if ($item['item_type'] === 'labor') {
                                $default_markup = $default_labor_markup;
                            } elseif ($item['item_type'] === 'isuzu_part') {
                                $default_markup = $default_isuzu_parts_markup;
                            } elseif ($item['item_type'] === 'subcontract_part') {
                                $default_markup = $default_subcontract_parts_markup;
                            } elseif ($item['item_type'] === 'subcontract_service') {
                                $default_markup = $default_subcontract_service_markup;
                            }
                        ?>
                        <tr class="item-row" data-item-id="<?php echo $item['id']; ?>" data-item-type="<?php echo $item['item_type']; ?>">
                            <td>
                                <input type="checkbox" name="selected_items[]" value="<?php echo $item['item_type'] . '_' . $item['id']; ?>" class="item-checkbox" onchange="calculateTotals()" checked>
                            </td>
                            <td>
                                <?php echo e($item['description']); ?>
                                <?php if ($item['item_type'] === 'labor' && isset($item['performed_by'])): ?>
                                    <br><small class="text-muted">By: <?php echo e($item['performed_by']); ?></small>
                                <?php endif; ?>
                                <input type="hidden" name="item_types[<?php echo $item['item_type'] . '_' . $item['id']; ?>]" value="<?php echo $item['item_type']; ?>">
                                <input type="hidden" name="reference_ids[<?php echo $item['item_type'] . '_' . $item['id']; ?>]" value="<?php echo $item['id']; ?>">
                            </td>
                            <td><?php echo formatDate($item['date']); ?></td>
                            <td class="item-cost" data-raw-cost="<?php echo $item['cost']; ?>"><?php echo formatCurrency($item['cost']); ?></td>
                            <td>
                                <input type="number" name="markup[<?php echo $item['item_type'] . '_' . $item['id']; ?>]" class="form-control form-control-sm markup-input" 
                                       value="<?php echo $default_markup; ?>" min="0" max="100" step="0.1" style="width: 80px;" onchange="calculateTotals()">
                            </td>
                            <td>
                                <input type="number" name="item_discount[<?php echo $item['item_type'] . '_' . $item['id']; ?>]" class="form-control form-control-sm discount-input" 
                                       value="0" min="0" max="100" step="0.1" style="width: 80px;" onchange="calculateTotals()">
                            </td>
                            <td class="item-price">-</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Pricing Summary -->
    <div class="card">
        <div class="card-header">
            <h3>Step 3: Review & Apply Overall Discount</h3>
        </div>
        <div class="card-body">
            <div style="max-width: 500px; margin: 0 0 0 auto;">
                <table style="width: 100%; font-size: 14px;">
                    <tr>
                        <td style="padding: 8px 0;">Subtotal (before overall discount):</td>
                        <td style="padding: 8px 0; text-align: right;"><strong id="subtotal-before">KES 0.00</strong></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0;">
                            Overall Discount:
                            <input type="number" name="overall_discount" id="overall-discount-input" 
                                   value="0" min="0" max="100" step="0.1" 
                                   style="width: 70px; margin-left: 10px;" onchange="calculateTotals()"> %
                        </td>
                        <td style="padding: 8px 0; text-align: right; color: #ef4444;">
                            <span id="overall-discount-amount">KES 0.00</span>
                        </td>
                    </tr>
                    <tr style="border-top: 1px solid #e5e7eb;">
                        <td style="padding: 8px 0;">Subtotal (after discount):</td>
                        <td style="padding: 8px 0; text-align: right;"><strong id="subtotal-after">KES 0.00</strong></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0;">VAT (<?php echo $settings['vat_rate']; ?>%):</td>
                        <td style="padding: 8px 0; text-align: right;" id="vat-amount">KES 0.00</td>
                    </tr>
                    <tr style="border-top: 2px solid #1f2937; font-size: 18px; font-weight: bold;">
                        <td style="padding: 12px 0;">TOTAL AMOUNT:</td>
                        <td style="padding: 12px 0; text-align: right; color: #667eea;" id="total-amount">KES 0.00</td>
                    </tr>
                    <?php if (isDirector()): ?>
                    <tr style="background: #f9fafb; border-top: 1px solid #e5e7eb;">
                        <td style="padding: 8px; color: #059669;">Total Cost:</td>
                        <td style="padding: 8px; text-align: right;" id="total-cost">KES 0.00</td>
                    </tr>
                    <tr style="background: #f9fafb;">
                        <td style="padding: 8px; color: #059669;"><strong>Profit:</strong></td>
                        <td style="padding: 8px; text-align: right; color: #059669;"><strong id="total-profit">KES 0.00</strong></td>
                    </tr>
                    <tr style="background: #f9fafb;">
                        <td style="padding: 8px; color: #059669;">Margin:</td>
                        <td style="padding: 8px; text-align: right; color: #059669;" id="profit-margin">0%</td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-footer">
            <a href="<?php echo APP_URL; ?>/modules/jobs/job_details.php?id=<?php echo $jobId; ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" name="generate_invoice" class="btn btn-success btn-lg">
                <i class="fas fa-check-circle"></i> Generate Invoice
            </button>
        </div>
    </div>
</form>

<script>
const vatRate = <?php echo $settings['vat_rate']; ?>;

function toggleAllItems() {
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
    calculateTotals();
}

function calculateTotals() {
    let totalCost = 0; // Sum of our actual costs for selected items
    let subtotalBefore = 0; // Sum of customer prices for selected items before overall discount
    
    document.querySelectorAll('.item-row').forEach(row => {
        const checkbox = row.querySelector('.item-checkbox');
        
        if (checkbox.checked) {
            const cost = parseFloat(row.querySelector('.item-cost').dataset.rawCost) || 0; // Get raw cost
            const markup = parseFloat(row.querySelector('.markup-input').value) || 0;
            const discount = parseFloat(row.querySelector('.discount-input').value) || 0;
            
            const priceBeforeDiscount = cost * (1 + markup / 100);
            const discountAmount = priceBeforeDiscount * (discount / 100);
            const priceAfterDiscount = priceBeforeDiscount - discountAmount;
            
            row.querySelector('.item-price').textContent = formatCurrency(priceAfterDiscount);
            
            totalCost += cost;
            subtotalBefore += priceAfterDiscount;
        } else {
            row.querySelector('.item-price').textContent = '-';
        }
    });
    
    // Overall discount
    const overallDiscount = parseFloat(document.getElementById('overall-discount-input').value) || 0;
    const overallDiscountAmount = subtotalBefore * (overallDiscount / 100);
    const subtotalAfter = subtotalBefore - overallDiscountAmount;
    
    // VAT
    const vatAmount = subtotalAfter * (vatRate / 100);
    const totalAmount = subtotalAfter + vatAmount;
    
    // Profit
    const profit = totalAmount - totalCost;
    const margin = totalAmount > 0 ? (profit / totalAmount) * 100 : 0;
    
    // Update display
    document.getElementById('subtotal-before').textContent = formatCurrency(subtotalBefore);
    document.getElementById('overall-discount-amount').textContent = '- ' + formatCurrency(overallDiscountAmount);
    document.getElementById('subtotal-after').textContent = formatCurrency(subtotalAfter);
    document.getElementById('vat-amount').textContent = formatCurrency(vatAmount);
    document.getElementById('total-amount').textContent = formatCurrency(totalAmount);
    
    <?php if (isDirector()): ?>
    document.getElementById('total-cost').textContent = formatCurrency(totalCost);
    document.getElementById('total-profit').textContent = formatCurrency(profit);
    document.getElementById('profit-margin').textContent = margin.toFixed(1) + '%';
    <?php endif; ?>
}

// Initialize
document.getElementById('select-all').checked = true;
calculateTotals();
</script>

<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
