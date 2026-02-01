<?php
require_once '../../config/config.php';
requireLogin();

// Only procurement officers and directors can edit quotations
if (!isProcurementOfficer() && !isDirector()) {
    setErrorMessage('You do not have permission to edit quotations');
    redirect(APP_URL . '/modules/dashboard/dashboard.php');
}

$quotationId = intval($_GET['id'] ?? 0);

// Fetch existing quotation data
$stmt = $pdo->prepare("
    SELECT q.*, j.job_number, v.number_plate, v.make, v.model, s.name as supplier_name
    FROM quotations q
    JOIN jobs j ON q.job_id = j.id
    JOIN vehicles v ON j.vehicle_id = v.id
    JOIN suppliers s ON q.supplier_id = s.id
    WHERE q.id = ?
");
$stmt->execute([$quotationId]);
$quotation = $stmt->fetch();

if (!$quotation) {
    setErrorMessage('Quotation not found.');
    redirect(APP_URL . '/modules/quotations/quotations.php');
}

// Check if quotation is editable
if ($quotation['status'] !== 'draft') {
    setErrorMessage('Only quotations in "Draft" status can be edited.');
    redirect(APP_URL . '/modules/quotations/view_quotation.php?id=' . $quotationId);
}

// Fetch existing quotation items
$stmt = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY item_no");
$stmt->execute([$quotationId]);
$existingItems = $stmt->fetchAll();

$pageTitle = 'Edit Quotation - ' . $quotation['quotation_number'];
$breadcrumbs = [
    ['text' => 'Quotations', 'url' => APP_URL . '/modules/quotations/quotations.php'],
    ['text' => $quotation['quotation_number'], 'url' => APP_URL . '/modules/quotations/view_quotation.php?id=' . $quotationId],
    ['text' => 'Edit Quotation']
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("Debug: POST data received: " . print_r($_POST, true)); // DEBUG LOG
    $job_id = intval($_POST['job_id'] ?? 0);
    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    $quotation_date = $_POST['quotation_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    $quotation_date = $_POST['quotation_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    
    // Get items
    $item_count = intval($_POST['item_count'] ?? 0);
    $items = [];
    
    for ($i = 1; $i <= $item_count; $i++) {
        if (isset($_POST["part_number_$i"])) {
            $items[] = [
                'part_number' => trim($_POST["part_number_$i"]),
                'description' => trim($_POST["description_$i"] ?? ''),
                'quantity' => intval($_POST["quantity_$i"] ?? 1),
                'list_price' => floatval($_POST["list_price_$i"] ?? 0),
                'discount_price' => floatval($_POST["discount_price_$i"] ?? 0)
            ];
        }
    }
    
    // Validate
    if ($job_id <= 0 || $supplier_id <= 0) {
        setErrorMessage('Job and supplier are required');
    } elseif (empty($items)) {
        setErrorMessage('Please add at least one item');
    } else {
        try {
            $pdo->beginTransaction();
            
            // Update quotation
            $stmt = $pdo->prepare("
                UPDATE quotations SET job_id = ?, supplier_id = ?, quotation_date = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$job_id, $supplier_id, $quotation_date, $notes, $quotationId]);
            
            // Delete existing quotation items
            $stmt = $pdo->prepare("DELETE FROM quotation_items WHERE quotation_id = ?");
            $stmt->execute([$quotationId]);
            
            // Insert new quotation items
            $item_no = 1;
            foreach ($items as $item) {
                if ($item['quantity'] > 0 && $item['price_excluding_vat'] > 0) {
                    $price_excluding_vat = $item['price_excluding_vat'];
                    $vat_amount = $price_excluding_vat * 0.16;
                    $total_amount = $price_excluding_vat + $vat_amount;
                    
                    error_log("Debug (Edit): Saving item - price_excluding_vat: $price_excluding_vat, vat_amount: $vat_amount, total_amount: $total_amount"); // DEBUG LOG
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO quotation_items (
                            quotation_id, item_no, part_number, description, quantity,
                            list_price, discount_price, price_excluding_vat, vat_amount, total_amount
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $quotationId, $item_no++, $item['part_number'], $item['description'],
                        $item['quantity'], $item['list_price'], $item['discount_price'],
                        $price_excluding_vat, $vat_amount, $total_amount
                    ]);
                }
            }
            
            // Update job status (only if it was 'awaiting_quotation_approval' and now perhaps needs re-approval)
            // For editing a draft, job status usually stays the same or is explicitly set to 'awaiting_quotation_approval' if it was edited.
            // Let's assume if it's draft, it goes to 'awaiting_quotation_approval' after editing.
            if ($quotation['status'] === 'draft') {
                $stmt = $pdo->prepare("UPDATE jobs SET status = 'awaiting_quotation_approval' WHERE id = ?");
                $stmt->execute([$job_id]);
                // Also update quotation status to pending_approval if it was draft
                $stmt = $pdo->prepare("UPDATE quotations SET status = 'pending_approval' WHERE id = ?");
                $stmt->execute([$quotationId]);
            }
            
            // Log activity
            logActivity($pdo, 'Updated quotation', 'quotations', $quotationId, "Quotation: " . $quotation['quotation_number']);
            
            $pdo->commit();
            
            setSuccessMessage("Quotation " . $quotation['quotation_number'] . " updated successfully.");
            redirect(APP_URL . '/modules/quotations/view_quotation.php?id=' . $quotationId);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            setErrorMessage('Failed to update quotation: ' . $e->getMessage());
        }
    }
}

// Get jobs that need parts
$jobsStmt = $pdo->query("
    SELECT j.id, j.job_number, v.number_plate, v.make, v.model
    FROM jobs j
    JOIN vehicles v ON j.vehicle_id = v.id
    WHERE j.status != 'invoiced'
    ORDER BY j.created_at DESC
");
$jobs = $jobsStmt->fetchAll();

// Get suppliers (Isuzu)
$suppliersStmt = $pdo->query("
    SELECT * FROM suppliers WHERE is_active = 1 ORDER BY name
");
$suppliers = $suppliersStmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-file-invoice"></i> Edit Quotation - <?php echo e($quotation['quotation_number']); ?></h1>
    <a href="<?php echo APP_URL; ?>/modules/quotations/view_quotation.php?id=<?php echo $quotationId; ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Quotation
    </a>
</div>

<form method="POST" id="quotationForm">
    <!-- Job & Supplier Selection -->
    <div class="card">
        <div class="card-header">
            <h3>Step 1: Select Job & Supplier</h3>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label for="job_id">Select Job <span class="required">*</span></label>
                    <select id="job_id" name="job_id" class="form-control" required>
                        <option value="">-- Select a job --</option>
                        <?php foreach ($jobs as $j): ?>
                        <option value="<?php echo $j['id']; ?>" <?php echo $quotation['job_id'] == $j['id'] ? 'selected' : ''; ?>>
                            <?php echo e($j['job_number']); ?> - <?php echo e($j['number_plate']); ?> (<?php echo e($j['make'] . ' ' . $j['model']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="supplier_id">Supplier <span class="required">*</span></label>
                    <select id="supplier_id" name="supplier_id" class="form-control" required>
                        <option value="">-- Select supplier --</option>
                        <?php foreach ($suppliers as $s): ?>
                        <option value="<?php echo $s['id']; ?>" <?php echo $quotation['supplier_id'] == $s['id'] ? 'selected' : ''; ?>>
                            <?php echo e($s['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="quotation_date">Quotation Date <span class="required">*</span></label>
                    <input type="date" id="quotation_date" name="quotation_date" class="form-control" 
                           value="<?php echo e($quotation['quotation_date']); ?>" required>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Parts Items -->
    <div class="card">
        <div class="card-header">
            <h3>Step 2: Add Parts</h3>
            <button type="button" class="btn btn-sm btn-primary" onclick="addItem()">
                <i class="fas fa-plus"></i> Add Part
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="data-table" id="items-table">
                    <thead>
                        <tr>
                            <th width="50">#</th>
                            <th width="150">Part Number</th>
                            <th>Description</th>
                            <th width="100">Qty</th>
                            <th width="120">List Price</th>
                            <th width="120">Discount Price</th>
                            <th width="120">Price Excl VAT</th>
                            <th width="120">VAT (16%)</th>
                            <th width="120">Total</th>
                            <th width="50"></th>
                        </tr>
                    </thead>
                    <tbody id="items-tbody">
                        <!-- Items will be added here dynamically -->
                    </tbody>
                    <tfoot>
                        <tr style="font-weight: bold; background: #f9fafb;">
                            <td colspan="8" class="text-right">GRAND TOTAL:</td>
                            <td id="grand-total">KES 0.00</td>
                            <td></td>
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
            <h3>Step 3: Additional Notes (Optional)</h3>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="3" 
                          placeholder="Any additional notes or special instructions..."><?php echo e($quotation['notes']); ?></textarea>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-footer">
            <a href="<?php echo APP_URL; ?>/modules/quotations/view_quotation.php?id=<?php echo $quotationId; ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-success btn-lg">
                <i class="fas fa-check-circle"></i> Update Quotation
            </button>
        </div>
    </div>
</form>

<script>
let itemCounter = 0;
const existingItems = <?php echo json_encode($existingItems); ?>;

function addItem(itemData = {}) {
    itemCounter++;
    const tbody = document.getElementById('items-tbody');
    const row = document.createElement('tr');
    row.id = `item-row-${itemCounter}`;
    row.innerHTML = `
        <td>${itemCounter}</td>
        <td><input type="text" name="part_number_${itemCounter}" class="form-control form-control-sm" placeholder="e.g., 12345" value="${itemData.part_number || ''}" required></td>
        <td><input type="text" name="description_${itemCounter}" class="form-control form-control-sm" placeholder="Part description" value="${itemData.description || ''}"></td>
        <td><input type="number" name="quantity_${itemCounter}" class="form-control form-control-sm" value="${itemData.quantity || 1}" min="1" onchange="calculateItemTotal(${itemCounter})" required></td>
        <td><input type="number" name="list_price_${itemCounter}" class="form-control form-control-sm" step="0.01" min="0" value="${itemData.list_price || 0}" onchange="calculateItemTotal(${itemCounter})" required></td>
        <td><input type="number" name="discount_price_${itemCounter}" class="form-control form-control-sm" step="0.01" min="0" value="${itemData.discount_price || 0}" onchange="calculateItemTotal(${itemCounter})" required></td>
        <td><input type="number" name="price_excluding_vat_${itemCounter}" class="form-control form-control-sm item-price-excluding-vat" step="0.01" min="0" value="${itemData.price_excluding_vat || 0}" onchange="calculateItemTotal(${itemCounter})" required></td>
        <td class="item-vat">KES 0.00</td>
        <td class="item-total">KES 0.00</td>
        <td><button type="button" class="btn btn-sm btn-danger" onclick="removeItem(${itemCounter})"><i class="fas fa-trash"></i></button></td>
    `;
    tbody.appendChild(row);
    document.getElementById('item-count').value = itemCounter;
    calculateItemTotal(itemCounter); // Calculate totals for the newly added item
}

function removeItem(itemId) {
    const row = document.getElementById(`item-row-${itemId}`);
    if (row) {
        row.remove();
        calculateGrandTotal();
    }
}

function calculateItemTotal(itemId) {
    const row = document.getElementById(`item-row-${itemId}`);
    if (!row) return;
    
    const quantity = parseFloat(row.querySelector(`[name="quantity_${itemId}"]`).value) || 0;
    const listPrice = parseFloat(row.querySelector(`[name="list_price_${itemId}"]`).value) || 0;
    const discountPriceInput = row.querySelector(`[name="discount_price_${itemId}"]`);
    const priceExclVatInput = row.querySelector(`.item-price-excluding-vat`);

    const discountPrice = parseFloat(discountPriceInput.value) || 0;
    
    // Initial calculation for Price Excl VAT (from discountPrice)
    const calculatedPriceExclVat = discountPrice; 
    
    // Set the value of the editable input field. This will be overridden if user types.
    // Only update if the user hasn't explicitly typed something different
    if (parseFloat(priceExclVatInput.value) !== calculatedPriceExclVat && priceExclVatInput.value !== '') {
        // User has manually entered a value, do not override
    } else {
        priceExclVatInput.value = calculatedPriceExclVat.toFixed(2);
    }
    
    // Use the current value from the input field for further calculations
    const priceExclVat = parseFloat(priceExclVatInput.value) || 0;

    const vat = priceExclVat * 0.16;
    const total = priceExclVat + vat;
    
    row.querySelector('.item-vat').textContent = formatCurrency(vat);
    row.querySelector('.item-total').textContent = formatCurrency(total);
    
    calculateGrandTotal();
}

function calculateGrandTotal() {
    let grandTotal = 0;
    document.querySelectorAll('.item-total').forEach(cell => {
        const value = parseFloat(cell.textContent.replace('KES ', '').replace(/,/g, '')) || 0;
        grandTotal += value;
    });
    document.getElementById('grand-total').textContent = formatCurrency(grandTotal);
}

// Pre-fill with existing items or add a new one if none
if (existingItems.length > 0) {
    existingItems.forEach(item => addItem(item));
} else {
    addItem(); // Add one empty item if starting fresh
}
</script>

<?php include '../../includes/footer.php'; ?>
