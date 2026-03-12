<?php
/**
 * Vendor Management
 * Standalone vendor accounts page
 * Location: /admin/vendors.php
 */

require_once __DIR__ . '/../admin-auth.php';
requireAnyPermission(['preflight_edit', 'preflight_view', 'orders_edit']);

$canEdit = hasPermission('preflight_edit') || hasPermission('orders_edit');
$basePath = __DIR__ . '/../';

// Load icons
require_once $basePath . 'includes/icons.php';

// Load vendor functions
require_once $basePath . 'includes/vendor-functions.php';

$vendorsFile = $basePath . 'data/vendors.json';

if (!defined('MAX_VENDORS')) define('MAX_VENDORS', 10);

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $result = ['success' => false, 'error' => 'Unknown action'];
    
    switch ($action) {
        case 'add_vendor':
            $result = addVendor($_POST, $vendorsFile);
            break;
        case 'update_vendor':
            $result = updateVendor($_POST, $vendorsFile);
            break;
        case 'delete_vendor':
            $result = deleteVendor($_POST['vendor_id'], $vendorsFile);
            break;
        case 'toggle_vendor_status':
            $result = toggleVendorStatus($_POST['vendor_id'], $vendorsFile);
            break;
        case 'set_default_vendor':
            $result = setDefaultVendor($_POST['vendor_id'], $vendorsFile);
            break;
    }
    
    echo json_encode($result);
    exit;
}

// Load data
$vendorData = loadVendors($vendorsFile);
$vendors = $vendorData['vendors'] ?? [];
$defaultVendorId = $vendorData['settings']['default_vendor_id'] ?? null;
$activeVendors = array_filter($vendors, fn($v) => $v['active']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendors - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin-base.css">
    <link rel="stylesheet" href="../css/admin-components.css">
    <link rel="stylesheet" href="../css/admin-layout.css">
    <link rel="stylesheet" href="production-styles.css">
    <link rel="stylesheet" href="../css/admin-sidebar.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('production_vendors'); ?>
<script src="../js/admin-sidebar.js"></script>
<div class="production-content">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-left">
            <h1 class="page-title"><?= ICON_USERS ?> Vendor Management</h1>
            <div class="page-welcome">
                <span class="welcome-text"><?= count($activeVendors) ?> active of <?= count($vendors) ?> vendors (max <?= MAX_VENDORS ?>)</span>
            </div>
        </div>
    </div>

    <div class="preflight-container">
        <div class="vendors-grid">
            <?php foreach ($vendors as $vendor): ?>
            <div class="vendor-card <?= $vendor['active'] ? '' : 'inactive' ?> <?= $vendor['id'] === $defaultVendorId ? 'is-default' : '' ?>" data-vendor-id="<?= htmlspecialchars($vendor['id']) ?>">
                <div class="vendor-card-header">
                    <div class="vendor-header-left">
                        <div class="vendor-name"><?= htmlspecialchars($vendor['business_name']) ?></div>
                        <?php if ($vendor['id'] === $defaultVendorId): ?>
                        <span class="default-badge"><?= ICON_STAR ?> Default</span>
                        <?php endif; ?>
                    </div>
                    <span class="vendor-status <?= $vendor['active'] ? 'active' : 'inactive' ?>">
                        <?= $vendor['active'] ? '● Active' : '○ Inactive' ?>
                    </span>
                </div>
                <div class="vendor-card-body">
                    <div class="vendor-info">
                        <?php if (!empty($vendor['contact_name'])): ?>
                        <div class="vendor-info-row">
                            <span class="icon"><?= ICON_USER ?></span>
                            <span class="value"><?= htmlspecialchars($vendor['contact_name']) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="vendor-info-row">
                            <span class="icon"><?= ICON_ENVELOPE ?></span>
                            <span class="value">
                                <a href="mailto:<?= htmlspecialchars($vendor['email']) ?>"><?= htmlspecialchars($vendor['email']) ?></a>
                            </span>
                        </div>
                        <?php if (!empty($vendor['phone'])): ?>
                        <div class="vendor-info-row">
                            <span class="icon"><?= ICON_PAYMENT_LINK ?></span>
                            <span class="value"><?= htmlspecialchars($vendor['phone']) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="vendor-info-row">
                            <span class="icon">&#128273;</span>
                            <span class="value" style="font-size: 0.8rem;">
                                <?php if (!empty($vendor['pin'])): ?>
                                    <span style="color: #059669; font-weight: 600;">PIN Set</span>
                                    <span style="color: #9ca3af; font-family: monospace; margin-left: 4px;">(<?= htmlspecialchars($vendor['pin']) ?>)</span>
                                <?php else: ?>
                                    <span style="color: #dc2626; font-weight: 600;">No PIN</span>
                                    <span style="color: #9ca3af; margin-left: 4px;">&mdash; portal login disabled</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php if ($canEdit): ?>
                <div class="vendor-card-footer">
                    <button class="btn btn-edit" onclick="editVendor('<?= $vendor['id'] ?>')">
                        <?= ICON_PENCIL ?> Edit
                    </button>
                    <button class="btn btn-toggle" onclick="toggleVendor('<?= $vendor['id'] ?>')">
                        <?= $vendor['active'] ? ICON_STOP . ' Deactivate' : ICON_CHECK_GREEN . ' Activate' ?>
                    </button>
                    <?php if ($vendor['id'] !== $defaultVendorId && $vendor['active']): ?>
                    <button class="btn btn-default" onclick="setDefault('<?= $vendor['id'] ?>')"><?= ICON_STAR ?></button>
                    <?php endif; ?>
                    <button class="btn btn-delete" onclick="deleteVendor('<?= $vendor['id'] ?>', '<?= htmlspecialchars(addslashes($vendor['business_name'])) ?>')"><?= ICON_TRASH ?></button>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            
            <?php if ($canEdit && count($vendors) < MAX_VENDORS): ?>
            <div class="vendor-card add-vendor-card" onclick="openAddVendorModal()">
                <div class="plus-icon">+</div>
                <div class="add-text">Add New Vendor</div>
                <div class="max-text"><?= count($vendors) ?> of <?= MAX_VENDORS ?> vendors</div>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Add/Edit Vendor Modal -->
<div class="modal-overlay" id="vendorModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" id="modalTitle">Add New Vendor</div>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form id="vendorForm" onsubmit="saveVendor(event)">
            <input type="hidden" id="vendorId" name="vendor_id" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label>Business Name <span class="required">*</span></label>
                    <input type="text" id="businessName" name="business_name" required>
                </div>
                <div class="form-group">
                    <label>Contact Name</label>
                    <input type="text" id="contactName" name="contact_name">
                </div>
                <div class="form-group">
                    <label>Email Address <span class="required">*</span></label>
                    <input type="email" id="vendorEmail" name="email" required>
                </div>
                <div class="form-group">
                    <label>CC Email (Optional)</label>
                    <input type="email" id="vendorEmailCc" name="email_cc">
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" id="vendorPhone" name="phone">
                </div>
                <div class="form-group">
                    <label>Portal PIN</label>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <input type="text" id="vendorPin" name="pin" maxlength="6" inputmode="numeric" pattern="[0-9]*" placeholder="Auto-generated if blank" style="font-family: monospace; font-size: 1.1rem; letter-spacing: 4px; max-width: 180px;">
                        <button type="button" class="btn btn-edit" onclick="generatePin()" style="white-space: nowrap; padding: 8px 12px; font-size: 0.75rem;">&#x1F3B2; Generate</button>
                    </div>
                    <div style="font-size: 0.75rem; color: #6b7280; margin-top: 4px;">6-digit PIN for vendor portal login. Leave blank to auto-generate.</div>
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <textarea id="vendorAddress" name="address"></textarea>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea id="vendorNotes" name="notes"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-save" id="saveBtn">Add Vendor</button>
            </div>
        </form>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
    const vendorData = <?= json_encode($vendors) ?>;
    
    function openAddVendorModal() {
        document.getElementById('modalTitle').textContent = 'Add New Vendor';
        document.getElementById('saveBtn').textContent = 'Add Vendor';
        document.getElementById('vendorForm').reset();
        document.getElementById('vendorId').value = '';
        document.getElementById('vendorPin').placeholder = 'Auto-generated if blank';
        document.getElementById('vendorModal').classList.add('show');
    }
    
    function editVendor(vendorId) {
        const vendor = vendorData.find(v => v.id === vendorId);
        if (!vendor) return;
        document.getElementById('modalTitle').textContent = 'Edit Vendor';
        document.getElementById('saveBtn').textContent = 'Save Changes';
        document.getElementById('vendorId').value = vendor.id;
        document.getElementById('businessName').value = vendor.business_name || '';
        document.getElementById('contactName').value = vendor.contact_name || '';
        document.getElementById('vendorEmail').value = vendor.email || '';
        document.getElementById('vendorEmailCc').value = vendor.email_cc || '';
        document.getElementById('vendorPhone').value = vendor.phone || '';
        document.getElementById('vendorPin').value = vendor.pin || '';
        document.getElementById('vendorPin').placeholder = vendor.pin ? 'Leave blank to keep current' : 'No PIN set';
        document.getElementById('vendorAddress').value = vendor.address || '';
        document.getElementById('vendorNotes').value = vendor.notes || '';
        document.getElementById('vendorModal').classList.add('show');
    }
    
    function generatePin() {
        const pin = String(Math.floor(Math.random() * 1000000)).padStart(6, '0');
        document.getElementById('vendorPin').value = pin;
    }
    
    function closeModal() {
        document.getElementById('vendorModal').classList.remove('show');
    }
    
    async function saveVendor(event) {
        event.preventDefault();
        const vendorId = document.getElementById('vendorId').value;
        const action = vendorId ? 'update_vendor' : 'add_vendor';
        const formData = new FormData(document.getElementById('vendorForm'));
        formData.append('ajax_action', action);
        
        try {
            const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
            const result = await response.json();
            if (result.success) {
                showToast(result.message, 'success');
                closeModal();
                setTimeout(() => location.reload(), 500);
            } else {
                showToast(result.error || 'Failed', 'error');
            }
        } catch (error) {
            showToast('An error occurred', 'error');
        }
    }
    
    async function toggleVendor(vendorId) {
        const formData = new FormData();
        formData.append('ajax_action', 'toggle_vendor_status');
        formData.append('vendor_id', vendorId);
        try {
            const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
            const result = await response.json();
            showToast(result.message || (result.success ? 'Updated' : 'Failed'), result.success ? 'success' : 'error');
            if (result.success) setTimeout(() => location.reload(), 500);
        } catch (error) { showToast('Error', 'error'); }
    }
    
    async function setDefault(vendorId) {
        const formData = new FormData();
        formData.append('ajax_action', 'set_default_vendor');
        formData.append('vendor_id', vendorId);
        try {
            const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
            const result = await response.json();
            showToast(result.message || 'Updated', result.success ? 'success' : 'error');
            if (result.success) setTimeout(() => location.reload(), 500);
        } catch (error) { showToast('Error', 'error'); }
    }
    
    async function deleteVendor(vendorId, vendorName) {
        if (!confirm(`Delete "${vendorName}"?`)) return;
        const formData = new FormData();
        formData.append('ajax_action', 'delete_vendor');
        formData.append('vendor_id', vendorId);
        try {
            const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
            const result = await response.json();
            showToast(result.message || 'Deleted', result.success ? 'success' : 'error');
            if (result.success) setTimeout(() => location.reload(), 500);
        } catch (error) { showToast('Error', 'error'); }
    }
    
    function showToast(message, type = 'success') {
        const toast = document.getElementById('toast');
        toast.textContent = message;
        toast.className = `toast ${type} show`;
        setTimeout(() => toast.classList.remove('show'), 3000);
    }
    
    // Modal close handlers
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('show');
        });
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.show').forEach(m => m.classList.remove('show'));
    });
</script>

</body>
</html>
