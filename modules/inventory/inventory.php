<?php
require_once '../../config/config.php';
requireLogin();

// Check if the user has the necessary permissions (e.g., Procurement Officer or Director)
if (!isProcurementOfficer() && !isDirector()) {
    redirect(APP_URL . '/dashboard.php'); // Redirect to dashboard if not authorized
}

$pageTitle = 'Inventory Management';
$breadcrumbs = [
    ['text' => 'Inventory Management']
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $part_name = trim($_POST['part_name'] ?? '');
        $part_number = trim($_POST['part_number'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $quantity_on_hand = intval($_POST['quantity_on_hand'] ?? 0);
        $reorder_level = intval($_POST['reorder_level'] ?? 0);
        $supplier_id = intval($_POST['supplier_id'] ?? 0);
        $cost_price = floatval($_POST['cost_price'] ?? 0);
        $selling_price = floatval($_POST['selling_price'] ?? 0);
        
        if (empty($part_name)) {
            setErrorMessage('Part Name is required');
        } else {
            // Check if part name already exists
            $stmt = $pdo->prepare("SELECT id FROM inventory_parts WHERE part_name = ?");
            $stmt->execute([$part_name]);
            
            if ($stmt->fetch()) {
                setErrorMessage('A part with this name already exists');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_parts (part_name, part_number, description, quantity_on_hand, reorder_level, supplier_id, cost_price, selling_price)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                if ($stmt->execute([$part_name, $part_number ?: null, $description ?: null, $quantity_on_hand, $reorder_level, $supplier_id ?: null, $cost_price, $selling_price])) {
                    logActivity($pdo, 'Created inventory part', 'inventory_parts', $pdo->lastInsertId(), "Part Name: $part_name");
                    setSuccessMessage("Part '$part_name' created successfully");
                    redirect(APP_URL . '/modules/inventory/inventory.php');
                } else {
                    setErrorMessage('Failed to create part');
                }
            }
        }
    } elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $part_name = trim($_POST['part_name'] ?? '');
        $part_number = trim($_POST['part_number'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $quantity_on_hand = intval($_POST['quantity_on_hand'] ?? 0);
        $reorder_level = intval($_POST['reorder_level'] ?? 0);
        $supplier_id = intval($_POST['supplier_id'] ?? 0);
        $cost_price = floatval($_POST['cost_price'] ?? 0);
        $selling_price = floatval($_POST['selling_price'] ?? 0);
        
        if (empty($part_name)) {
            setErrorMessage('Part Name is required');
        } else {
            // Check if part name exists for another part
            $stmt = $pdo->prepare("SELECT id FROM inventory_parts WHERE part_name = ? AND id != ?");
            $stmt->execute([$part_name, $id]);
            
            if ($stmt->fetch()) {
                setErrorMessage('Another part with this name already exists');
            } else {
                $stmt = $pdo->prepare("
                    UPDATE inventory_parts 
                    SET part_name = ?, part_number = ?, description = ?, quantity_on_hand = ?, reorder_level = ?, 
                        supplier_id = ?, cost_price = ?, selling_price = ?
                    WHERE id = ?
                ");
                
                if ($stmt->execute([$part_name, $part_number ?: null, $description ?: null, $quantity_on_hand, $reorder_level, $supplier_id ?: null, $cost_price, $selling_price, $id])) {
                    logActivity($pdo, 'Updated inventory part', 'inventory_parts', $id, "Part Name: $part_name");
                    setSuccessMessage("Part '$part_name' updated successfully");
                    redirect(APP_URL . '/modules/inventory/inventory.php');
                } else {
                    setErrorMessage('Failed to update part');
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        
        // TODO: Add logic to check if part is used in any jobs/invoices before deleting
        // For now, allow deletion
        $stmt = $pdo->prepare("DELETE FROM inventory_parts WHERE id = ?");
        if ($stmt->execute([$id])) {
            logActivity($pdo, 'Deleted inventory part', 'inventory_parts', $id);
            setSuccessMessage('Part deleted successfully');
        } else {
            setErrorMessage('Failed to delete part');
        }
        redirect(APP_URL . '/modules/inventory/inventory.php');
    } elseif ($action === 'adjust_stock') {
        $id = intval($_POST['id'] ?? 0);
        $adjustment_type = $_POST['adjustment_type'] ?? '';
        $quantity = intval($_POST['quantity'] ?? 0);

        if ($id > 0 && $quantity > 0) {
            $current_stock_stmt = $pdo->prepare("SELECT quantity_on_hand FROM inventory_parts WHERE id = ?");
            $current_stock_stmt->execute([$id]);
            $current_stock = $current_stock_stmt->fetchColumn();

            $new_quantity = $current_stock;
            if ($adjustment_type === 'add') {
                $new_quantity += $quantity;
            } elseif ($adjustment_type === 'subtract') {
                $new_quantity -= $quantity;
            }

            if ($new_quantity < 0) {
                setErrorMessage('Cannot reduce stock below zero.');
            } else {
                $stmt = $pdo->prepare("UPDATE inventory_parts SET quantity_on_hand = ? WHERE id = ?");
                if ($stmt->execute([$new_quantity, $id])) {
                    logActivity($pdo, 'Adjusted stock for inventory part', 'inventory_parts', $id, "Type: $adjustment_type, Quantity: $quantity, New Stock: $new_quantity");
                    setSuccessMessage('Stock adjusted successfully.');
                } else {
                    setErrorMessage('Failed to adjust stock.');
                }
            }
        } else {
            setErrorMessage('Invalid adjustment parameters.');
        }
        redirect(APP_URL . '/modules/inventory/inventory.php');
    }
}

// Get action and ID from query string
$viewAction = $_GET['action'] ?? 'list';
$partId = intval($_GET['id'] ?? 0);

// Load part for edit
$part = null;
if ($viewAction === 'edit' && $partId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM inventory_parts WHERE id = ?");
    $stmt->execute([$partId]);
    $part = $stmt->fetch();
    
    if (!$part) {
        setErrorMessage('Part not found');
        redirect(APP_URL . '/modules/inventory/inventory.php');
    }
}

// Fetch suppliers for dropdown
$suppliers = $pdo->query("SELECT id, name as supplier_name FROM suppliers ORDER BY name")->fetchAll();

// Search functionality
$search = trim($_GET['search'] ?? '');
$whereClause = '';
$params = [];

if ($search) {
    $whereClause = "WHERE ip.part_name LIKE ? OR ip.part_number LIKE ? OR s.supplier_name LIKE ?";
    $searchTerm = "%$search%";
    $params = [$searchTerm, $searchTerm, $searchTerm];
}

// Get inventory parts
$stmt = $pdo->prepare("
    SELECT ip.*, s.supplier_name 
    FROM inventory_parts ip
    LEFT JOIN suppliers s ON ip.supplier_id = s.id
    $whereClause
    ORDER BY ip.created_at DESC
");
$stmt->execute($params);
$inventoryParts = $stmt->fetchAll();

include '../../includes/header.php';
?>

<?php if ($viewAction === 'list'): ?>
<!-- List View -->
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-boxes"></i> Inventory Management</h1>
    <a href="?action=create" class="btn btn-primary">
        <i class="fas fa-plus"></i> Add New Part
    </a>
</div>

<!-- Search Bar -->
<div class="card">
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 10px;">
            <input type="text" name="search" class="form-control" placeholder="Search by part name, number, or supplier..." value="<?php echo e($search); ?>" style="flex: 1;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i> Search
            </button>
            <?php if ($search): ?>
            <a href="?" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Inventory Parts Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($inventoryParts)): ?>
            <p class="text-center text-muted">
                <?php if ($search): ?>
                    No inventory parts found matching "<?php echo e($search); ?>"
                <?php else: ?>
                    No inventory parts registered yet. <a href="?action=create">Add your first part</a>
                <?php endif; ?>
            </p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Part Name</th>
                        <th>Part No.</th>
                        <th>Description</th>
                        <th>Qty. on Hand</th>
                        <th>Reorder Level</th>
                        <th>Supplier</th>
                        <th>Cost Price</th>
                        <th>Selling Price</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventoryParts as $item): ?>
                    <tr class="<?php echo $item['quantity_on_hand'] <= $item['reorder_level'] ? 'table-danger' : ''; ?>">
                        <td><strong><?php echo e($item['part_name']); ?></strong></td>
                        <td><?php echo e($item['part_number'] ?: '-'); ?></td>
                        <td><?php echo e(truncateText($item['description'], 50) ?: '-'); ?></td>
                        <td>
                            <?php echo e($item['quantity_on_hand']); ?>
                            <?php if ($item['quantity_on_hand'] <= $item['reorder_level']): ?>
                                <span class="badge bg-danger ms-2">Low Stock!</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e($item['reorder_level']); ?></td>
                        <td><?php echo e($item['supplier_name'] ?: '-'); ?></td>
                        <td><?php echo formatCurrency($item['cost_price']); ?></td>
                        <td><?php echo formatCurrency($item['selling_price']); ?></td>
                        <td>
                            <div class="table-actions">
                                <!-- Edit Button -->
                                <a href="?action=edit&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <!-- Adjust Stock Button -->
                                <button type="button" class="btn btn-sm btn-info" title="Adjust Stock" data-bs-toggle="modal" data-bs-target="#adjustStockModal" data-part-id="<?php echo $item['id']; ?>" data-part-name="<?php echo e($item['part_name']); ?>">
                                    <i class="fas fa-warehouse"></i>
                                </button>
                                <!-- Delete Button -->
                                <form method="POST" style="display: inline;" onsubmit="return confirmDelete('Are you sure you want to delete this part?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Adjust Stock Modal -->
<div class="modal fade" id="adjustStockModal" tabindex="-1" aria-labelledby="adjustStockModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title" id="adjustStockModalLabel">Adjust Stock for <span id="modalPartName"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="adjust_stock">
          <input type="hidden" name="id" id="modalPartId">
          <div class="form-group">
            <label for="adjustment_type">Adjustment Type</label>
            <select class="form-control" id="adjustment_type" name="adjustment_type" required>
              <option value="add">Add Stock</option>
              <option value="subtract">Subtract Stock</option>
            </select>
          </div>
          <div class="form-group mt-3">
            <label for="quantity">Quantity</label>
            <input type="number" class="form-control" id="quantity" name="quantity" min="1" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php elseif ($viewAction === 'create' || $viewAction === 'edit'): ?>
<!-- Create/Edit Form -->
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-boxes"></i> <?php echo $viewAction === 'create' ? 'Add New Part' : 'Edit Part'; ?></h1>
    <a href="?" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>

<div class="card">
    <form method="POST">
        <input type="hidden" name="action" value="<?php echo $viewAction === 'create' ? 'create' : 'update'; ?>">
        <?php if ($viewAction === 'edit'): ?>
        <input type="hidden" name="id" value="<?php echo $part['id']; ?>">
        <?php endif; ?>
        
        <div class="card-header">
            <h3>Part Information</h3>
        </div>
        
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label for="part_name">Part Name <span class="required">*</span></label>
                    <input type="text" id="part_name" name="part_name" class="form-control" 
                           placeholder="e.g., Oil Filter" required 
                           value="<?php echo $part ? e($part['part_name']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="part_number">Part Number</label>
                    <input type="text" id="part_number" name="part_number" class="form-control" 
                           placeholder="e.g., 90915-YZZD2" 
                           value="<?php echo $part ? e($part['part_number']) : ''; ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="form-control" rows="3" 
                          placeholder="Brief description of the part"><?php echo $part ? e($part['description']) : ''; ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="quantity_on_hand">Quantity on Hand <span class="required">*</span></label>
                    <input type="number" id="quantity_on_hand" name="quantity_on_hand" class="form-control" 
                           min="0" required 
                           value="<?php echo $part ? e($part['quantity_on_hand']) : '0'; ?>">
                </div>
                
                <div class="form-group">
                    <label for="reorder_level">Reorder Level <span class="required">*</span></label>
                    <input type="number" id="reorder_level" name="reorder_level" class="form-control" 
                           min="0" required 
                           value="<?php echo $part ? e($part['reorder_level']) : '0'; ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="supplier_id">Supplier</label>
                    <select id="supplier_id" name="supplier_id" class="form-control">
                        <option value="">-- Select Supplier --</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo $supplier['id']; ?>" 
                                <?php echo ($part && $part['supplier_id'] == $supplier['id']) ? 'selected' : ''; ?>>
                                <?php echo e($supplier['supplier_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="cost_price">Cost Price <span class="required">*</span></label>
                    <input type="number" id="cost_price" name="cost_price" class="form-control" 
                           step="0.01" min="0" required 
                           value="<?php echo $part ? e($part['cost_price']) : '0.00'; ?>">
                </div>
                
                <div class="form-group">
                    <label for="selling_price">Selling Price <span class="required">*</span></label>
                    <input type="number" id="selling_price" name="selling_price" class="form-control" 
                           step="0.01" min="0" required 
                           value="<?php echo $part ? e($part['selling_price']) : '0.00'; ?>">
                </div>
            </div>
        </div>
        
        <div class="card-footer">
            <a href="?" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo $viewAction === 'create' ? 'Create Part' : 'Update Part'; ?>
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var adjustStockModal = document.getElementById('adjustStockModal');
    adjustStockModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget; // Button that triggered the modal
        var partId = button.getAttribute('data-part-id');
        var partName = button.getAttribute('data-part-name');
        
        var modalPartName = adjustStockModal.querySelector('#modalPartName');
        var modalPartId = adjustStockModal.querySelector('#modalPartId');
        
        modalPartName.textContent = partName;
        modalPartId.value = partId;
        
        // Reset quantity input
        var quantityInput = adjustStockModal.querySelector('#quantity');
        quantityInput.value = '';
    });
});
</script>