<?php
require_once '../../config/config.php';
requireLogin();

// Only directors can manage suppliers
if (!isDirector()) {
    setErrorMessage('You do not have permission to manage suppliers');
    redirect(APP_URL . '/modules/dashboard/dashboard.php');
}

$pageTitle = 'Manage Suppliers';
$breadcrumbs = [
    ['text' => 'Admin'],
    ['text' => 'Suppliers']
];

// Handle Add/Edit Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $supplier_type = $_POST['supplier_type'] ?? 'parts';
    $contact_person = trim($_POST['contact_person'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (empty($name) || empty($supplier_type)) {
        setErrorMessage('Supplier Name and Type are required.');
    } else {
        try {
            if ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO suppliers (name, supplier_type, contact_person, phone, email, address, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $supplier_type, $contact_person, $phone, $email, $address, $is_active]);
                $new_id = $pdo->lastInsertId();
                logActivity($pdo, 'Added supplier', 'suppliers', $new_id, "Name: $name");
                setSuccessMessage('Supplier added successfully.');
            } elseif ($action === 'edit' && $supplier_id > 0) {
                $stmt = $pdo->prepare("UPDATE suppliers SET name = ?, supplier_type = ?, contact_person = ?, phone = ?, email = ?, address = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$name, $supplier_type, $contact_person, $phone, $email, $address, $is_active, $supplier_id]);
                logActivity($pdo, 'Updated supplier', 'suppliers', $supplier_id, "Name: $name");
                setSuccessMessage('Supplier updated successfully.');
            }
        } catch (PDOException $e) {
            setErrorMessage('Database error: ' . $e->getMessage());
        }
    }
    redirect(APP_URL . '/modules/settings/suppliers.php');
}

// Handle Delete Action
if (isset($_GET['delete']) && isDirector()) {
    $delete_id = intval($_GET['delete']);
    try {
        // Check for dependencies before deleting
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM quotations WHERE supplier_id = ?");
        $stmt->execute([$delete_id]);
        if ($stmt->fetchColumn() > 0) {
            setErrorMessage('Cannot delete supplier: has associated quotations.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
            $stmt->execute([$delete_id]);
            logActivity($pdo, 'Deleted supplier', 'suppliers', $delete_id);
            setSuccessMessage('Supplier deleted successfully.');
        }
    } catch (PDOException $e) {
        setErrorMessage('Database error: ' . $e->getMessage());
    }
    redirect(APP_URL . '/modules/settings/suppliers.php');
}

// Fetch all suppliers
$stmt = $pdo->query("SELECT * FROM suppliers ORDER BY name");
$suppliers = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-truck"></i> Manage Suppliers</h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEditSupplierModal" onclick="clearSupplierForm()">
        <i class="fas fa-plus"></i> Add New Supplier
    </button>
</div>

<div class="card">
    <div class="card-body">
        <?php if (empty($suppliers)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> No suppliers found.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Contact Person</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($suppliers as $supplier): ?>
                    <tr>
                        <td><?php echo e($supplier['name']); ?></td>
                        <td><?php echo e(ucwords(str_replace('_', ' ', $supplier['supplier_type']))); ?></td>
                        <td><?php echo e($supplier['contact_person'] ?: '-'); ?></td>
                        <td><?php echo e($supplier['phone'] ?: '-'); ?></td>
                        <td><?php echo e($supplier['email'] ?: '-'); ?></td>
                        <td>
                            <?php if ($supplier['is_active']): ?>
                                <span class="badge badge-success">Yes</span>
                            <?php else: ?>
                                <span class="badge badge-danger">No</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#addEditSupplierModal" onclick="editSupplier(<?php echo htmlspecialchars(json_encode($supplier)); ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <a href="?delete=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this supplier?');">
                                <i class="fas fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Supplier Modal -->
<div class="modal fade" id="addEditSupplierModal" tabindex="-1" aria-labelledby="addEditSupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="supplierForm" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="addEditSupplierModalLabel">Add/Edit Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="supplier-action" value="add">
                    <input type="hidden" name="supplier_id" id="supplier-id">
                    
                    <div class="form-group">
                        <label for="supplier-name">Supplier Name <span class="required">*</span></label>
                        <input type="text" class="form-control" id="supplier-name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="supplier-type">Supplier Type <span class="required">*</span></label>
                        <select class="form-control" id="supplier-type" name="supplier_type" required>
                            <option value="parts">Parts</option>
                            <option value="both">Both (Parts & Service)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="supplier-contact-person">Contact Person</label>
                        <input type="text" class="form-control" id="supplier-contact-person" name="contact_person">
                    </div>
                    <div class="form-group">
                        <label for="supplier-phone">Phone</label>
                        <input type="text" class="form-control" id="supplier-phone" name="phone">
                    </div>
                    <div class="form-group">
                        <label for="supplier-email">Email</label>
                        <input type="email" class="form-control" id="supplier-email" name="email">
                    </div>
                    <div class="form-group">
                        <label for="supplier-address">Address</label>
                        <textarea class="form-control" id="supplier-address" name="address" rows="3"></textarea>
                    </div>
                    <div class="form-group form-check">
                        <input type="checkbox" class="form-check-input" id="supplier-is-active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="supplier-is-active">Is Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
