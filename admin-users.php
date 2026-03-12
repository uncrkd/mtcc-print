<?php
/**
 * Admin User Management
 * Manage admin users, roles, and permissions
 * Only accessible by God Mode and Super Admin (with user_management permission)
 */
require_once 'admin-auth.php';
require_once 'includes/icons.php';
requirePermission('user_management');

$adminData = loadAdminUsers();
$message = '';
$messageType = '';
$editingUser = null;
$editingRole = null;

// ============================================
// Handle Form Submissions
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Add New User
    if (isset($_POST['add_user'])) {
        $username = strtolower(trim($_POST['username'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'staff';
        $must_change_password = isset($_POST['must_change_password']);
        
        // Custom permissions
        $custom_permissions = null;
        if ($role === 'custom' && isset($_POST['permissions'])) {
            $custom_permissions = $_POST['permissions'];
            $role = 'custom';
        }
        
        // Validation
        if (empty($username) || empty($name) || empty($password)) {
            $message = 'Username, name, and password are required';
            $messageType = 'error';
        } elseif (!preg_match('/^[a-z0-9_]+$/', $username)) {
            $message = 'Username can only contain lowercase letters, numbers, and underscores';
            $messageType = 'error';
        } elseif (isset($adminData['users'][$username])) {
            $message = 'Username already exists';
            $messageType = 'error';
        } else {
            $passwordErrors = validatePassword($password);
            if (!empty($passwordErrors)) {
                $message = implode('. ', $passwordErrors);
                $messageType = 'error';
            } else {
                $adminData['users'][$username] = [
                    'name' => $name,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $role,
                    'custom_permissions' => $custom_permissions,
                    'active' => true,
                    'must_change_password' => $must_change_password,
                    'created_at' => date('c'),
                    'created_by' => getCurrentAdminUsername(),
                    'last_login' => null,
                    'login_count' => 0
                ];
                saveAdminUsers($adminData);
                logAdminActivity('User Created', ['new_user' => $username, 'role' => $role]);
                $message = "User '$username' created successfully";
                $messageType = 'success';
            }
        }
    }
    
    // Edit User
    if (isset($_POST['edit_user'])) {
        $username = $_POST['edit_username'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $role = $_POST['role'] ?? 'staff';
        $active = isset($_POST['active']);
        
        $custom_permissions = null;
        if ($role === 'custom' && isset($_POST['permissions'])) {
            $custom_permissions = $_POST['permissions'];
        }
        
        if (isset($adminData['users'][$username])) {
            $oldRole = $adminData['users'][$username]['role'];
            $adminData['users'][$username]['name'] = $name;
            $adminData['users'][$username]['role'] = $role;
            $adminData['users'][$username]['custom_permissions'] = $custom_permissions;
            $adminData['users'][$username]['active'] = $active;
            
            saveAdminUsers($adminData);
            logAdminActivity('User Edited', ['user' => $username, 'old_role' => $oldRole, 'new_role' => $role]);
            $message = "User '$username' updated successfully";
            $messageType = 'success';
        }
    }
    
    // Reset Password (Direct)
    if (isset($_POST['reset_password_direct'])) {
        $username = $_POST['reset_username'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        
        if (isset($adminData['users'][$username]) && !empty($new_password)) {
            $passwordErrors = validatePassword($new_password);
            if (!empty($passwordErrors)) {
                $message = implode('. ', $passwordErrors);
                $messageType = 'error';
            } else {
                $adminData['users'][$username]['password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
                $adminData['users'][$username]['must_change_password'] = false;
                saveAdminUsers($adminData);
                logAdminActivity('Password Reset (Direct)', ['user' => $username]);
                $message = "Password for '$username' has been reset";
                $messageType = 'success';
            }
        }
    }
    
    // Reset Password (Temporary)
    if (isset($_POST['reset_password_temp'])) {
        $username = $_POST['reset_username'] ?? '';
        $temp_password = $_POST['temp_password'] ?? '';
        
        if (isset($adminData['users'][$username]) && !empty($temp_password)) {
            $passwordErrors = validatePassword($temp_password);
            if (!empty($passwordErrors)) {
                $message = implode('. ', $passwordErrors);
                $messageType = 'error';
            } else {
                $adminData['users'][$username]['password_hash'] = password_hash($temp_password, PASSWORD_DEFAULT);
                $adminData['users'][$username]['must_change_password'] = true;
                saveAdminUsers($adminData);
                logAdminActivity('Password Reset (Temporary)', ['user' => $username]);
                $message = "Temporary password set for '$username'. They will be required to change it on next login.";
                $messageType = 'success';
            }
        }
    }
    
    // Delete User
    if (isset($_POST['delete_user'])) {
        $username = $_POST['delete_username'] ?? '';
        
        if ($username === getCurrentAdminUsername()) {
            $message = "You cannot delete your own account";
            $messageType = 'error';
        } elseif (isset($adminData['users'][$username])) {
            $deletedName = $adminData['users'][$username]['name'];
            unset($adminData['users'][$username]);
            saveAdminUsers($adminData);
            logAdminActivity('User Deleted', ['deleted_user' => $username, 'name' => $deletedName]);
            $message = "User '$username' has been deleted";
            $messageType = 'success';
        }
    }
    
    // Edit Role (God Mode only)
    if (isset($_POST['edit_role']) && canManageRoles()) {
        $roleKey = $_POST['role_key'] ?? '';
        $roleLabel = trim($_POST['role_label'] ?? '');
        $roleDescription = trim($_POST['role_description'] ?? '');
        $roleTracked = isset($_POST['role_tracked']);
        $rolePermissions = $_POST['role_permissions'] ?? [];
        
        if (isset($adminData['roles'][$roleKey]) && !$adminData['roles'][$roleKey]['is_system_role']) {
            $adminData['roles'][$roleKey]['label'] = $roleLabel;
            $adminData['roles'][$roleKey]['description'] = $roleDescription;
            $adminData['roles'][$roleKey]['tracked'] = $roleTracked;
            $adminData['roles'][$roleKey]['permissions'] = $rolePermissions;
            saveAdminUsers($adminData);
            logAdminActivity('Role Edited', ['role' => $roleKey]);
            $message = "Role '$roleLabel' updated successfully";
            $messageType = 'success';
        } elseif ($adminData['roles'][$roleKey]['is_system_role'] ?? false) {
            $message = "System roles cannot be edited";
            $messageType = 'error';
        }
    }
    
    // Create New Role (God Mode only)
    if (isset($_POST['create_role']) && canManageRoles()) {
        $roleKey = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $_POST['new_role_key'] ?? '')));
        $roleLabel = trim($_POST['new_role_label'] ?? '');
        $roleDescription = trim($_POST['new_role_description'] ?? '');
        $roleTracked = isset($_POST['new_role_tracked']);
        $rolePermissions = $_POST['new_role_permissions'] ?? [];
        
        if (empty($roleKey) || empty($roleLabel)) {
            $message = 'Role key and label are required';
            $messageType = 'error';
        } elseif (isset($adminData['roles'][$roleKey])) {
            $message = 'A role with this key already exists';
            $messageType = 'error';
        } else {
            $adminData['roles'][$roleKey] = [
                'label' => $roleLabel,
                'description' => $roleDescription,
                'tracked' => $roleTracked,
                'is_system_role' => false,
                'permissions' => $rolePermissions
            ];
            saveAdminUsers($adminData);
            logAdminActivity('Role Created', ['role' => $roleKey, 'label' => $roleLabel]);
            $message = "Role '$roleLabel' created successfully";
            $messageType = 'success';
        }
    }
    
    // Delete Role (God Mode only)
    if (isset($_POST['delete_role']) && canManageRoles()) {
        $roleKey = $_POST['delete_role_key'] ?? '';
        
        if (isset($adminData['roles'][$roleKey])) {
            if ($adminData['roles'][$roleKey]['is_system_role'] ?? false) {
                $message = "System roles cannot be deleted";
                $messageType = 'error';
            } else {
                // Check if any users have this role
                $usersWithRole = array_filter($adminData['users'], function($u) use ($roleKey) {
                    return $u['role'] === $roleKey;
                });
                
                if (!empty($usersWithRole)) {
                    $message = "Cannot delete role: " . count($usersWithRole) . " user(s) are assigned to this role";
                    $messageType = 'error';
                } else {
                    $deletedLabel = $adminData['roles'][$roleKey]['label'];
                    unset($adminData['roles'][$roleKey]);
                    saveAdminUsers($adminData);
                    logAdminActivity('Role Deleted', ['role' => $roleKey, 'label' => $deletedLabel]);
                    $message = "Role '$deletedLabel' has been deleted";
                    $messageType = 'success';
                }
            }
        }
    }
    
    // Reload data after changes
    $adminData = loadAdminUsers();
}

// Check for edit mode
if (isset($_GET['edit_user'])) {
    $editUsername = $_GET['edit_user'];
    if (isset($adminData['users'][$editUsername])) {
        $editingUser = $adminData['users'][$editUsername];
        $editingUser['username'] = $editUsername;
    }
}

if (isset($_GET['edit_role']) && canManageRoles()) {
    $editRoleKey = $_GET['edit_role'];
    if (isset($adminData['roles'][$editRoleKey])) {
        $editingRole = $adminData['roles'][$editRoleKey];
        $editingRole['key'] = $editRoleKey;
    }
}

$activeTab = $_GET['tab'] ?? 'users';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-layout.css">
    <style>
        :root {
            --primary: #7c3aed;
            --primary-dark: #6d28d9;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --radius: 12px;
            --radius-sm: 8px;
            --shadow: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Montserrat', sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            line-height: 1.5;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 0;
        }
        
        /* Page Header */
        .page-header {
            background: linear-gradient(90deg, rgba(64, 0, 128, 1) 0%, rgba(115, 0, 196, 1) 100%);
            color: white;
            padding: 12px 24px;
            border-radius: var(--radius);
            margin-bottom: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-md);
        }
        
        /* Card that attaches directly below page-header */
        .card-attached {
            border-radius: 0 0 var(--radius) var(--radius);
        }
        
        .page-header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
        }
        
        .page-subtitle {
            opacity: 0.85;
            font-size: 0.9rem;
            padding-left: 16px;
            border-left: 1px solid rgba(255,255,255,0.3);
        }
        
        .page-header-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .header-tab {
            padding: 8px 16px;
            background: rgba(255,255,255,0.15);
            color: white;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: 6px;
            transition: all 0.2s;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .header-tab:hover {
            background: rgba(255,255,255,0.25);
            color: white;
        }
        
        .header-tab.active {
            background: white;
            color: var(--primary);
            font-weight: 600;
            border-color: white;
        }
        
        .user-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            background: white;
            padding: 8px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        
        .tab-btn {
            padding: 12px 24px;
            border: none;
            background: transparent;
            font-family: inherit;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--gray-500);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .tab-btn:hover {
            background: var(--gray-100);
            color: var(--gray-700);
        }
        
        .tab-btn.active {
            background: var(--primary);
            color: white;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 24px;
            overflow: hidden;
        }
        
        .card-header {
            background: var(--gray-50);
            padding: 16px 24px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray-700);
        }
        
        .card-body {
            padding: 24px;
        }
        
        /* Messages */
        .message {
            padding: 14px 20px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .message.success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .message.error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th,
        .data-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .data-table th {
            background: var(--gray-50);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            color: var(--gray-500);
        }
        
        .data-table tr:hover {
            background: var(--gray-50);
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Status badges */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #ede9fe;
            color: #5b21b6;
        }
        
        .role-badge.god-mode {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: #78350f;
        }
        
        .role-badge.super-admin {
            background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
            color: white;
        }
        
        /* Forms */
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 6px;
        }
        
        .form-input,
        .form-select {
            width: 100%;
            padding: 10px 14px;
            font-family: inherit;
            font-size: 0.9rem;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-sm);
            transition: all 0.2s;
        }
        
        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }
        
        .form-hint {
            font-size: 0.8rem;
            color: var(--gray-500);
            margin-top: 4px;
        }
        
        /* Checkboxes */
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 12px;
            padding: 16px;
            background: var(--gray-50);
            border-radius: var(--radius-sm);
        }
        
        .checkbox-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        
        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            accent-color: var(--primary);
        }
        
        .checkbox-label {
            font-size: 0.85rem;
            color: var(--gray-700);
        }
        
        .checkbox-label small {
            display: block;
            color: var(--gray-500);
            font-size: 0.75rem;
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            font-family: inherit;
            font-size: 0.9rem;
            font-weight: 600;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background: #059669;
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background: #dc2626;
        }
        
        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }
        
        .btn-secondary:hover {
            background: var(--gray-300);
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.8rem;
        }
        
        .btn-group {
            display: flex;
            gap: 8px;
        }
        
        /* Action buttons in table */
        .action-btn {
            padding: 6px 12px;
            font-size: 0.8rem;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
            margin-right: 4px;
        }
        
        .action-edit {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .action-edit:hover {
            background: #bfdbfe;
        }
        
        .action-delete {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .action-delete:hover {
            background: #fecaca;
        }
        
        .action-reset {
            background: #fef3c7;
            color: #92400e;
        }
        
        .action-reset:hover {
            background: #fde68a;
        }
        
        /* Modal */
        .modal-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-backdrop.show {
            display: flex;
        }
        
        .modal {
            background: white;
            border-radius: var(--radius);
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px rgba(0,0,0,0.25);
        }
        
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray-400);
        }
        
        .modal-close:hover {
            color: var(--gray-600);
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        /* Permission categories */
        .permission-category {
            margin-bottom: 20px;
        }
        
        .permission-category-title {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--gray-500);
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--gray-200);
        }
        
        /* Role cards */
        .role-card {
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            padding: 16px;
            margin-bottom: 12px;
        }
        
        .role-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        
        .role-card-title {
            font-weight: 600;
            color: var(--gray-800);
        }
        
        .role-card-description {
            font-size: 0.85rem;
            color: var(--gray-500);
            margin-bottom: 12px;
        }
        
        .role-card-permissions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        
        .perm-tag {
            background: white;
            border: 1px solid var(--gray-200);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            color: var(--gray-600);
        }
        
        .system-badge {
            background: var(--warning);
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .checkbox-group {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-wrap: wrap;
            }
            
        }
    </style>
<link rel="stylesheet" href="css/admin-sidebar.css">
</head>
<body>
<?php require_once __DIR__ . '/includes/admin-sidebar.php'; renderSidebar('users'); ?>
<script src="admin-sidebar.js"></script>
<div class="container">
    <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-left">
                <h1 class="page-title">User Management</h1>
                <div class="page-welcome">
                    <span class="welcome-text">Manage admin users, roles, and permissions</span>
                    <span class="welcome-date">Today is <?= date('l, F j, Y') ?></span>
                </div>
                </div>
               <div class="page-header-right">
            <a href="?tab=users" class="header-tab <?= $activeTab === 'users' ? 'active' : '' ?>">Users</a>
            <?php if (canManageRoles()): ?>
            <a href="?tab=roles" class="header-tab <?= $activeTab === 'roles' ? 'active' : '' ?>">Roles</a>
            <?php endif; ?>
        </div>
            
        </div>
    
    <?php if ($message): ?>
    <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    
    <?php if ($activeTab === 'users'): ?>
    <!-- Users List -->
    <div class="card card-attached">
        <div class="card-header">
            <h2 class="card-title">All Users (<?= count($adminData['users']) ?>)</h2>
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('addUserModal').classList.add('show')"><?= ICON_PLUS ?> Add User</button>
        </div>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($adminData['users'] as $username => $user): 
                        $roleData = $adminData['roles'][$user['role']] ?? null;
                        $roleLabel = $roleData['label'] ?? ucfirst($user['role']);
                        $roleBadgeClass = '';
                        if ($user['role'] === 'god_mode') $roleBadgeClass = 'god-mode';
                        elseif ($user['role'] === 'super_admin') $roleBadgeClass = 'super-admin';
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($username) ?></strong></td>
                        <td><?= htmlspecialchars($user['name']) ?></td>
                        <td><span class="role-badge <?= $roleBadgeClass ?>"><?= htmlspecialchars($roleLabel) ?></span></td>
                        <td>
                            <span class="status-badge <?= $user['active'] ? 'status-active' : 'status-inactive' ?>">
                                <?= $user['active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($user['last_login']): ?>
                                <?= date('M j, Y g:i A', strtotime($user['last_login'])) ?>
                            <?php else: ?>
                                <span style="color: var(--gray-400);">Never</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="?tab=users&edit_user=<?= urlencode($username) ?>" class="action-btn action-edit">Edit</a>
                            <button type="button" class="action-btn action-reset" onclick="showResetModal('<?= htmlspecialchars($username) ?>', '<?= htmlspecialchars($user['name']) ?>')">Reset PW</button>
                            <?php if ($username !== getCurrentAdminUsername()): ?>
                            <button type="button" class="action-btn action-delete" onclick="confirmDelete('<?= htmlspecialchars($username) ?>', '<?= htmlspecialchars($user['name']) ?>')">Delete</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php if ($editingUser): ?>
    <!-- Edit User Form -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Edit User: <?= htmlspecialchars($editingUser['username']) ?></h2>
            <a href="?tab=users" class="btn btn-secondary btn-sm">Cancel</a>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="edit_user" value="1">
                <input type="hidden" name="edit_username" value="<?= htmlspecialchars($editingUser['username']) ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-input" value="<?= htmlspecialchars($editingUser['username']) ?>" disabled>
                        <div class="form-hint">Username cannot be changed</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Display Name</label>
                        <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($editingUser['name']) ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select" id="editRoleSelect" onchange="toggleEditPermissions()">
                            <?php foreach ($adminData['roles'] as $roleKey => $role): ?>
                            <option value="<?= $roleKey ?>" <?= $editingUser['role'] === $roleKey ? 'selected' : '' ?>>
                                <?= htmlspecialchars($role['label']) ?>
                            </option>
                            <?php endforeach; ?>
                            <option value="custom" <?= $editingUser['role'] === 'custom' ? 'selected' : '' ?>>Custom Permissions</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <div style="padding-top: 8px;">
                            <label class="checkbox-item" style="display: inline-flex;">
                                <input type="checkbox" name="active" <?= $editingUser['active'] ? 'checked' : '' ?>>
                                <span class="checkbox-label">Active</span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="form-group" id="editPermissionsGroup" style="display: <?= $editingUser['role'] === 'custom' ? 'block' : 'none' ?>;">
                    <label class="form-label">Custom Permissions</label>
                    <div class="checkbox-group">
                        <?php 
                        $currentPerms = $editingUser['custom_permissions'] ?? [];
                        foreach ($adminData['permissions_catalog'] as $permKey => $perm): 
                        ?>
                        <label class="checkbox-item">
                            <input type="checkbox" name="permissions[]" value="<?= $permKey ?>" <?= in_array($permKey, $currentPerms) ? 'checked' : '' ?>>
                            <span class="checkbox-label">
                                <?= htmlspecialchars($perm['label']) ?>
                                <small><?= htmlspecialchars($perm['description']) ?></small>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary"><?= ICON_CHECK_GREEN ?> Save Changes</button>
                    <a href="?tab=users" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <?php elseif ($activeTab === 'roles' && canManageRoles()): ?>
    <!-- Roles Management -->
    <div class="card card-attached">
        <div class="card-header">
            <h2 class="card-title">Role Definitions</h2>
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('newRoleModal').classList.add('show')"><?= ICON_PLUS ?> Create Role</button>
        </div>
        <div class="card-body">
            <?php foreach ($adminData['roles'] as $roleKey => $role): ?>
            <div class="role-card">
                <div class="role-card-header">
                    <div>
                        <span class="role-card-title"><?= htmlspecialchars($role['label']) ?></span>
                        <?php if ($role['is_system_role'] ?? false): ?>
                        <span class="system-badge">System</span>
                        <?php endif; ?>
                        <?php if (!($role['tracked'] ?? true)): ?>
                        <span class="system-badge" style="background: var(--gray-500);">Not Tracked</span>
                        <?php endif; ?>
                    </div>
                    <div class="btn-group">
                        <?php if (!($role['is_system_role'] ?? false)): ?>
                        <a href="?tab=roles&edit_role=<?= urlencode($roleKey) ?>" class="btn btn-secondary btn-sm">Edit</a>
                        <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteRole('<?= $roleKey ?>', '<?= htmlspecialchars($role['label']) ?>')">Delete</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="role-card-description"><?= htmlspecialchars($role['description']) ?></div>
                <div class="role-card-permissions">
                    <?php foreach ($role['permissions'] as $perm): 
                        $permLabel = $adminData['permissions_catalog'][$perm]['label'] ?? $perm;
                    ?>
                    <span class="perm-tag"><?= htmlspecialchars($permLabel) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <?php if ($editingRole): ?>
    <!-- Edit Role Form -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Edit Role: <?= htmlspecialchars($editingRole['label']) ?></h2>
            <a href="?tab=roles" class="btn btn-secondary btn-sm">Cancel</a>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="edit_role" value="1">
                <input type="hidden" name="role_key" value="<?= htmlspecialchars($editingRole['key']) ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Role Label</label>
                        <input type="text" name="role_label" class="form-input" value="<?= htmlspecialchars($editingRole['label']) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Activity Tracking</label>
                        <div style="padding-top: 8px;">
                            <label class="checkbox-item" style="display: inline-flex;">
                                <input type="checkbox" name="role_tracked" <?= ($editingRole['tracked'] ?? true) ? 'checked' : '' ?>>
                                <span class="checkbox-label">Track actions in Activity Log</span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <input type="text" name="role_description" class="form-input" value="<?= htmlspecialchars($editingRole['description']) ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Permissions</label>
                    <div class="checkbox-group">
                        <?php foreach ($adminData['permissions_catalog'] as $permKey => $perm): ?>
                        <label class="checkbox-item">
                            <input type="checkbox" name="role_permissions[]" value="<?= $permKey ?>" <?= in_array($permKey, $editingRole['permissions']) ? 'checked' : '' ?>>
                            <span class="checkbox-label">
                                <?= htmlspecialchars($perm['label']) ?>
                                <small><?= htmlspecialchars($perm['description']) ?></small>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary"><?= ICON_CHECK_GREEN ?> Save Changes</button>
                    <a href="?tab=roles" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- New Role Modal -->
    <div class="modal-backdrop" id="newRoleModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Create New Role</h3>
                <button type="button" class="modal-close" onclick="document.getElementById('newRoleModal').classList.remove('show')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="create_role" value="1">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Role Key</label>
                        <input type="text" name="new_role_key" class="form-input" placeholder="e.g., print_operator" required pattern="[a-z0-9_]+">
                        <div class="form-hint">Lowercase letters, numbers, underscores only</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Role Label</label>
                        <input type="text" name="new_role_label" class="form-input" placeholder="e.g., Print Operator" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <input type="text" name="new_role_description" class="form-input" placeholder="What this role can do">
                    </div>
                    
                    <div class="form-group">
                        <label class="checkbox-item">
                            <input type="checkbox" name="new_role_tracked" checked>
                            <span class="checkbox-label">Track actions in Activity Log</span>
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Permissions</label>
                        <div class="checkbox-group">
                            <?php foreach ($adminData['permissions_catalog'] as $permKey => $perm): ?>
                            <label class="checkbox-item">
                                <input type="checkbox" name="new_role_permissions[]" value="<?= $permKey ?>">
                                <span class="checkbox-label"><?= htmlspecialchars($perm['label']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('newRoleModal').classList.remove('show')">Cancel</button>
                    <button type="submit" class="btn btn-success">Create Role</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Add User Modal -->
<div class="modal-backdrop" id="addUserModal">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title"><?= ICON_PLUS ?> Add New User</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('addUserModal').classList.remove('show')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="add_user" value="1">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Username *</label>
                        <input type="text" name="username" class="form-input" placeholder="johndoe" required pattern="[a-z0-9_]+">
                        <div class="form-hint">Lowercase letters, numbers, and underscores only</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Display Name *</label>
                        <input type="text" name="name" class="form-input" placeholder="John Doe" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Password *</label>
                        <input type="password" name="password" class="form-input" required>
                        <div class="form-hint">Min 8 chars, uppercase, lowercase, number</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select" id="addRoleSelect" onchange="toggleAddPermissions()">
                            <?php foreach ($adminData['roles'] as $roleKey => $role): 
                                if ($roleKey === 'god_mode' && !isGodMode()) continue;
                            ?>
                            <option value="<?= $roleKey ?>"><?= htmlspecialchars($role['label']) ?></option>
                            <?php endforeach; ?>
                            <option value="custom">Custom Permissions</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-item">
                        <input type="checkbox" name="must_change_password">
                        <span class="checkbox-label">
                            Require password change on first login
                            <small>User will be prompted to set a new password</small>
                        </span>
                    </label>
                </div>
                
                <div class="form-group" id="addPermissionsGroup" style="display: none;">
                    <label class="form-label">Custom Permissions</label>
                    <div class="checkbox-group">
                        <?php foreach ($adminData['permissions_catalog'] as $permKey => $perm): ?>
                        <label class="checkbox-item">
                            <input type="checkbox" name="permissions[]" value="<?= $permKey ?>">
                            <span class="checkbox-label">
                                <?= htmlspecialchars($perm['label']) ?>
                                <small><?= htmlspecialchars($perm['description']) ?></small>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('addUserModal').classList.remove('show')">Cancel</button>
                <button type="submit" class="btn btn-success"><?= ICON_PLUS ?> Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-backdrop" id="resetPasswordModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Reset Password</h3>
            <button type="button" class="modal-close" onclick="closeResetModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin-bottom: 20px;">Reset password for: <strong id="resetUserDisplay"></strong></p>
            
            <div class="tabs" style="margin-bottom: 20px; padding: 4px;">
                <button type="button" class="tab-btn active" onclick="showResetTab('direct')" id="tabDirect">Set New Password</button>
                <button type="button" class="tab-btn" onclick="showResetTab('temp')" id="tabTemp">Temporary Password</button>
            </div>
            
            <!-- Direct Password Reset -->
            <form method="POST" id="resetDirectForm">
                <input type="hidden" name="reset_password_direct" value="1">
                <input type="hidden" name="reset_username" id="resetUsernameDirectInput">
                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-input" required>
                    <div class="form-hint">Min 8 chars, uppercase, lowercase, number</div>
                </div>
                <div class="modal-footer" style="padding: 0; border: none; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeResetModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Set Password</button>
                </div>
            </form>
            
            <!-- Temporary Password Reset -->
            <form method="POST" id="resetTempForm" style="display: none;">
                <input type="hidden" name="reset_password_temp" value="1">
                <input type="hidden" name="reset_username" id="resetUsernameTempInput">
                <div class="form-group">
                    <label class="form-label">Temporary Password</label>
                    <input type="password" name="temp_password" class="form-input" required>
                    <div class="form-hint">User will be required to change this on next login</div>
                </div>
                <div class="modal-footer" style="padding: 0; border: none; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeResetModal()">Cancel</button>
                    <button type="submit" class="btn btn-warning">Set Temporary Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal-backdrop" id="deleteModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">âš ï¸ Delete User</h3>
            <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="delete_user" value="1">
            <input type="hidden" name="delete_username" id="deleteUsernameInput">
            <div class="modal-body">
                <p>Are you sure you want to delete user <strong id="deleteUserDisplay"></strong>?</p>
                <p style="color: var(--danger); margin-top: 12px;">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete User</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Role Modal -->
<div class="modal-backdrop" id="deleteRoleModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">âš ï¸ Delete Role</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('deleteRoleModal').classList.remove('show')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="delete_role" value="1">
            <input type="hidden" name="delete_role_key" id="deleteRoleKeyInput">
            <div class="modal-body">
                <p>Are you sure you want to delete role <strong id="deleteRoleDisplay"></strong>?</p>
                <p style="color: var(--danger); margin-top: 12px;">Users assigned to this role will need to be reassigned.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('deleteRoleModal').classList.remove('show')">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete Role</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleAddPermissions() {
    const select = document.getElementById('addRoleSelect');
    const group = document.getElementById('addPermissionsGroup');
    group.style.display = select.value === 'custom' ? 'block' : 'none';
}

function toggleEditPermissions() {
    const select = document.getElementById('editRoleSelect');
    const group = document.getElementById('editPermissionsGroup');
    if (group) {
        group.style.display = select.value === 'custom' ? 'block' : 'none';
    }
}

function showResetModal(username, name) {
    document.getElementById('resetUserDisplay').textContent = name + ' (' + username + ')';
    document.getElementById('resetUsernameDirectInput').value = username;
    document.getElementById('resetUsernameTempInput').value = username;
    document.getElementById('resetPasswordModal').classList.add('show');
    showResetTab('direct');
}

function closeResetModal() {
    document.getElementById('resetPasswordModal').classList.remove('show');
}

function showResetTab(tab) {
    document.getElementById('resetDirectForm').style.display = tab === 'direct' ? 'block' : 'none';
    document.getElementById('resetTempForm').style.display = tab === 'temp' ? 'block' : 'none';
    document.getElementById('tabDirect').classList.toggle('active', tab === 'direct');
    document.getElementById('tabTemp').classList.toggle('active', tab === 'temp');
}

function confirmDelete(username, name) {
    document.getElementById('deleteUserDisplay').textContent = name + ' (' + username + ')';
    document.getElementById('deleteUsernameInput').value = username;
    document.getElementById('deleteModal').classList.add('show');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('show');
}

function confirmDeleteRole(roleKey, roleLabel) {
    document.getElementById('deleteRoleDisplay').textContent = roleLabel;
    document.getElementById('deleteRoleKeyInput').value = roleKey;
    document.getElementById('deleteRoleModal').classList.add('show');
}

// Close modals when clicking outside
document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
    backdrop.addEventListener('click', function(e) {
        if (e.target === backdrop) {
            backdrop.classList.remove('show');
        }
    });
});
</script>
</body>
</html>
