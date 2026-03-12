<?php
/**
 * Courier Management
 * Add, edit, toggle, and manage couriers and staff
 * Location: /dispatch/couriers.php
 */

require_once __DIR__ . '/../admin-auth.php';
requirePermission('dispatch');

require_once __DIR__ . '/dispatch-functions.php';

// ============================================
// AJAX Handlers
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    switch ($_POST['ajax_action']) {
        case 'add_courier':
            $name = trim($_POST['name'] ?? '');
            $role = $_POST['role'] ?? 'courier';
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $vehicle = $_POST['vehicle_type'] ?? 'car';
            $notes = trim($_POST['notes'] ?? '');

            if (empty($name)) {
                echo json_encode(['success' => false, 'error' => 'Name is required']);
                break;
            }

            $result = dispatch_addCourier($name, $role, $phone, $email, $vehicle, $notes);

            if ($result['success'] && function_exists('logAdminActivity')) {
                logAdminActivity('Courier Added', ['name' => $name, 'pin' => $result['pin'], 'role' => $role], $result['pin']);
            }

            echo json_encode($result);
            break;

        case 'update_courier':
            $pin = $_POST['pin'] ?? '';
            $fields = [
                'name' => trim($_POST['name'] ?? ''),
                'role' => $_POST['role'] ?? 'courier',
                'phone' => trim($_POST['phone'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'vehicle_type' => $_POST['vehicle_type'] ?? 'car',
                'notes' => trim($_POST['notes'] ?? ''),
            ];

            if (empty($pin) || empty($fields['name'])) {
                echo json_encode(['success' => false, 'error' => 'PIN and name are required']);
                break;
            }

            $result = dispatch_updateCourier($pin, $fields);

            if ($result['success'] && function_exists('logAdminActivity')) {
                logAdminActivity('Courier Updated', ['pin' => $pin, 'name' => $fields['name']], $pin);
            }

            echo json_encode($result);
            break;

        case 'toggle_courier':
            $pin = $_POST['pin'] ?? '';
            if (empty($pin)) {
                echo json_encode(['success' => false, 'error' => 'PIN required']);
                break;
            }
            $result = dispatch_toggleCourier($pin);
            echo json_encode($result);
            break;

        case 'delete_courier':
            $pin = $_POST['pin'] ?? '';
            if (empty($pin)) {
                echo json_encode(['success' => false, 'error' => 'PIN required']);
                break;
            }
            $result = dispatch_deleteCourier($pin);

            if ($result['success'] && function_exists('logAdminActivity')) {
                logAdminActivity('Courier Deleted', ['pin' => $pin, 'name' => $result['name']], $pin);
            }

            echo json_encode($result);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
    exit;
}

// ============================================
// Page Load
// ============================================
$couriersData = json_decode(file_get_contents(DISPATCH_COURIERS_FILE), true);
$users = $couriersData['users'] ?? [];
$roles = $couriersData['roles'] ?? [];
$meta = $couriersData['metadata'] ?? [];

// Sort: active first, then by name
uasort($users, function($a, $b) {
    if ($a['active'] !== $b['active']) return $b['active'] - $a['active'];
    return strcasecmp($a['name'], $b['name']);
});

$activeCount = count(array_filter($users, function($u) { return $u['active']; }));
$totalDeliveries = array_sum(array_column($users, 'total_deliveries'));
$totalEarned = array_sum(array_column($users, 'total_earned'));

$roleIcons = [
    'courier' => '&#128666;',
    'mtcc_staff' => '&#127970;',
    'admin' => '&#128272;',
];
$vehicleIcons = [
    'car' => '&#128663;',
    'bicycle' => '&#128690;',
    'motorcycle' => '&#127949;',
    'on_foot' => '&#128694;',
    'van' => '&#128656;',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Courier Management - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin-base.css">
    <link rel="stylesheet" href="../css/admin-components.css">
    <link rel="stylesheet" href="../css/admin-layout.css">
    <link rel="stylesheet" href="dispatch-hub.css">
    <style>
        .courier-page-header {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border-radius: var(--radius);
            padding: 14px 24px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .courier-title { font-size: 1.4rem; font-weight: 700; color: #374151; margin: 0; }
        .courier-subtitle { font-size: 0.78rem; color: var(--subtext); margin-top: 2px; }
        .courier-header-actions { display: flex; gap: 8px; align-items: center; }
        .courier-back {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px; border-radius: 8px; font-size: 0.8rem; font-weight: 600;
            color: #374151; background: #f3f4f6; border: 1px solid #e5e7eb;
            text-decoration: none; transition: all 0.2s ease;
        }
        .courier-back:hover { background: #e5e7eb; color: var(--primary); }
        .courier-add-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; border-radius: 8px; font-size: 0.82rem; font-weight: 600;
            color: #fff; background: var(--primary); border: none; cursor: pointer;
            transition: all 0.15s ease;
        }
        .courier-add-btn:hover { background: var(--primary-dark); }

        /* Stats bar */
        .courier-stats {
            display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap;
        }
        .courier-stat {
            background: #fff; border: 1px solid #e5e7eb; border-radius: var(--radius);
            padding: 12px 18px; box-shadow: var(--shadow-sm); flex: 1; min-width: 140px;
        }
        .courier-stat-value { font-size: 1.4rem; font-weight: 700; color: #374151; }
        .courier-stat-label { font-size: 0.72rem; color: var(--subtext); font-weight: 500; }

        /* Cards grid */
        .courier-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
        }

        .courier-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: box-shadow 0.2s ease;
        }
        .courier-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .courier-card.inactive { opacity: 0.55; }
        .courier-card.inactive .courier-card-header { background: #f3f4f6; }

        .courier-card-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 16px; background: #faf8ff; border-bottom: 1px solid #f1f5f9;
        }
        .courier-card-name {
            font-size: 0.95rem; font-weight: 700; color: #374151;
            display: flex; align-items: center; gap: 8px;
        }
        .courier-card-pin {
            font-size: 0.68rem; font-weight: 600; font-family: monospace;
            background: var(--primary-light); color: var(--primary);
            padding: 2px 6px; border-radius: 4px;
        }
        .courier-role-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 8px; border-radius: 10px; font-size: 0.68rem; font-weight: 700;
        }
        .role-courier { background: #ede9fe; color: #7c3aed; }
        .role-mtcc_staff { background: #dbeafe; color: #1d4ed8; }
        .role-admin { background: #fef3c7; color: #92400e; }

        .courier-card-body { padding: 14px 16px; }
        .courier-card-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 4px 0; font-size: 0.78rem;
        }
        .courier-card-label { color: var(--subtext); font-weight: 500; }
        .courier-card-value { color: #374151; font-weight: 600; }
        .courier-card-notes {
            margin-top: 8px; padding: 6px 10px; background: #f8fafc;
            border-radius: 6px; font-size: 0.72rem; color: var(--subtext);
            font-style: italic;
        }

        .courier-card-footer {
            display: flex; align-items: center; gap: 6px;
            padding: 10px 16px; border-top: 1px solid #f1f5f9; background: #fafafa;
        }
        .courier-action-btn {
            padding: 5px 12px; border-radius: 6px; font-size: 0.72rem; font-weight: 600;
            cursor: pointer; border: 1px solid #e5e7eb; background: #fff; color: #374151;
            transition: all 0.15s ease;
        }
        .courier-action-btn:hover { background: #f3f4f6; }
        .courier-action-btn.danger { color: #dc2626; border-color: #fecaca; }
        .courier-action-btn.danger:hover { background: #fef2f2; }
        .courier-action-btn.toggle-active { color: #059669; border-color: #a7f3d0; }
        .courier-action-btn.toggle-inactive { color: #d97706; border-color: #fde68a; }

        .courier-avail { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }
        .avail-online { background: #10b981; }
        .avail-busy { background: #f59e0b; }
        .avail-offline { background: #d1d5db; }

        /* Modal */
        .courier-modal-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5); z-index: 9990;
            display: flex; align-items: center; justify-content: center;
            animation: fadeIn 0.2s ease;
        }
        .courier-modal {
            background: #fff; border-radius: 12px; width: 90%; max-width: 480px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3); animation: slideUp 0.25s ease;
        }
        .courier-modal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 24px; border-bottom: 1px solid #e5e7eb;
        }
        .courier-modal-title { font-size: 1.1rem; font-weight: 700; color: #374151; margin: 0; }
        .courier-modal-close {
            background: none; border: none; font-size: 1.5rem; color: #9ca3af;
            cursor: pointer; padding: 4px 8px; border-radius: 6px; line-height: 1;
        }
        .courier-modal-close:hover { background: #f3f4f6; color: #374151; }
        .courier-modal-body { padding: 20px 24px; }
        .courier-form-row { margin-bottom: 14px; }
        .courier-form-label {
            display: block; font-size: 0.78rem; font-weight: 600;
            color: var(--subtext); margin-bottom: 4px;
        }
        .courier-form-input, .courier-form-select {
            width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;
            font-size: 0.82rem; font-family: 'Montserrat', sans-serif;
            box-sizing: border-box;
        }
        .courier-form-input:focus, .courier-form-select:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 2px var(--primary-light);
        }
        .courier-form-row-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .courier-modal-footer {
            display: flex; align-items: center; justify-content: flex-end; gap: 8px;
            padding: 14px 24px; border-top: 1px solid #e5e7eb;
        }
        .courier-modal-btn {
            padding: 8px 18px; border-radius: 8px; font-size: 0.82rem; font-weight: 600;
            cursor: pointer; transition: all 0.15s ease; border: 1px solid transparent;
        }
        .courier-modal-cancel { background: #f3f4f6; color: var(--subtext); border-color: #d1d5db; }
        .courier-modal-cancel:hover { background: #e5e7eb; }
        .courier-modal-save { background: var(--primary); color: #fff; }
        .courier-modal-save:hover { background: var(--primary-dark); }
        .courier-modal-save:disabled { opacity: 0.6; cursor: not-allowed; }

        .courier-pin-display {
            text-align: center; padding: 12px; background: #f8fafc; border-radius: 8px;
            margin-bottom: 14px; border: 1px dashed #d1d5db;
        }
        .courier-pin-value {
            font-size: 1.8rem; font-weight: 700; font-family: monospace;
            color: var(--primary); letter-spacing: 0.15em;
        }
        .courier-pin-hint { font-size: 0.72rem; color: var(--subtext); margin-top: 4px; }

        /* Delete confirm */
        .delete-confirm-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5); z-index: 9995;
            display: flex; align-items: center; justify-content: center;
        }
        .delete-confirm-box {
            background: #fff; border-radius: 12px; padding: 24px; width: 90%; max-width: 360px;
            text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .delete-confirm-name { font-weight: 700; color: #dc2626; }

        @media (max-width: 640px) {
            .courier-grid { grid-template-columns: 1fr; }
            .courier-stats { flex-direction: column; }
            .courier-form-row-2col { grid-template-columns: 1fr; }
        }
    </style>
<link rel="stylesheet" href="../css/admin-sidebar.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('dispatch_couriers'); ?>
<script src="../js/admin-sidebar.js"></script>
<div style="margin: 0 auto; padding: 0 20px;">

    <div class="courier-page-header">
        <div>
            <h1 class="courier-title">&#128100; Courier Management</h1>
            <div class="courier-subtitle">Manage couriers, MTCC staff, and delivery personnel</div>
        </div>
        <div class="courier-header-actions">
            <a href="./" class="courier-back">&#8592; Hub</a>
            <button class="courier-add-btn" onclick="openCourierModal()">+ Add Courier</button>
        </div>
    </div>

    <!-- Stats -->
    <div class="courier-stats">
        <div class="courier-stat">
            <div class="courier-stat-value"><?= count($users) ?></div>
            <div class="courier-stat-label">Total Personnel</div>
        </div>
        <div class="courier-stat">
            <div class="courier-stat-value"><?= $activeCount ?></div>
            <div class="courier-stat-label">Active</div>
        </div>
        <div class="courier-stat">
            <div class="courier-stat-value"><?= $totalDeliveries ?></div>
            <div class="courier-stat-label">Total Deliveries</div>
        </div>
        <div class="courier-stat">
            <div class="courier-stat-value">$<?= number_format($totalEarned, 2) ?></div>
            <div class="courier-stat-label">Total Earned</div>
        </div>
    </div>

    <!-- Courier Cards -->
    <div class="courier-grid" id="courierGrid">
        <?php foreach ($users as $pin => $user): ?>
        <?php
            $isActive = $user['active'] ?? true;
            $roleCls = 'role-' . ($user['role'] ?? 'courier');
            $roleLabel = $roles[$user['role']]['label'] ?? ucfirst($user['role'] ?? 'Courier');
            $roleIcon = $roleIcons[$user['role']] ?? '&#128100;';
            $vehicleIcon = $vehicleIcons[$user['vehicle_type'] ?? 'car'] ?? '&#128663;';
            $avail = $user['availability'] ?? 'offline';
        ?>
        <div class="courier-card <?= $isActive ? '' : 'inactive' ?>" data-pin="<?= $pin ?>" id="card-<?= $pin ?>">
            <div class="courier-card-header">
                <div class="courier-card-name">
                    <span class="courier-avail avail-<?= $avail ?>" title="<?= ucfirst($avail) ?>"></span>
                    <?= htmlspecialchars($user['name']) ?>
                    <span class="courier-card-pin"><?= $pin ?></span>
                </div>
                <span class="courier-role-badge <?= $roleCls ?>"><?= $roleIcon ?> <?= $roleLabel ?></span>
            </div>
            <div class="courier-card-body">
                <div class="courier-card-row">
                    <span class="courier-card-label">Vehicle</span>
                    <span class="courier-card-value"><?= $vehicleIcon ?> <?= ucfirst($user['vehicle_type'] ?? 'car') ?></span>
                </div>
                <?php if (!empty($user['phone'])): ?>
                <div class="courier-card-row">
                    <span class="courier-card-label">Phone</span>
                    <span class="courier-card-value"><?= htmlspecialchars($user['phone']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($user['email'])): ?>
                <div class="courier-card-row">
                    <span class="courier-card-label">Email</span>
                    <span class="courier-card-value"><?= htmlspecialchars($user['email']) ?></span>
                </div>
                <?php endif; ?>
                <div class="courier-card-row">
                    <span class="courier-card-label">Deliveries</span>
                    <span class="courier-card-value"><?= $user['total_deliveries'] ?? 0 ?></span>
                </div>
                <div class="courier-card-row">
                    <span class="courier-card-label">Earned</span>
                    <span class="courier-card-value">$<?= number_format($user['total_earned'] ?? 0, 2) ?></span>
                </div>
                <div class="courier-card-row">
                    <span class="courier-card-label">Since</span>
                    <span class="courier-card-value"><?= date('M j, Y', strtotime($user['created'] ?? 'now')) ?></span>
                </div>
                <?php if (!empty($user['notes'])): ?>
                <div class="courier-card-notes"><?= htmlspecialchars($user['notes']) ?></div>
                <?php endif; ?>
            </div>
            <div class="courier-card-footer">
                <button class="courier-action-btn" onclick="editCourier('<?= $pin ?>')">&#9998; Edit</button>
                <button class="courier-action-btn <?= $isActive ? 'toggle-active' : 'toggle-inactive' ?>"
                        onclick="toggleCourier('<?= $pin ?>', this)">
                    <?= $isActive ? '&#10003; Active' : '&#9888; Inactive' ?>
                </button>
                <button class="courier-action-btn danger" onclick="confirmDeleteCourier('<?= $pin ?>', '<?= htmlspecialchars(addslashes($user['name'])) ?>')">
                    &#128465; Delete
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($users)): ?>
    <div class="empty-state" style="margin-top: 40px;">
        <div class="empty-icon">&#128100;</div>
        <div class="empty-text">No couriers or staff configured</div>
        <div class="empty-subtext">Add couriers to start managing deliveries</div>
    </div>
    <?php endif; ?>

</div>

<!-- ============================================ -->
<!-- ADD / EDIT COURIER MODAL -->
<!-- ============================================ -->
<div class="courier-modal-overlay" id="courierModal" style="display:none;" onclick="closeCourierModal(event)">
    <div class="courier-modal" onclick="event.stopPropagation()">
        <div class="courier-modal-header">
            <h2 class="courier-modal-title" id="courierModalTitle">Add Courier</h2>
            <button class="courier-modal-close" onclick="closeCourierModal()">&times;</button>
        </div>
        <div class="courier-modal-body">
            <!-- PIN display (edit mode only) -->
            <div class="courier-pin-display" id="courierPinDisplay" style="display:none;">
                <div class="courier-pin-value" id="courierPinValue"></div>
                <div class="courier-pin-hint">Scanner PIN</div>
            </div>

            <input type="hidden" id="courierEditPin" value="">

            <div class="courier-form-row">
                <label class="courier-form-label">Name *</label>
                <input type="text" id="courierName" class="courier-form-input" placeholder="Full name">
            </div>

            <div class="courier-form-row-2col">
                <div class="courier-form-row">
                    <label class="courier-form-label">Role</label>
                    <select id="courierRole" class="courier-form-select">
                        <?php foreach ($roles as $key => $role): ?>
                        <option value="<?= $key ?>"><?= htmlspecialchars($role['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="courier-form-row">
                    <label class="courier-form-label">Vehicle</label>
                    <select id="courierVehicle" class="courier-form-select">
                        <option value="car">&#128663; Car</option>
                        <option value="van">&#128656; Van</option>
                        <option value="bicycle">&#128690; Bicycle</option>
                        <option value="motorcycle">&#127949; Motorcycle</option>
                        <option value="on_foot">&#128694; On Foot</option>
                    </select>
                </div>
            </div>

            <div class="courier-form-row-2col">
                <div class="courier-form-row">
                    <label class="courier-form-label">Phone</label>
                    <input type="tel" id="courierPhone" class="courier-form-input" placeholder="(optional)">
                </div>
                <div class="courier-form-row">
                    <label class="courier-form-label">Email</label>
                    <input type="email" id="courierEmail" class="courier-form-input" placeholder="(optional)">
                </div>
            </div>

            <div class="courier-form-row">
                <label class="courier-form-label">Notes</label>
                <input type="text" id="courierNotes" class="courier-form-input" placeholder="Optional notes">
            </div>
        </div>
        <div class="courier-modal-footer">
            <button class="courier-modal-btn courier-modal-cancel" onclick="closeCourierModal()">Cancel</button>
            <button class="courier-modal-btn courier-modal-save" id="courierSaveBtn" onclick="saveCourier()">Add Courier</button>
        </div>
    </div>
</div>

<!-- DELETE CONFIRMATION -->
<div class="delete-confirm-overlay" id="deleteConfirm" style="display:none;">
    <div class="delete-confirm-box">
        <div style="font-size:2rem;margin-bottom:12px;">&#128465;</div>
        <p>Delete <span class="delete-confirm-name" id="deleteConfirmName"></span>?</p>
        <p style="font-size:0.78rem;color:var(--subtext);">This action cannot be undone.</p>
        <div style="display:flex;gap:8px;justify-content:center;margin-top:16px;">
            <button class="courier-modal-btn courier-modal-cancel" onclick="closeDeleteConfirm()">Cancel</button>
            <button class="courier-modal-btn" style="background:#dc2626;color:#fff;" onclick="executeDelete()">Delete</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toast" class="dispatch-toast" style="display:none;"></div>

<script>
// Courier data for editing
var COURIER_DATA = <?= json_encode($users) ?>;
var pendingDeletePin = '';

function openCourierModal(editPin) {
    var modal = document.getElementById('courierModal');
    var pinDisplay = document.getElementById('courierPinDisplay');
    var title = document.getElementById('courierModalTitle');
    var saveBtn = document.getElementById('courierSaveBtn');

    // Reset form
    document.getElementById('courierEditPin').value = '';
    document.getElementById('courierName').value = '';
    document.getElementById('courierRole').value = 'courier';
    document.getElementById('courierVehicle').value = 'car';
    document.getElementById('courierPhone').value = '';
    document.getElementById('courierEmail').value = '';
    document.getElementById('courierNotes').value = '';

    if (editPin && COURIER_DATA[editPin]) {
        var c = COURIER_DATA[editPin];
        document.getElementById('courierEditPin').value = editPin;
        document.getElementById('courierName').value = c.name || '';
        document.getElementById('courierRole').value = c.role || 'courier';
        document.getElementById('courierVehicle').value = c.vehicle_type || 'car';
        document.getElementById('courierPhone').value = c.phone || '';
        document.getElementById('courierEmail').value = c.email || '';
        document.getElementById('courierNotes').value = c.notes || '';
        document.getElementById('courierPinValue').textContent = editPin;
        pinDisplay.style.display = 'block';
        title.textContent = 'Edit Courier';
        saveBtn.textContent = 'Save Changes';
    } else {
        pinDisplay.style.display = 'none';
        title.textContent = 'Add Courier';
        saveBtn.textContent = 'Add Courier';
    }

    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    setTimeout(function() { document.getElementById('courierName').focus(); }, 100);
}

function editCourier(pin) {
    openCourierModal(pin);
}

function closeCourierModal(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('courierModal').style.display = 'none';
    document.body.style.overflow = '';
}

function saveCourier() {
    var pin = document.getElementById('courierEditPin').value;
    var name = document.getElementById('courierName').value.trim();

    if (!name) {
        showToast('Name is required', 'error');
        return;
    }

    var btn = document.getElementById('courierSaveBtn');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    var formData = new FormData();
    formData.append('ajax_action', pin ? 'update_courier' : 'add_courier');
    if (pin) formData.append('pin', pin);
    formData.append('name', name);
    formData.append('role', document.getElementById('courierRole').value);
    formData.append('vehicle_type', document.getElementById('courierVehicle').value);
    formData.append('phone', document.getElementById('courierPhone').value);
    formData.append('email', document.getElementById('courierEmail').value);
    formData.append('notes', document.getElementById('courierNotes').value);

    fetch(window.location.pathname, { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        if (result.success) {
            var msg = pin ? 'Courier updated' : 'Courier added (PIN: ' + result.pin + ')';
            showToast(msg, 'success');
            closeCourierModal();
            setTimeout(function() { location.reload(); }, 600);
        } else {
            showToast(result.error || 'Failed to save', 'error');
            btn.disabled = false;
            btn.textContent = pin ? 'Save Changes' : 'Add Courier';
        }
    })
    .catch(function() {
        showToast('Network error', 'error');
        btn.disabled = false;
        btn.textContent = pin ? 'Save Changes' : 'Add Courier';
    });
}

function toggleCourier(pin, btn) {
    var formData = new FormData();
    formData.append('ajax_action', 'toggle_courier');
    formData.append('pin', pin);

    fetch(window.location.pathname, { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        if (result.success) {
            var card = document.getElementById('card-' + pin);
            if (result.active) {
                card.classList.remove('inactive');
                btn.className = 'courier-action-btn toggle-active';
                btn.innerHTML = '&#10003; Active';
            } else {
                card.classList.add('inactive');
                btn.className = 'courier-action-btn toggle-inactive';
                btn.innerHTML = '&#9888; Inactive';
            }
            showToast('Courier ' + (result.active ? 'activated' : 'deactivated'), 'success');
        } else {
            showToast(result.error || 'Failed to toggle', 'error');
        }
    })
    .catch(function() { showToast('Network error', 'error'); });
}

function confirmDeleteCourier(pin, name) {
    pendingDeletePin = pin;
    document.getElementById('deleteConfirmName').textContent = name;
    document.getElementById('deleteConfirm').style.display = 'flex';
}

function closeDeleteConfirm() {
    document.getElementById('deleteConfirm').style.display = 'none';
    pendingDeletePin = '';
}

function executeDelete() {
    if (!pendingDeletePin) return;

    var formData = new FormData();
    formData.append('ajax_action', 'delete_courier');
    formData.append('pin', pendingDeletePin);

    fetch(window.location.pathname, { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        closeDeleteConfirm();
        if (result.success) {
            var card = document.getElementById('card-' + pendingDeletePin);
            if (card) {
                card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                card.style.opacity = '0';
                card.style.transform = 'scale(0.95)';
                setTimeout(function() { card.remove(); }, 300);
            }
            showToast('Courier deleted', 'success');
        } else {
            showToast(result.error || 'Failed to delete', 'error');
        }
    })
    .catch(function() {
        closeDeleteConfirm();
        showToast('Network error', 'error');
    });
}

function showToast(message, type) {
    var toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = 'dispatch-toast ' + (type || '');
    toast.style.display = 'block';
    setTimeout(function() { toast.style.display = 'none'; }, 3000);
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (document.getElementById('deleteConfirm').style.display !== 'none') {
            closeDeleteConfirm();
        } else if (document.getElementById('courierModal').style.display !== 'none') {
            closeCourierModal();
        }
    }
});
</script>

</body>
</html>
