<?php
require_once '../../config/config.php';
requireLogin();

// Only procurement officers and directors
if (!isProcurementOfficer() && !isDirector()) {
    setErrorMessage('You do not have permission to enter supplier invoices');
    redirect(APP_URL . '/modules/dashboard/dashboard.php');
}

$quotationId = intval($_GET['quotation_id'] ?? 0);

// Load quotation
$stmt = $pdo->prepare("
    SELECT q.*, j.job_number, v.number_plate, s.name as supplier_name
    FROM quotations q
    JOIN jobs j ON q.job_id = j.id
    JOIN vehicles v ON j.vehicle_id = v.id
    JOIN suppliers s ON q.supplier_id = s.id
    WHERE q.id = ? AND q.status = 'approved'
");
$stmt->execute([$quotationId]);
$quotation = $stmt->fetch();

if (!$quotation) {
    setErrorMessage('Quotation not found or not approved');
    redirect(APP_URL . '/modules/quotations/quotations.php');
}

// Check if invoice already entered
$stmt = $pdo->prepare("SELECT id FROM supplier_invoices WHERE quotation_id = ?");
$stmt->execute([$quotationId]);
if ($stmt->fetch()) {
    setErrorMessage('Supplier invoice has already been entered for this quotation');
    redirect(APP_URL . '/modules/quotations/view_quotation.php?id=' . $quotationId);
}

$pageTitle = 'Enter Supplier Invoice';
$breadcrumbs = [
    ['text' => 'Quotations', 'url' => APP_URL . '/modules/quotations/quotations.php'],
    ['text' => $quotation['quotation_number'], 'url' => APP_URL . '/modules/quotations/view_quotation.php?id=' . $quotationId],
    ['text' => 'Enter Invoice']
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $invoice_date = $_POST['invoice_date'] ?? '';
    $received_date = $_POST['received_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    
    // Get items
    $item_count = intval($_POST['item_count'] ?? 0);
    $items = [];
    
    for ($i = 1; $i <= $item_count; $i++) {
        if (isset($_POST["item_no_$i"])) {
            $items[] = [
                'item_no' => intval($_POST["item_no_$i"]),
                'part_number' => trim($_POST["part_number_$i"] ?? ''),
                'description' => trim($_POST["description_$i"] ?? ''),
                'quantity' => intval($_POST["quantity_$i"] ?? 0),
                'price_unit' => floatval($_POST["price_unit_$i"] ?? 0),
                'trade_disc_pct' => floatval($_POST["trade_disc_pct_$i"] ?? 0),
                'trade_disc_value' => floatval($_POST["trade_disc_value_$i"] ?? 0),
                'net_value' => floatval($_POST["net_value_$i"] ?? 0)
            ];
        }
    }
    
    if (empty($invoice_number) || empty($invoice_date) || empty($items)) {
        setErrorMessage('Invoice number, date, and at least one item are required');
    } else {
        try {
            $pdo->beginTransaction();
            
            // Calculate invoice totals
            $total_net_value = 0;
            
            foreach ($items as $item) {
                $item_total = $item['net_value'] * $item['quantity'];
                $total_net_value += $item_total;
            }
            
            $total_output_tax = $total_net_value * 0.16; // Calculate as 16% of Total Net Value
            $final_amount = $total_net_value + $total_output_tax;
            
            // Create supplier invoice
            $stmt = $pdo->prepare("
                INSERT INTO supplier_invoices (
                    invoice_number, quotation_id, supplier_id, invoice_date, received_date,
                    total_net_value, total_output_tax, final_amount, status, notes,
                    received_by, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'received', ?, ?, ?)
            ");
            
            $stmt->execute([
                $invoice_number, $quotationId, $quotation['supplier_id'],
                $invoice_date, $received_date,
                $total_net_value, $total_output_tax, $final_amount,
                $notes, $_SESSION['user_id'], $_SESSION['user_id']
            ]);
            
            $invoice_id = $pdo->lastInsertId();
            
            // Create invoice items
            foreach ($items as $item) {
                $item_total = $item['net_value'] * $item['quantity'];
                
                $distributed_tax = 0;
                if ($total_net_value > 0) {
                    $proportion = $item_total / $total_net_value;
                    $distributed_tax = $proportion * $total_output_tax;
                }
                
                $final_amount_item = $item_total + $distributed_tax;
                
                $stmt = $pdo->prepare("
                    INSERT INTO supplier_invoice_items (
                        supplier_invoice_id, item_no, part_number, description,
                        quantity, price_unit, trade_disc_percentage, trade_disc_value,
                        net_value, item_total, output_tax_16, final_amount,
                        quantity_received, installation_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                
                $stmt->execute([
                    $invoice_id, $item['item_no'], $item['part_number'], $item['description'],
                    $item['quantity'], $item['price_unit'], $item['trade_disc_pct'], $item['trade_disc_value'],
                    $item['net_value'], $item_total, $distributed_tax, $final_amount_item,
                    $item['quantity'] // Mark as received
                ]);
            }
            
            // Update quotation status
            $stmt = $pdo->prepare("UPDATE quotations SET status = 'ordered' WHERE id = ?");
            $stmt->execute([$quotationId]);
            
            // Update job status
            $stmt = $pdo->prepare("
                UPDATE jobs j
                JOIN quotations q ON j.id = q.job_id
                SET j.status = 'awaiting_parts'
                WHERE q.id = ?
            ");
            $stmt->execute([$quotationId]);
            
            // Log activity
            logActivity($pdo, 'Entered supplier invoice', 'supplier_invoices', $invoice_id, "Invoice: $invoice_number");
            
            $pdo->commit();
            
            setSuccessMessage("Supplier invoice $invoice_number entered successfully");
            redirect(APP_URL . '/modules/quotations/view_quotation.php?id=' . $quotationId);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            setErrorMessage('Failed to enter invoice: ' . $e->getMessage());
        }
    }
}

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-file-invoice"></i> Enter Supplier Invoice</h1>
    <a href="<?php echo APP_URL; ?>/modules/quotations/view_quotation.php?id=<?php echo $quotationId; ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Quotation
    </a>
</div>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    <strong>Instructions:</strong> Enter the data exactly as it appears on the physical invoice from <?php echo e($quotation['supplier_name']); ?>. 
    All fields should be manually entered to match the supplier's invoice.
</div>

<!-- Quotation Info -->
<div class="card">
    <div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label>Quotation</label>
                <p><strong><?php echo e($quotation['quotation_number']); ?></strong></p>
            </div>
            <div class="form-group">
                <label>Job</label>
                <p><?php echo e($quotation['job_number']); ?></p>
            </div>
            <div class="form-group">
                <label>Vehicle</label>
                <p><?php echo e($quotation['number_plate']); ?></p>
            </div>
            <div class="form-group">
                <label>Supplier</label>
                <p><?php echo e($quotation['supplier_name']); ?></p>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="invoiceForm">
    <!-- Invoice Header -->
    <div class="card">
        <div class="card-header">
            <h3>Invoice Information</h3>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label for="invoice_number">Invoice Number <span class="required">*</span></label>
                    <input type="text" id="invoice_number" name="invoice_number" class="form-control" required 
                           placeholder="e.g., INV-2026-001">
                    <small class="form-hint">As shown on supplier invoice</small>
                </div>
                
                <div class="form-group">
                    <label for="invoice_date">Invoice Date <span class="required">*</span></label>
                    <input type="date" id="invoice_date" name="invoice_date" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="received_date">Received Date</label>
                    <input type="date" id="received_date" name="received_date" class="form-control" 
                           value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label for="overall_output_tax">Overall Output Tax (16%)</label>
                    <input type="number" id="overall_output_tax" name="overall_output_tax" class="form-control" 
                           step="0.01" min="0" value="0" readonly>
                    <small class="form-hint">Automatically calculated as 16% of Total Net Value</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Invoice Items -->
    <div class="card">
        <div class="card-header">
            <h3>Invoice Items</h3>
            <button type="button" class="btn btn-sm btn-primary" onclick="addItem()">
                <i class="fas fa-plus"></i> Add Item
            </button>
        </div>
        <div class="card-body">
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Important:</strong> Enter ALL fields exactly as they appear on the Isuzu invoice. Do not calculate - just copy the values.
            </div>
            
            <div class="table-responsive">
                <table class="data-table" style="font-size: 12px;">
                    <thead>
                        <tr>
                            <th width="40">Item No</th>
                            <th width="100">Part No</th>
                            <th>Description</th>
                            <th width="60">Qty</th>
                            <th width="90">Price Unit</th>
                            <th width="80">Trade Disc %</th>
                            <th width="90">Trade Disc Value</th>
                            <th width="90">Net Value</th>
                            <th width="50"></th>
                        </tr>
                    </thead>
                    <tbody id="items-tbody">
                    </tbody>
                    <tfoot>
                        <tr style="font-weight: bold; background: #f9fafb;">
                            <td colspan="6" class="text-right">Total Net Value:</td>
                            <td id="total-net" colspan="2">KES 0.00</td>
                        </tr>
                        <tr style="font-weight: bold; background: #f9fafb;">
                            <td colspan="6" class="text-right">Total Output Tax:</td>
                            <td id="total-tax" colspan="2">KES 0.00</td>
                        </tr>
                        <tr style="font-weight: bold; background: #f9fafb; font-size: 14px;">
                            <td colspan="6" class="text-right">FINAL AMOUNT:</td>
                            <td id="final-amount" colspan="2" style="color: #667eea;">KES 0.00</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <input type="hidden" name="item_count" id="item-count" value="0">
        </div>
    </div>
    
    <!-- Notes -->
    <div class="card">
        <div class="card-header">
            <h3>Additional Notes</h3>
        </div>
        <div class="card-body">
            <div class="form-group">
                <textarea name="notes" class="form-control" rows="3" placeholder="Any notes about the delivery or invoice..."></textarea>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-footer">
            <a href="<?php echo APP_URL; ?>/modules/quotations/view_quotation.php?id=<?php echo $quotationId; ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-success btn-lg">
                <i class="fas fa-save"></i> Save Supplier Invoice
            </button>
        </div>
    </div>
</form>

<script>
let itemCounter = 0;

function addItem() {
    itemCounter++;
    const tbody = document.getElementById('items-tbody');
    const row = document.createElement('tr');
    row.id = `item-row-${itemCounter}`;
    row.innerHTML = `
        <td><input type="number" name="item_no_${itemCounter}" class="form-control form-control-sm" value="${itemCounter}" required style="width: 60px;"></td>
        <td><input type="text" name="part_number_${itemCounter}" class="form-control form-control-sm" required></td>
        <td><input type="text" name="description_${itemCounter}" class="form-control form-control-sm"></td>
        <td><input type="number" name="quantity_${itemCounter}" class="form-control form-control-sm" min="1" value="1" onchange="calculateTotals()" required style="width: 70px;"></td>
        <td><input type="number" name="price_unit_${itemCounter}" class="form-control form-control-sm" step="0.01" min="0" onchange="calculateTotals()" required></td>
        <td><input type="number" name="trade_disc_pct_${itemCounter}" class="form-control form-control-sm" step="0.01" min="0" value="0" onchange="calculateTotals()"></td>
        <td><input type="number" name="trade_disc_value_${itemCounter}" class="form-control form-control-sm" step="0.01" min="0" value="0" onchange="calculateTotals()" required></td>
        <td><input type="number" name="net_value_${itemCounter}" class="form-control form-control-sm" step="0.01" min="0" onchange="calculateTotals()" required></td>
        <td><button type="button" class="btn btn-sm btn-danger" onclick="removeItem(${itemCounter})"><i class="fas fa-trash"></i></button></td>
    `;
    tbody.appendChild(row);
    document.getElementById('item-count').value = itemCounter;
}

function removeItem(itemId) {
    const row = document.getElementById(`item-row-${itemId}`);
    if (row) {
        row.remove();
        calculateTotals();
    }
}

function calculateTotals() {
    let totalNet = 0;
    
    for (let i = 1; i <= itemCounter; i++) {
        const row = document.getElementById(`item-row-${i}`);
        if (row) {
            const qty = parseFloat(row.querySelector(`[name="quantity_${i}"]`)?.value) || 0;
            const netValue = parseFloat(row.querySelector(`[name="net_value_${i}"]`)?.value) || 0;
            
            totalNet += (netValue * qty);
        }
    }
    
    const totalTax = totalNet * 0.16; // Calculate as 16% of Total Net Value
    const finalAmount = totalNet + totalTax;
    
    document.getElementById('overall_output_tax').value = totalTax.toFixed(2); // Update the read-only field
    document.getElementById('total-net').textContent = formatCurrency(totalNet);
    document.getElementById('total-tax').textContent = formatCurrency(totalTax);
    document.getElementById('final-amount').textContent = formatCurrency(finalAmount);
}

// Add first item on load
addItem();
</script>

<?php include '../../includes/footer.php'; ?>
