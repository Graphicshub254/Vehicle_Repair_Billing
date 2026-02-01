<?php
require_once '../../config/config.php';
requireLogin();

$vehicleId = intval($_GET['vehicle_id'] ?? 0); // Changed from job_id

// Load vehicle
$stmt = $pdo->prepare("
    SELECT v.*, u.full_name as owner_name, u.email as owner_email, u.phone as owner_phone
    FROM vehicles v
    LEFT JOIN users u ON v.owner_id = u.id -- Assuming owner_id in vehicles table or can be derived
    WHERE v.id = ?
");
$stmt->execute([$vehicleId]);
$vehicle = $stmt->fetch();

if (!$vehicle) {
    setErrorMessage('Vehicle not found');
    redirect(APP_URL . '/modules/vehicles/vehicles.php'); // Redirect to vehicles list
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_invoice'])) {
    $invoice_type = $_POST['invoice_type'] ?? 'final';
    $selected_labor = $_POST['selected_labor'] ?? [];
    $selected_parts = $_POST['selected_parts'] ?? [];
    $selected_subcontracts = $_POST['selected_subcontracts'] ?? [];
    
    $labor_markups = $_POST['labor_markup'] ?? [];
    $parts_markups = $_POST['parts_markup'] ?? [];
    $subcontract_markups = $_POST['subcontract_markup'] ?? [];
    
    $overall_discount = floatval($_POST['overall_discount'] ?? 0);
    
    if (empty($selected_labor) && empty($selected_parts) && empty($selected_subcontracts)) {
        setErrorMessage('Please select at least one item to invoice');
    } else {
        try {
            $pdo->beginTransaction();
            
            // Generate invoice number
            $invoice_number = generateInvoiceNumber($pdo);
            $invoice_date = date('Y-m-d');
            
            // Get settings
            $settings = getCompanySettings($pdo);
            $vat_rate = $settings['vat_rate'];
            
            // Calculate totals
            $labor_cost = 0;
            $parts_cost = 0;
            $subcontract_cost = 0;
            $subtotal_before_discount = 0;
            $items_data = [];
            $item_no = 1;
            
            // Process labor items
            foreach ($selected_labor as $labor_id) {
                $stmt = $pdo->prepare("SELECT lc.*, j.job_number FROM labor_charges lc JOIN jobs j ON lc.job_id = j.id WHERE lc.id = ?");
                $stmt->execute([$labor_id]);
                $labor = $stmt->fetch();
                
                if (!$labor) continue;
                
                $unit_cost = $labor['total_amount'];
                $markup_pct = 0; // Labor charges should not have markup (as per existing logic)
                $unit_price = $unit_cost * (1 + $markup_pct / 100);
                
                $items_data[] = [
                    'type' => 'labor',
                    'reference_id' => $labor_id,
                    'job_id' => $labor['job_id'], // Store job_id for later checks
                    'item_no' => $item_no++,
                    'description' => $labor['description'] . ' (Job: ' . $labor['job_number'] . ')',
                    'quantity' => 1,
                    'unit_cost' => $unit_cost,
                    'markup_pct' => $markup_pct,
                    'unit_price' => $unit_price
                ];
                
                $labor_cost += $unit_cost;
                $subtotal_before_discount += $unit_price;
            }
            
            // Process Isuzu parts
            foreach ($selected_parts as $part_id) {
                $stmt = $pdo->prepare("
                    SELECT sii.*, si.invoice_number, q.quotation_number, q.job_id, j.job_number
                    FROM supplier_invoice_items sii
                    JOIN supplier_invoices si ON sii.supplier_invoice_id = si.id
                    JOIN quotations q ON si.quotation_id = q.id
                    JOIN jobs j ON q.job_id = j.id
                    WHERE sii.id = ? AND sii.installation_status = 'fully_installed'
                ");
                $stmt->execute([$part_id]);
                $part = $stmt->fetch();
                
                if (!$part) continue;
                
                $unit_cost = $part['net_value'];
                $quantity = $part['quantity_received'];
                $total_cost = $unit_cost * $quantity;
                
                $markup_pct = floatval($parts_markups[$part_id] ?? 20);
                $unit_price = $unit_cost * (1 + $markup_pct / 100);
                
                $items_data[] = [
                    'type' => 'isuzu_part', // Changed from 'parts' for clarity
                    'reference_id' => $part_id,
                    'job_id' => $part['job_id'], // Store job_id
                    'item_no' => $item_no++,
                    'description' => $part['description'] . ' (Part#: ' . $part['part_number'] . ' - Job: ' . $part['job_number'] . ')',
                    'quantity' => $quantity,
                    'unit_cost' => $unit_cost,
                    'markup_pct' => $markup_pct,
                    'unit_price' => $unit_price
                ];
                
                $parts_cost += $total_cost;
                $subtotal_before_discount += ($unit_price * $quantity);
            }
            
            // Process subcontracts
            foreach ($selected_subcontracts as $work_id) {
                $stmt = $pdo->prepare("
                    SELECT sw.*, sub.name as subcontractor_name, j.job_number, j.id as job_id
                    FROM subcontract_works sw
                    JOIN subcontractors sub ON sw.subcontractor_id = sub.id
                    JOIN jobs j ON sw.job_id = j.id
                    WHERE sw.id = ? AND sw.status = 'completed'
                ");
                $stmt->execute([$work_id]);
                $work = $stmt->fetch();
                
                if (!$work) continue;
                
                $unit_cost = $work['total_cost'];
                $markup_pct = floatval($subcontract_markups[$work_id] ?? 15);
                $unit_price = $unit_cost * (1 + $markup_pct / 100);
                
                $description = $work['work_description'];
                if ($work['work_type'] === 'parts' && $work['part_number']) {
                    $description .= ' (Part#: ' . $work['part_number'] . ')';
                }
                $description .= ' - via ' . $work['subcontractor_name'] . ' (Job: ' . $work['job_number'] . ')';
                
                $items_data[] = [
                    'type' => ($work['work_type'] === 'parts' ? 'subcontract_part' : 'subcontract_service'), // More specific types
                    'reference_id' => $work_id,
                    'job_id' => $work['job_id'], // Store job_id
                    'item_no' => $item_no++,
                    'description' => $description,
                    'quantity' => 1,
                    'unit_cost' => $unit_cost,
                    'markup_pct' => $markup_pct,
                    'unit_price' => $unit_price
                ];
                
                $subcontract_cost += $unit_cost;
                $subtotal_before_discount += $unit_price;
            }
            
            $total_cost = $labor_cost + $parts_cost + $subcontract_cost;
            
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
            
            // Calculate component totals
            $labor_total = 0;
            $parts_total = 0;
            $subcontract_total = 0;
            
            foreach ($items_data as $item) {
                $item_price = $item['unit_price'] * $item['quantity'];
                if ($item['type'] === 'labor') $labor_total += $item_price;
                if ($item['type'] === 'isuzu_part' || $item['type'] === 'subcontract_part') $parts_total += $item_price;
                if ($item['type'] === 'subcontract_service') $subcontract_total += $item_price;
            }
            
            // Create invoice (using vehicle_id now)
            $stmt = $pdo->prepare("
                INSERT INTO customer_invoices (
                    invoice_number, vehicle_id, invoice_date, invoice_type,
                    labor_cost, parts_cost, subcontract_cost, total_cost,
                    labor_total, parts_total, subcontract_total,
                    subtotal_before_discount, overall_discount_percentage, total_discount,
                    subtotal_after_discount, vat_percentage, vat_amount, total_amount,
                    total_profit, profit_percentage, generated_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $invoice_number, $vehicleId, $invoice_date, $invoice_type,
                $labor_cost, $parts_cost, $subcontract_cost, $total_cost,
                $labor_total, $parts_total, $subcontract_total,
                $subtotal_before_discount, $overall_discount, $overall_discount_amount,
                $subtotal_after_discount, $vat_rate, $vat_amount, $total_amount,
                $total_profit, $profit_percentage, $_SESSION['user_id']
            ]);
            
            $invoice_id = $pdo->lastInsertId();
            
            // Create invoice items
            foreach ($items_data as $item) {
                $item_subtotal = $item['unit_price'] * $item['quantity'];
                $item_subtotal_after_discount = $item_subtotal * (1 - $overall_discount / 100);
                $item_vat = $item_subtotal_after_discount * ($vat_rate / 100);
                $item_total = $item_subtotal_after_discount + $item_vat;
                $item_cost_total = $item['unit_cost'] * $item['quantity'];
                $item_profit = $item_total - $item_cost_total;
                
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
                    $invoice_id, $item['type'], $item['reference_id'], $item['item_no'],
                    $item['description'], $item['quantity'], $item['unit_cost'], 
                    $item['unit_cost'] * $item['quantity'],
                    $item['markup_pct'], $item['unit_price'],
                    0, 0, $item['unit_price'],
                    $item_subtotal_after_discount, $item_vat, $item_total, $item_profit
                ]);
            }
            
            // Update job status if final invoice (only for jobs with *all* items invoiced)
            // This logic is now more complex as an invoice can span multiple jobs.
            // We need to identify which jobs had items invoiced in this customer invoice.
            $jobs_with_invoiced_items = array_unique(array_column($items_data, 'job_id'));
            foreach($jobs_with_invoiced_items as $job_to_check_id) {
                // Here, you'd ideally call the same completion check logic as in job_details.php
                // to see if this job can now be marked 'invoiced' or 'completed'.
                // For simplicity here, we'll mark affected jobs as 'invoiced' if it's a final invoice
                // A more robust solution would re-run the `canComplete` check.
                 if ($invoice_type === 'final') {
                    $stmt = $pdo->prepare("UPDATE jobs SET status = 'invoiced', completion_date = ? WHERE id = ?");
                    $stmt->execute([date('Y-m-d'), $job_to_check_id]);
                }
            }
            
            // Log activity
            logActivity($pdo, 'Generated comprehensive invoice', 'customer_invoices', $invoice_id, "Invoice: $invoice_number, Amount: " . formatCurrency($total_amount));
            
            $pdo->commit();
            
            setSuccessMessage("Invoice $invoice_number generated successfully");
            redirect(APP_URL . '/modules/invoices/view_invoice.php?id=' . $invoice_id);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            setErrorMessage('Failed to generate invoice: ' . $e->getMessage());
        }
    }
}

// Get all eligible items for the vehicle across all its jobs
// eligible labor charges
$stmt = $pdo->prepare("
    SELECT lc.*, j.job_number, j.id as job_id
    FROM labor_charges lc
    JOIN jobs j ON lc.job_id = j.id
    WHERE j.vehicle_id = ?
    AND lc.id NOT IN (
        SELECT reference_id FROM customer_invoice_items 
        WHERE item_type = 'labor' AND reference_id IS NOT NULL
    )
    ORDER BY j.job_number ASC, lc.date_performed DESC
");
$stmt->execute([$vehicleId]);
$labor_items_by_job = [];
foreach ($stmt->fetchAll() as $item) {
    $labor_items_by_job[$item['job_id']][] = $item;
}

// Get eligible Isuzu parts (fully installed)
$stmt = $pdo->prepare("
    SELECT sii.*, si.invoice_number, q.quotation_number, q.job_id, j.job_number
    FROM supplier_invoice_items sii
    JOIN supplier_invoices si ON sii.supplier_invoice_id = si.id
    JOIN quotations q ON si.quotation_id = q.id
    JOIN jobs j ON q.job_id = j.id
    WHERE j.vehicle_id = ?
    AND sii.installation_status = 'fully_installed'
    AND sii.id NOT IN (
        SELECT reference_id FROM customer_invoice_items 
        WHERE item_type = 'isuzu_part' AND reference_id IS NOT NULL
    )
    ORDER BY j.job_number ASC, sii.installed_date DESC
");
$stmt->execute([$vehicleId]);
$parts_items_by_job = [];
foreach ($stmt->fetchAll() as $item) {
    $parts_items_by_job[$item['job_id']][] = $item;
}

// Get eligible subcontracts (completed)
$stmt = $pdo->prepare("
    SELECT sw.*, sub.name as subcontractor_name, j.job_number, j.id as job_id
    FROM subcontract_works sw
    JOIN subcontractors sub ON sw.subcontractor_id = sub.id
    JOIN jobs j ON sw.job_id = j.id
    WHERE j.vehicle_id = ?
    AND sw.status = 'completed'
    AND sw.id NOT IN (
        SELECT reference_id FROM customer_invoice_items 
        WHERE item_type IN ('subcontract_part', 'subcontract_service') AND reference_id IS NOT NULL
    )
    ORDER BY j.job_number ASC, sw.completion_date DESC
");
$stmt->execute([$vehicleId]);
$subcontract_items_by_job = [];
foreach ($stmt->fetchAll() as $item) {
    $subcontract_items_by_job[$item['job_id']][] = $item;
}

$settings = getCompanySettings($pdo);

$pageTitle = 'Generate Comprehensive Invoice for ' . $vehicle['number_plate'];
$breadcrumbs = [
    ['text' => 'Vehicles', 'url' => APP_URL . '/modules/vehicles/vehicles.php'],
    ['text' => $vehicle['number_plate'], 'url' => APP_URL . '/modules/vehicles/vehicle_history.php?id=' . $vehicleId],
    ['text' => 'Generate Invoice']
];

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-file-invoice-dollar"></i> Generate Comprehensive Invoice</h1>
    <a href="<?php echo APP_URL; ?>/modules/vehicles/vehicle_history.php?id=<?php echo $vehicleId; ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Vehicle History
    </a>
</div>

<!-- Vehicle Info -->
<div class="card">
    <div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label>Vehicle Number Plate</label>
                <p><strong><?php echo e($vehicle['number_plate']); ?></strong></p>
            </div>
            <div class="form-group">
                <label>Make & Model</label>
                <p><?php echo e($vehicle['make'] . ' ' . $vehicle['model']); ?></p>
            </div>
            <div class="form-group">
                <label>Owner</label>
                <p><?php echo e($vehicle['owner_name']); ?></p>
            </div>
        </div>
    </div>
</div>

<?php if (empty($labor_items_by_job) && empty($parts_items_by_job) && empty($subcontract_items_by_job)): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>No items to invoice for this vehicle!</strong> All eligible items across all jobs have been invoiced, or there are no completed items yet.
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
                        <p class="text-muted" style="margin: 5px 0 0 0; font-size: 13px;">Partial billing for work completed so far</p>
                    </div>
                </label>
                <label style="display: flex; align-items: center; gap: 10px; padding: 15px; border: 2px solid #e5e7eb; border-radius: 8px; cursor: pointer; flex: 1;">
                    <input type="radio" name="invoice_type" value="final" required checked>
                    <div>
                        <strong>Final Invoice</strong>
                        <p class="text-muted" style="margin: 5px 0 0 0; font-size: 13px;">Complete billing, items selected will be marked invoiced</p>
                    </div>
                </label>
            </div>
        </div>
    </div>
    
    <!-- Labor Items -->
    <?php if (!empty($labor_items_by_job)): ?>
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-users-cog"></i> Labor Charges</h3>
        </div>
        <div class="card-body">
            <?php foreach ($labor_items_by_job as $job_id => $labor_items): ?>
            <h4>Job: <?php echo e($labor_items[0]['job_number']); ?></h4>
            <table class="data-table">
                <thead>
                    <tr>
                        <th width="50"><input type="checkbox" id="select-all-labor-job-<?php echo $job_id; ?>" onchange="toggleAll('labor', <?php echo $job_id; ?>)"></th>
                        <th>Description</th>
                        <th>Date</th>
                        <th>Our Cost</th>
                        <th>Markup (N/A)</th>
                        <th>Selling Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($labor_items as $item): ?>
                    <tr>
                        <td><input type="checkbox" name="selected_labor[]" value="<?php echo $item['id']; ?>" class="labor-checkbox labor-job-<?php echo $job_id; ?>" onchange="calculateTotals()" checked></td>
                        <td><?php echo e($item['description']); ?></td>
                        <td><?php echo formatDate($item['date_performed']); ?></td>
                        <td><?php echo formatCurrency($item['total_amount']); ?></td>
                        <td>
                            <input type="number" name="labor_markup[<?php echo $item['id']; ?>]" class="form-control form-control-sm" 
                                   value="0" min="0" max="100" step="0.1" style="width: 80px;" readonly>
                        </td>
                        <td class="labor-price-<?php echo $item['id']; ?>">-</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Isuzu Parts -->
    <?php if (!empty($parts_items_by_job)): ?>
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-cogs"></i> Isuzu Parts (Installed)</h3>
        </div>
        <div class="card-body">
            <?php foreach ($parts_items_by_job as $job_id => $parts_items): ?>
            <h4>Job: <?php echo e($parts_items[0]['job_number']); ?></h4>
            <table class="data-table">
                <thead>
                    <tr>
                        <th width="50"><input type="checkbox" id="select-all-parts-job-<?php echo $job_id; ?>" onchange="toggleAll('parts', <?php echo $job_id; ?>)"></th>
                        <th>Part Number</th>
                        <th>Description</th>
                        <th>Qty</th>
                        <th>Our Cost/Unit</th>
                        <th>Markup %</th>
                        <th>Selling Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parts_items as $item): ?>
                    <tr>
                        <td><input type="checkbox" name="selected_parts[]" value="<?php echo $item['id']; ?>" class="parts-checkbox parts-job-<?php echo $job_id; ?>" onchange="calculateTotals()" checked></td>
                        <td><?php echo e($item['part_number']); ?></td>
                        <td><?php echo e($item['description']); ?></td>
                        <td><?php echo $item['quantity_received']; ?></td>
                        <td><?php echo formatCurrency($item['net_value']); ?></td>
                        <td>
                            <input type="number" name="parts_markup[<?php echo $item['id']; ?>]" class="form-control form-control-sm" 
                                   value="20" min="0" max="100" step="0.1" style="width: 80px;" onchange="calculateTotals()">
                        </td>
                        <td class="parts-price-<?php echo $item['id']; ?>">-</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Subcontracts -->
    <?php if (!empty($subcontract_items_by_job)): ?>
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-users"></i> Subcontract Work (Completed)</h3>
        </div>
        <div class="card-body">
            <?php foreach ($subcontract_items_by_job as $job_id => $subcontract_items): ?>
            <h4>Job: <?php echo e($subcontract_items[0]['job_number']); ?></h4>
            <table class="data-table">
                <thead>
                    <tr>
                        <th width="50"><input type="checkbox" id="select-all-subcontracts-job-<?php echo $job_id; ?>" onchange="toggleAll('subcontracts', <?php echo $job_id; ?>)"></th>
                        <th>Vendor</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Our Cost</th>
                        <th>Markup %</th>
                        <th>Selling Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subcontract_items as $item): ?>
                    <tr>
                        <td><input type="checkbox" name="selected_subcontracts[]" value="<?php echo $item['id']; ?>" class="subcontracts-checkbox subcontract-job-<?php echo $job_id; ?>" onchange="calculateTotals()" checked></td>
                        <td><?php echo e($item['subcontractor_name']); ?></td>
                        <td>
                            <?php if ($item['work_type'] === 'parts'): ?>
                                <span class="badge badge-info">Parts</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Service</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e(substr($item['work_description'], 0, 50)) . '...'; ?></td>
                        <td><?php echo formatCurrency($item['total_cost']); ?></td>
                        <td>
                            <input type="number" name="subcontract_markup[<?php echo $item['id']; ?>]" class="form-control form-control-sm" 
                                   value="15" min="0" max="100" step="0.1" style="width: 80px;" onchange="calculateTotals()">
                        </td>
                        <td class="subcontract-price-<?php echo $item['id']; ?>">-</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Pricing Summary -->
    <div class="card">
        <div class="card-header">
            <h3>Step 2: Review Totals & Apply Discount</h3>
        </div>
        <div class="card-body">
            <div style="max-width: 600px; margin: 0 0 0 auto;">
                <table style="width: 100%; font-size: 14px;">
                    <tr>
                        <td style="padding: 8px 0;">Labor Subtotal:</td>
                        <td style="padding: 8px 0; text-align: right;" id="labor-subtotal">KES 0.00</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0;">Parts Subtotal:</td>
                        <td style="padding: 8px 0; text-align: right;" id="parts-subtotal">KES 0.00</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0;">Subcontracts Subtotal:</td>
                        <td style="padding: 8px 0; text-align: right;" id="subcontracts-subtotal">KES 0.00</td>
                    </tr>
                    <tr style="border-top: 1px solid #e5e7eb;">
                        <td style="padding: 8px 0;"><strong>Subtotal (before discount):</strong></td>
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
            <a href="<?php echo APP_URL; ?>/modules/vehicles/vehicle_history.php?id=<?php echo $vehicleId; ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" name="generate_invoice" class="btn btn-success btn-lg">
                <i class="fas fa-check-circle"></i> Generate Comprehensive Invoice
            </button>
        </div>
    </div>
</form>

<script>
const vatRate = <?php echo $settings['vat_rate']; ?>;
// Adjusted to include job_id for grouping in PHP
const laborItemsAll = <?php echo json_encode($labor_items_by_job); ?>;
const partsItemsAll = <?php echo json_encode($parts_items_by_job); ?>;
const subcontractItemsAll = <?php echo json_encode($subcontract_items_by_job); ?>;

function toggleAll(type, jobId) {
    const checkbox = document.getElementById(`select-all-${type}-job-${jobId}`);
    // Select all checkboxes for the specific type AND job
    const checkboxes = document.querySelectorAll(`.${type}-checkbox.${type}-job-${jobId}`);
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
    calculateTotals();
}

function calculateTotals() {
    let laborSubtotal = 0;
    let partsSubtotal = 0;
    let subcontractsSubtotal = 0;
    let totalCost = 0;
    
    // Calculate labor
    Object.values(laborItemsAll).forEach(jobItems => {
        jobItems.forEach(item => {
            const checkbox = document.querySelector(`input[name="selected_labor[]"][value="${item.id}"]`);
            if (checkbox && checkbox.checked) {
                const cost = parseFloat(item.total_amount);
                const markup = 0; // Labor charges should not have markup
                const price = cost * (1 + markup / 100);
                
                const priceElement = document.querySelector(`.labor-price-${item.id}`);
                if(priceElement) priceElement.textContent = formatCurrency(price);
                
                laborSubtotal += price;
                totalCost += cost;
            }
        });
    });
    
    // Calculate parts
    Object.values(partsItemsAll).forEach(jobItems => {
        jobItems.forEach(item => {
            const checkbox = document.querySelector(`input[name="selected_parts[]"][value="${item.id}"]`);
            if (checkbox && checkbox.checked) {
                const cost = parseFloat(item.net_value) * parseInt(item.quantity_received);
                const markupInput = document.querySelector(`input[name="parts_markup[${item.id}]"]`);
                const markup = parseFloat(markupInput ? markupInput.value : 0) || 0;
                const unitPrice = parseFloat(item.net_value) * (1 + markup / 100);
                const price = unitPrice * parseInt(item.quantity_received);
                
                const priceElement = document.querySelector(`.parts-price-${item.id}`);
                if(priceElement) priceElement.textContent = formatCurrency(price);
                
                partsSubtotal += price;
                totalCost += cost;
            }
        });
    });
    
    // Calculate subcontracts
    Object.values(subcontractItemsAll).forEach(jobItems => {
        jobItems.forEach(item => {
            const checkbox = document.querySelector(`input[name="selected_subcontracts[]"][value="${item.id}"]`);
            if (checkbox && checkbox.checked) {
                const cost = parseFloat(item.total_cost);
                const markupInput = document.querySelector(`input[name="subcontract_markup[${item.id}]"]`);
                const markup = parseFloat(markupInput ? markupInput.value : 0) || 0;
                const price = cost * (1 + markup / 100);
                
                const priceElement = document.querySelector(`.subcontract-price-${item.id}`);
                if(priceElement) priceElement.textContent = formatCurrency(price);
                
                subcontractsSubtotal += price;
                totalCost += cost;
            }
        });
    });
    
    const subtotalBefore = laborSubtotal + partsSubtotal + subcontractsSubtotal;
    const overallDiscount = parseFloat(document.getElementById('overall-discount-input').value) || 0;
    const overallDiscountAmount = subtotalBefore * (overallDiscount / 100);
    const subtotalAfter = subtotalBefore - overallDiscountAmount;
    const vatAmount = subtotalAfter * (vatRate / 100);
    const totalAmount = subtotalAfter + vatAmount;
    
    const profit = totalAmount - totalCost;
    const margin = totalAmount > 0 ? (profit / totalAmount) * 100 : 0;
    
    // Update display
    document.getElementById('labor-subtotal').textContent = formatCurrency(laborSubtotal);
    document.getElementById('parts-subtotal').textContent = formatCurrency(partsSubtotal);
    document.getElementById('subcontracts-subtotal').textContent = formatCurrency(subcontractsSubtotal);
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

// Initial calculation
calculateTotals();
</script>

<?php endif; ?>

<?php include '../../includes/footer.php'; ?>