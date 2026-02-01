<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = 'Vehicles';
$breadcrumbs = [
    ['text' => 'Vehicles']
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $number_plate = strtoupper(trim($_POST['number_plate'] ?? ''));
        $make = trim($_POST['make'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $year = trim($_POST['year'] ?? '');
        $vin = trim($_POST['vin'] ?? '');
        $owner_name = trim($_POST['owner_name'] ?? '');
        $owner_phone = trim($_POST['owner_phone'] ?? '');
        $owner_email = trim($_POST['owner_email'] ?? '');
        
        if (empty($number_plate) || empty($make) || empty($model) || empty($owner_name)) {
            setErrorMessage('Number plate, make, model, and owner name are required');
        } else {
            // Check if number plate already exists
            $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE number_plate = ?");
            $stmt->execute([$number_plate]);
            
            if ($stmt->fetch()) {
                setErrorMessage('A vehicle with this number plate already exists');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO vehicles (number_plate, make, model, year, vin, owner_name, owner_phone, owner_email)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                if ($stmt->execute([$number_plate, $make, $model, $year ?: null, $vin ?: null, $owner_name, $owner_phone ?: null, $owner_email ?: null])) {
                    logActivity($pdo, 'Created vehicle', 'vehicles', $pdo->lastInsertId(), "Number plate: $number_plate");
                    setSuccessMessage("Vehicle $number_plate created successfully");
                    redirect(APP_URL . '/modules/vehicles/vehicles.php');
                } else {
                    setErrorMessage('Failed to create vehicle');
                }
            }
        }
    } elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $number_plate = strtoupper(trim($_POST['number_plate'] ?? ''));
        $make = trim($_POST['make'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $year = trim($_POST['year'] ?? '');
        $vin = trim($_POST['vin'] ?? '');
        $owner_name = trim($_POST['owner_name'] ?? '');
        $owner_phone = trim($_POST['owner_phone'] ?? '');
        $owner_email = trim($_POST['owner_email'] ?? '');
        
        if (empty($number_plate) || empty($make) || empty($model) || empty($owner_name)) {
            setErrorMessage('Number plate, make, model, and owner name are required');
        } else {
            // Check if number plate exists for another vehicle
            $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE number_plate = ? AND id != ?");
            $stmt->execute([$number_plate, $id]);
            
            if ($stmt->fetch()) {
                setErrorMessage('Another vehicle with this number plate already exists');
            } else {
                $stmt = $pdo->prepare("
                    UPDATE vehicles 
                    SET number_plate = ?, make = ?, model = ?, year = ?, vin = ?, 
                        owner_name = ?, owner_phone = ?, owner_email = ?
                    WHERE id = ?
                ");
                
                if ($stmt->execute([$number_plate, $make, $model, $year ?: null, $vin ?: null, $owner_name, $owner_phone ?: null, $owner_email ?: null, $id])) {
                    logActivity($pdo, 'Updated vehicle', 'vehicles', $id, "Number plate: $number_plate");
                    setSuccessMessage("Vehicle $number_plate updated successfully");
                    redirect(APP_URL . '/modules/vehicles/vehicles.php');
                } else {
                    setErrorMessage('Failed to update vehicle');
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        
        // Check if vehicle has jobs
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM jobs WHERE vehicle_id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            setErrorMessage('Cannot delete vehicle with existing jobs');
        } else {
            $stmt = $pdo->prepare("DELETE FROM vehicles WHERE id = ?");
            if ($stmt->execute([$id])) {
                logActivity($pdo, 'Deleted vehicle', 'vehicles', $id);
                setSuccessMessage('Vehicle deleted successfully');
            } else {
                setErrorMessage('Failed to delete vehicle');
            }
        }
        redirect(APP_URL . '/modules/vehicles/vehicles.php');
    }
}

// Get action and ID from query string
$viewAction = $_GET['action'] ?? 'list';
$vehicleId = intval($_GET['id'] ?? 0);

// Load vehicle for edit
$vehicle = null;
if ($viewAction === 'edit' && $vehicleId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?");
    $stmt->execute([$vehicleId]);
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        setErrorMessage('Vehicle not found');
        redirect(APP_URL . '/modules/vehicles/vehicles.php');
    }
}

// Search functionality
$search = trim($_GET['search'] ?? '');
$whereClause = '';
$params = [];

if ($search) {
    $whereClause = "WHERE number_plate LIKE ? OR make LIKE ? OR model LIKE ? OR owner_name LIKE ?";
    $searchTerm = "%$search%";
    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
}

// Get vehicles
$stmt = $pdo->prepare("
    SELECT v.*, 
           (SELECT COUNT(*) FROM jobs WHERE vehicle_id = v.id) as total_jobs,
           (SELECT MAX(created_at) FROM jobs WHERE vehicle_id = v.id) as last_visit
    FROM vehicles v
    $whereClause
    ORDER BY v.created_at DESC
");
$stmt->execute($params);
$vehicles = $stmt->fetchAll();

include '../../includes/header.php';
?>

<?php if ($viewAction === 'list'): ?>
<!-- List View -->
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-car"></i> Vehicles</h1>
    <a href="?action=create" class="btn btn-primary">
        <i class="fas fa-plus"></i> Add New Vehicle
    </a>
</div>

<!-- Search Bar -->
<div class="card">
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 10px;">
            <input type="text" name="search" class="form-control" placeholder="Search by number plate, make, model, or owner..." value="<?php echo e($search); ?>" style="flex: 1;">
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

<!-- Vehicles Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($vehicles)): ?>
            <p class="text-center text-muted">
                <?php if ($search): ?>
                    No vehicles found matching "<?php echo e($search); ?>"
                <?php else: ?>
                    No vehicles registered yet. <a href="?action=create">Add your first vehicle</a>
                <?php endif; ?>
            </p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Number Plate</th>
                        <th>Vehicle</th>
                        <th>Year</th>
                        <th>Owner</th>
                        <th>Contact</th>
                        <th>Jobs</th>
                        <th>Last Visit</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vehicles as $v): ?>
                    <tr>
                        <td><strong><?php echo e($v['number_plate']); ?></strong></td>
                        <td><?php echo e($v['make'] . ' ' . $v['model']); ?></td>
                        <td><?php echo e($v['year'] ?: '-'); ?></td>
                        <td><?php echo e($v['owner_name']); ?></td>
                        <td>
                            <?php if ($v['owner_phone']): ?>
                                <i class="fas fa-phone"></i> <?php echo e($v['owner_phone']); ?><br>
                            <?php endif; ?>
                            <?php if ($v['owner_email']): ?>
                                <i class="fas fa-envelope"></i> <?php echo e($v['owner_email']); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($v['total_jobs'] > 0): ?>
                                <span class="badge badge-info"><?php echo $v['total_jobs']; ?> jobs</span>
                            <?php else: ?>
                                <span class="text-muted">No jobs</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $v['last_visit'] ? formatDate($v['last_visit']) : '-'; ?></td>
                        <td>
                            <div class="table-actions">
                                <a href="vehicle_history.php?id=<?php echo $v['id']; ?>" class="btn btn-sm btn-info" title="View History">
                                    <i class="fas fa-history"></i>
                                </a>
                                <a href="../jobs/jobs.php?action=create&vehicle_id=<?php echo $v['id']; ?>" class="btn btn-sm btn-success" title="Create Job">
                                    <i class="fas fa-plus"></i> Job
                                </a>
                                <a href="?action=edit&id=<?php echo $v['id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if ($v['total_jobs'] == 0): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirmDelete('Are you sure you want to delete this vehicle?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $v['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
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

<?php elseif ($viewAction === 'create' || $viewAction === 'edit'): ?>
<!-- Create/Edit Form -->
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-car"></i> <?php echo $viewAction === 'create' ? 'Add New Vehicle' : 'Edit Vehicle'; ?></h1>
    <a href="?" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>

<div class="card">
    <form method="POST">
        <input type="hidden" name="action" value="<?php echo $viewAction === 'create' ? 'create' : 'update'; ?>">
        <?php if ($viewAction === 'edit'): ?>
        <input type="hidden" name="id" value="<?php echo $vehicle['id']; ?>">
        <?php endif; ?>
        
        <div class="card-header">
            <h3>Vehicle Information</h3>
        </div>
        
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label for="number_plate">Number Plate <span class="required">*</span></label>
                    <input type="text" id="number_plate" name="number_plate" class="form-control" 
                           placeholder="e.g., KBZ 123A" required 
                           value="<?php echo $vehicle ? e($vehicle['number_plate']) : ''; ?>" 
                           style="text-transform: uppercase;">
                    <small class="form-hint">Will be automatically converted to uppercase</small>
                </div>
                
                <div class="form-group">
                    <label for="make">Make <span class="required">*</span></label>
                    <input type="text" id="make" name="make" class="form-control" 
                           placeholder="e.g., Toyota" required 
                           value="<?php echo $vehicle ? e($vehicle['make']) : ''; ?>"
                           list="make-list">
                    <datalist id="make-list">
                        <option value="Toyota">
                        <option value="Nissan">
                        <option value="Isuzu">
                        <option value="Mitsubishi">
                        <option value="Honda">
                        <option value="Mazda">
                        <option value="Subaru">
                        <option value="Suzuki">
                    </datalist>
                </div>
                
                <div class="form-group">
                    <label for="model">Model <span class="required">*</span></label>
                    <input type="text" id="model" name="model" class="form-control" 
                           placeholder="e.g., Corolla" required 
                           value="<?php echo $vehicle ? e($vehicle['model']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="year">Year</label>
                    <input type="number" id="year" name="year" class="form-control" 
                           placeholder="e.g., 2020" min="1900" max="<?php echo date('Y') + 1; ?>" 
                           value="<?php echo $vehicle ? e($vehicle['year']) : ''; ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="vin">VIN (Vehicle Identification Number)</label>
                    <input type="text" id="vin" name="vin" class="form-control" 
                           placeholder="17-character VIN" maxlength="17" 
                           value="<?php echo $vehicle ? e($vehicle['vin']) : ''; ?>">
                    <small class="form-hint">Optional, but recommended for accurate tracking</small>
                </div>
            </div>
        </div>
        
        <div class="card-header">
            <h3>Owner Information</h3>
        </div>
        
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label for="owner_name">Owner Name <span class="required">*</span></label>
                    <input type="text" id="owner_name" name="owner_name" class="form-control" 
                           placeholder="e.g., John Kamau" required 
                           value="<?php echo $vehicle ? e($vehicle['owner_name']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="owner_phone">Phone Number</label>
                    <input type="tel" id="owner_phone" name="owner_phone" class="form-control" 
                           placeholder="e.g., +254 712 345 678" 
                           value="<?php echo $vehicle ? e($vehicle['owner_phone']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="owner_email">Email Address</label>
                    <input type="email" id="owner_email" name="owner_email" class="form-control" 
                           placeholder="e.g., owner@email.com" 
                           value="<?php echo $vehicle ? e($vehicle['owner_email']) : ''; ?>">
                </div>
            </div>
        </div>
        
        <div class="card-footer">
            <a href="?" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo $viewAction === 'create' ? 'Create Vehicle' : 'Update Vehicle'; ?>
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
