<?php
/**
 * Admin Order Dashboard
 * Requires login via shared admin-auth.php with permission checks
 */

// Include shared authentication
require_once 'admin-auth.php';

// Include icon library
require_once 'includes/icons.php';
require_once 'includes/data-access.php';
require_once 'includes/status-config.php';

// Require at least view permission for orders
requireAnyPermission(['orders_edit', 'orders_view']);

// Permission helper variables for template use
$canEditOrders = hasPermission('orders_edit');
$canCreateOrders = hasPermission('orders_create');
$canViewAnalytics = hasPermission('dashboard_analytics');
$canDeleteOrders = hasPermission('orders_delete');
$canViewVendor = in_array($_SESSION['admin_role'] ?? '', ['god_mode', 'super_admin']);

// MTCC staff conditional rendering
$isMtccStaff = (getCurrentAdminRole() === 'mtcc_staff');
$canChangeMtccStatus = hasPermission('orders_status_mtcc');
$canViewMtccAnalytics = hasPermission('mtcc_analytics');
$statusRole = $isMtccStaff ? 'mtcc_staff' : 'admin';

// Allow MTCC staff access (they have orders_view)
if ($isMtccStaff) {
    require_once __DIR__ . '/includes/site-settings.php';
}

// ============================================================
// AJAX HANDLERS - Must be FIRST before any HTML output
// ============================================================

// Handle status updates via AJAX (must be before any output)
// ============================================================

if (isset($_POST['update_status'])) {
    // Check if logged in
    if (!isAdminLoggedIn()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    // Permission check: MTCC staff can set delivered/pickedup/unclaimed/missing
    $isMtccStaffUser = (getCurrentAdminRole() === 'mtcc_staff');
    if ($isMtccStaffUser) {
        if (!hasPermission('orders_status_mtcc')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
            exit;
        }
        $mtccAllowed = ['delivered', 'pickedup', 'unclaimed', 'missing'];
        $requestedStatus = $_POST['status'] ?? '';
        if (!in_array($requestedStatus, $mtccAllowed)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'MTCC staff can only set status to: Delivered, Picked Up, Unclaimed, or Missing']);
            exit;
        }
    } elseif (!hasPermission('orders_edit')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'You do not have permission to change order status']);
        exit;
    }
    
    try {
        $referenceCode = $_POST['reference_code'] ?? '';
        $newStatus = $_POST['status'] ?? '';
        
        // Validate status
        $validStatuses = ['unpaid', 'paid', 'preflight', 'file_issue', 'printing', 'ready', 'dispatched', 'shipped', 'delivered', 'pickedup', 'unclaimed', 'missing', 'cancelled', 'refunded'];
        if (!in_array($newStatus, $validStatuses)) {
            throw new Exception('Invalid status: ' . $newStatus);
        }
        
        // Load existing statuses
        $statusFile = 'data/statuses.json';
        $statuses = [];
        if (file_exists($statusFile)) {
            $statuses = json_decode(file_get_contents($statusFile), true) ?: [];
        }
        
        // Get old status for history
        $oldStatus = $statuses[$referenceCode] ?? 'unpaid';
        
        // Update status
        $statuses[$referenceCode] = $newStatus;
        
        // Save statuses
        if (file_put_contents($statusFile, json_encode($statuses, JSON_PRETTY_PRINT), LOCK_EX) === false) {
            throw new Exception('Failed to save status file');
        }
        
        // Pickup person (optional, only on pickedup transitions)
        $pickupPerson = trim($_POST['pickup_person'] ?? '');

        // Also update the individual order JSON file for scanner compatibility
        $orderDir = 'uploads/orders/';
        $orderFiles = glob($orderDir . '*-order.json');
        foreach ($orderFiles as $file) {
            $orderData = json_decode(file_get_contents($file), true);
            if ($orderData && isset($orderData['referenceCode']) && $orderData['referenceCode'] === $referenceCode) {
                $orderData['status'] = $newStatus;
                // Save pickup record if this is a pickedup transition
                if ($newStatus === 'pickedup') {
                    $orderData['pickup'] = [
                        'picked_up_at' => date('c'),
                        'picked_up_by_staff' => getCurrentAdminName(),
                        'pickup_person' => $pickupPerson !== '' ? $pickupPerson : ($orderData['customerInfo']['name'] ?? ''),
                        'same_as_customer' => ($pickupPerson === ''),
                    ];
                }
                file_put_contents($file, json_encode($orderData, JSON_PRETTY_PRINT));
                break;
            }
        }

        // Log to order history
        $statusLabels = getStatusLabelsForRole('admin');
        $oldLabel = $statusLabels[$oldStatus] ?? $oldStatus;
        $newLabel = $statusLabels[$newStatus] ?? $newStatus;
        $historyMsg = "Status changed from \"$oldLabel\" to \"$newLabel\"";
        if ($newStatus === 'pickedup' && $pickupPerson !== '') {
            $historyMsg .= ". Picked up by: $pickupPerson";
        }
        logOrderHistory($referenceCode, 'status_change', $historyMsg, getCurrentAdminName());
        
        // Log to activity log (if user is tracked)
        logAdminActivity('Status Change', [
            'from' => $oldLabel,
            'to' => $newLabel
        ], $referenceCode);

        // Send customer email for status changes that trigger notifications
        // (printing, delivered, pickedup, cancelled, refunded)
        if (in_array($newStatus, ['printing', 'delivered', 'pickedup', 'cancelled', 'refunded'])) {
            if (!function_exists('sendDispatchNotification')) {
                require_once __DIR__ . '/email-status-notifications.php';
            }
            if ($orderData && function_exists('sendDispatchNotification')) {
                try {
                    sendDispatchNotification($orderData, $newStatus, getCurrentAdminName());
                } catch (Exception $e) {
                    error_log('Status email failed for ' . $referenceCode . ': ' . $e->getMessage());
                }
            }
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Status updated successfully',
            'referenceCode' => $referenceCode,
            'newStatus' => $newStatus
        ]);
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle MTCC issue report submission
if (isset($_POST['mtcc_report_issue'])) {
    header('Content-Type: application/json');
    if (!isAdminLoggedIn()) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    if (getCurrentAdminRole() !== 'mtcc_staff' && !hasPermission('orders_view')) {
        echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
        exit;
    }

    $reportRef = trim($_POST['reference_code'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if (!$reportRef || !$description) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }

    $staffName = getCurrentAdminName();

    // Log to order history
    logOrderHistory($reportRef, 'mtcc_issue', 'MTCC Issue: ' . $description, $staffName);

    // Send email to orders@printstuff.ca
    $to = 'orders@printstuff.ca';
    $subject = 'MTCC Issue Report: #' . $reportRef;
    $body = '<html><body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
          . '<h2 style="color: #dc2626;">MTCC Issue Report</h2>'
          . '<p><strong>Order:</strong> #' . htmlspecialchars($reportRef) . '</p>'
          . '<p><strong>Reported by:</strong> ' . htmlspecialchars($staffName) . ' (MTCC Staff)</p>'
          . '<p><strong>Date:</strong> ' . date('F j, Y g:i A') . '</p>'
          . '<div style="background:#fef2f2;border-left:4px solid #dc2626;padding:12px 16px;margin:16px 0;border-radius:8px;">'
          . '<strong>Issue description:</strong><br>' . nl2br(htmlspecialchars($description))
          . '</div>'
          . '<p><a href="https://mtcc.print-stuff.ca/admin-orders.php?view=' . urlencode($reportRef) . '" style="display:inline-block;background:#7c3aed;color:white;padding:10px 20px;border-radius:6px;text-decoration:none;">View Order</a></p>'
          . '</body></html>';

    $headers = "MIME-Version: 1.0\r\n" .
               "Content-Type: text/html; charset=UTF-8\r\n" .
               "From: MTCC Staff <orders@printstuff.ca>\r\n" .
               "Reply-To: orders@printstuff.ca\r\n";

    // Try SMTP first if available
    $sent = false;
    if (file_exists(__DIR__ . '/email-status-notifications.php')) {
        require_once __DIR__ . '/email-status-notifications.php';
        if (function_exists('sendEmailSMTP')) {
            $sent = sendEmailSMTP($to, $subject, $body, $reportRef, 'orders');
        }
    }
    if (!$sent) {
        $sent = @mail($to, $subject, $body, $headers);
    }

    echo json_encode(['success' => (bool)$sent, 'message' => $sent ? 'Issue reported' : 'Email could not be sent, but issue was logged']);
    exit;
}

// Handle check for new orders (polling endpoint)
if (isset($_GET['check_new_orders'])) {
    // Check if logged in
    if (!isAdminLoggedIn()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    try {
        $orderDir = 'uploads/orders/';
        $orderRefs = [];
        
        if (is_dir($orderDir)) {
            $files = glob($orderDir . '*.json');
            foreach ($files as $file) {
                $content = file_get_contents($file);
                $orderData = json_decode($content, true);
                if ($orderData && isset($orderData['referenceCode'])) {
                    $orderRefs[] = $orderData['referenceCode'];
                }
            }
        }
        
        // Also compute a hash of current statuses for change detection
        $statusFile = 'data/statuses.json';
        $statusHash = '';
        if (file_exists($statusFile)) {
            $statusHash = md5_file($statusFile);
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'orderRefs' => $orderRefs,
            'totalOrders' => count($orderRefs),
            'statusHash' => $statusHash
        ]);
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// END AJAX HANDLERS
// ============================================================

// Require admin login (shows login form if not logged in)
requireAdminLogin();

// Include email functions
require_once 'email-order-confirmation.php';

// Test: Add basic error reporting
error_reporting( E_ALL );
ini_set( 'display_errors', 1 );

// Try to include utilities
if (file_exists(__DIR__ . '/includes/utilities.php')) {
    require_once __DIR__ . '/includes/utilities.php';
} elseif (file_exists(__DIR__ . '/utilities.php')) {
    require_once __DIR__ . '/utilities.php';
}
require_once 'includes/admin-orders-render.php';

// Handle download requests
if ( isset( $_GET[ 'download' ] ) ) {
  handleDownload();
}

// Status updates are now handled at the top of the file (before any output)

// Handle order deletion
if ( isset( $_POST[ 'delete_order' ] ) ) {
  handleOrderDeletion();
}

// Handle notes
if ( isset( $_POST[ 'add_internal_note' ] ) ) {
  handleAddInternalNote();
}

if ( isset( $_POST[ 'edit_internal_note' ] ) ) {
  handleEditInternalNote();
}

if ( isset( $_POST[ 'remove_internal_note' ] ) ) {
  handleRemoveInternalNote();
}

// Handle order saving
if ( isset( $_POST[ 'save_order' ] ) ) {
  handleOrderSave();
}

// Handle resend confirmation email
if (isset($_POST['resend_email'])) {
    handleResendConfirmationEmail();
}


// Handle BULK shipping labels generation
if ( isset( $_GET[ 'bulk_labels' ] ) && isAdminLoggedIn() ) {
  $referenceCodes = explode(',', $_GET[ 'bulk_labels' ]);
  $orderDir = 'uploads/orders/';
  $ordersData = [];
  
  // Load statuses
  $statusFile = 'data/statuses.json';
  $statuses = [];
  if ( file_exists( $statusFile ) ) {
    $statuses = json_decode( file_get_contents( $statusFile ), true ) ?: [];
  }

  // Find all requested orders
  $orderFiles = glob( $orderDir . '*-order.json' );
  foreach ( $orderFiles as $file ) {
    $data = json_decode( file_get_contents( $file ), true );
    if ( $data && in_array($data[ 'referenceCode' ], $referenceCodes) ) {
      $ordersData[] = $data;
    }
  }

  if ( count($ordersData) > 0 ) {
    generateBulkShippingLabels( $ordersData, $statuses );
    exit;
  }

  header( 'Location: admin-orders.php' );
  exit;
}

// Handle BULK order details printing
if ( isset( $_GET[ 'bulk_print' ] ) && isAdminLoggedIn() ) {
  $referenceCodes = explode(',', $_GET[ 'bulk_print' ]);
  $orderDir = 'uploads/orders/';
  $ordersData = [];
  
  // Load statuses
  $statusFile = 'data/statuses.json';
  $statuses = [];
  if ( file_exists( $statusFile ) ) {
    $statuses = json_decode( file_get_contents( $statusFile ), true ) ?: [];
  }

  // Find all requested orders
  $orderFiles = glob( $orderDir . '*-order.json' );
  foreach ( $orderFiles as $file ) {
    $data = json_decode( file_get_contents( $file ), true );
    if ( $data && in_array($data[ 'referenceCode' ], $referenceCodes) ) {
      $ordersData[] = $data;
    }
  }

  if ( count($ordersData) > 0 ) {
    generateBulkOrderPrint( $ordersData, $statuses );
    exit;
  }

  header( 'Location: admin-orders.php' );
  exit;
}

// Handle BULK file download (zip)
if ( isset( $_GET[ 'bulk_download' ] ) && isAdminLoggedIn() ) {
  $referenceCodes = explode(',', $_GET[ 'bulk_download' ]);
  $orderDir = 'uploads/orders/';
  
  // Find all requested orders and their files
  $orderFiles = glob( $orderDir . '*-order.json' );
  $filesToZip = [];
  
  foreach ( $orderFiles as $file ) {
    $data = json_decode( file_get_contents( $file ), true );
    if ( $data && in_array($data[ 'referenceCode' ], $referenceCodes) ) {
      // Get the uploaded file path
      if ( isset($data['uploadedFile']) && !empty($data['uploadedFile']) ) {
        $filePath = $data['uploadedFile'];
        // Handle both relative and absolute paths
        if ( strpos($filePath, 'uploads/') === 0 ) {
          $fullPath = $filePath;
        } else {
          $fullPath = 'uploads/orders/' . basename($filePath);
        }
        
        if ( file_exists($fullPath) ) {
          $filesToZip[] = [
            'path' => $fullPath,
            'name' => $data['referenceCode'] . '_' . basename($fullPath)
          ];
        }
      }
    }
  }
  
  if ( count($filesToZip) > 0 ) {
    // Create ZIP file
    $zipName = 'order-files-' . date('Y-m-d-His') . '.zip';
    $zipPath = sys_get_temp_dir() . '/' . $zipName;
    
    $zip = new ZipArchive();
    if ( $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE ) {
      foreach ( $filesToZip as $fileInfo ) {
        $zip->addFile($fileInfo['path'], $fileInfo['name']);
      }
      $zip->close();
      
      // Send ZIP file for download
      header('Content-Type: application/zip');
      header('Content-Disposition: attachment; filename="' . $zipName . '"');
      header('Content-Length: ' . filesize($zipPath));
      header('Cache-Control: no-cache, must-revalidate');
      readfile($zipPath);
      
      // Clean up
      unlink($zipPath);
      exit;
    }
  }
  
  // If no files found, redirect back with message
  header( 'Location: admin-orders.php?error=no_files' );
  exit;
}

// Handle shipping label generation
if ( isset( $_GET[ 'label' ] ) && isAdminLoggedIn() ) {
  $referenceCode = $_GET[ 'label' ];
  $orderDir = 'uploads/orders/';

  // Find the specific order file
  $orderFiles = glob( $orderDir . '*-order.json' );
  $orderData = null;

  foreach ( $orderFiles as $file ) {
    $data = json_decode( file_get_contents( $file ), true );
    if ( $data && $data[ 'referenceCode' ] === $referenceCode ) {
      $orderData = $data;
      break;
    }
  }

  if ( $orderData ) {
    generateShippingLabel( $orderData );
    exit;
  }

  // If order not found, redirect back
  header( 'Location: admin-orders.php' );
  exit;
}


// Handle bulk status updates
if ( isset( $_POST[ 'bulk_update' ] ) && isAdminLoggedIn() ) {
  $statusFile = 'data/statuses.json';
  $statuses = [];

  if ( file_exists( $statusFile ) ) {
    $statuses = json_decode( file_get_contents( $statusFile ), true ) ? : [];
  }

  $selectedOrders = $_POST[ 'selected_orders' ] ?? [];
  $newStatus = $_POST[ 'bulk_status' ];
  $orderDir = 'uploads/orders/';

  foreach ( $selectedOrders as $referenceCode ) {
    $statuses[ $referenceCode ] = $newStatus;
    
    // Also update individual order JSON file for scanner compatibility
    $orderFiles = glob($orderDir . '*-order.json');
    foreach ($orderFiles as $file) {
      $orderData = json_decode(file_get_contents($file), true);
      if ($orderData && isset($orderData['referenceCode']) && $orderData['referenceCode'] === $referenceCode) {
        $orderData['status'] = $newStatus;
        file_put_contents($file, json_encode($orderData, JSON_PRETTY_PRINT));
        break;
      }
    }
  }

  file_put_contents( $statusFile, json_encode( $statuses, JSON_PRETTY_PRINT ) );

  header( 'Location: admin-orders.php?updated=' . count( $selectedOrders ) );
  exit;
}

// Handle order view — MTCC staff cannot access full detail page (slideout only)
if ( isset( $_GET[ 'view' ] ) && isAdminLoggedIn() && (getCurrentAdminRole() === 'mtcc_staff') ) {
  header('Location: admin-orders.php');
  exit;
}
if ( isset( $_GET[ 'view' ] ) && isAdminLoggedIn() ) {
  $referenceCode = $_GET[ 'view' ];
  $orderDir = 'uploads/orders/';

  // More specific search for the exact order
  $orderFiles = glob( $orderDir . '*-order.json' );
  $orderData = null;

  foreach ( $orderFiles as $file ) {
    $data = json_decode( file_get_contents( $file ), true );
    if ( $data && $data[ 'referenceCode' ] === $referenceCode ) {
      $orderData = $data;
      break;
    }
  }

  if ( $orderData ) {
    displayOrderView( $orderData );
    exit;
  }

  // If order not found, redirect back
  header( 'Location: admin-orders.php' );
  exit;
}

// NEW SHIPPING LABEL GENERATION FUNCTION

// Load statuses
$statuses = loadStatuses();

// Load preflight log for vendor pricing / COGS data
$preflightLogFile = 'data/preflight-log.json';
$preflightLog = file_exists($preflightLogFile) ? (json_decode(file_get_contents($preflightLogFile), true) ?: []) : [];
$preflightEntries = $preflightLog['entries'] ?? [];

// Status configuration - uses centralized status-config.php
// For MTCC staff, use mtcc_staff labels with admin labels as fallback (no "Unknown" badges)
$adminLabelsMap = getStatusLabelsForRole('admin');
$roleLabelsMap = getStatusLabelsForRole($statusRole);
$statusColorsMap = getStatusColors();
$statusConfig = [];
foreach ($adminLabelsMap as $code => $label) {
  $statusConfig[$code] = [ 'label' => $roleLabelsMap[$code] ?? $label, 'color' => $statusColorsMap[$code] ?? '#6b7280', 'class' => $code ];
}

// Admin is logged in - show orders
$orderDir = 'uploads/orders/';
$fileDir = 'uploads/files/';

// Load orders
$orders = [];
if ( is_dir( 'uploads/orders/' ) ) {
  $files = glob( 'uploads/orders/*.json' );
  foreach ( $files as $file ) {
    $content = file_get_contents( $file );
    $orderData = json_decode( $content, true );
    if ( $orderData ) {
      // Skip orders missing essential fields
      if (empty($orderData['referenceCode'])) {
        error_log("Skipping malformed order file (missing referenceCode): " . $file);
        continue;
      }
      $orderData[ 'filename' ] = basename( $file );
      $orderData[ 'filesize' ] = filesize( $file );
      $orderData[ 'modified' ] = filemtime( $file );
      $refCode = $orderData['referenceCode'] ?? '';
      $orderData[ 'status' ] = isset( $statuses[ $refCode ] ) ? $statuses[ $refCode ] : 'unpaid';
      // Merge vendor pricing + packing from preflight log
      $pfEntry = $preflightEntries[$refCode] ?? [];
      $orderData['vendor_pricing'] = $pfEntry['vendor_pricing'] ?? null;
      $orderData['packing'] = $pfEntry['packing'] ?? null;
      $orderData['vendor_name'] = $pfEntry['vendor_name'] ?? null;
      $orders[] = $orderData;
    }
  }
}

// Sort orders by submission time (newest first)
usort( $orders, function ( $a, $b ) {
  $timeA = isset($a['submittedAt']) ? strtotime($a['submittedAt']) : 0;
  $timeB = isset($b['submittedAt']) ? strtotime($b['submittedAt']) : 0;
  return $timeB - $timeA;
} );


// Load events data for active/archived filtering
// Try multiple possible paths for events.json
$possiblePaths = [
    __DIR__ . '/admin/events.json',
    'admin/events.json',
    __DIR__ . '/events.json',
    'events.json'
];

$eventsData = ['active' => [], 'archived' => []];
$eventsFileFound = false;

foreach ($possiblePaths as $eventsFile) {
    if (file_exists($eventsFile)) {
        $eventsContent = file_get_contents($eventsFile);
        if ($eventsContent) {
            $decoded = json_decode($eventsContent, true);
            if ($decoded && isset($decoded['active'])) {
                $eventsData = $decoded;
                $eventsFileFound = true;
                break;
            }
        }
    }
}

// Build list of active event prefixes (events with endDate >= today OR in the 'active' array)
$todayDate = date('Y-m-d');
$activeEventPrefixes = [];

// All events in the 'active' array should be considered active
// (they are upcoming or current events that haven't been archived yet)
if (!empty($eventsData['active'])) {
    foreach ($eventsData['active'] as $event) {
        $prefix = strtoupper($event['acronym'] ?? '');
        if (!empty($prefix)) {
            // Include ALL events from the 'active' array
            // (these are events the user has marked as active/upcoming)
            $activeEventPrefixes[$prefix] = true;
        }
    }
}

// Calculate problem orders count
$problemOrdersCount = 0;
foreach ($orders as $order) {
    $orderPrefix = strtoupper(explode('-', $order['referenceCode'] ?? '')[0]);
    if (!isset($activeEventPrefixes[$orderPrefix]) && ($order['status'] ?? '') === 'delivered') {
        $problemOrdersCount++;
    }
}

// Calculate analytics and filter data
$filterAnalytics = [
  'priority' => [],
  'status' => [],
  'due_dates' => []
];

// Count orders by priority, status, and due date
foreach ( $orders as $order ) {
  // Priority analysis
  $turnaroundClass = 'standard';
  if ( isset( $order[ 'pricing' ][ 'tier' ] ) ) {
    $tier = strtolower( $order[ 'pricing' ][ 'tier' ] );
    if ( strpos( $tier, 'last minute' ) !== false ) {
      $turnaroundClass = 'lastminute';
    } elseif ( strpos( $tier, 'early' ) !== false ) {
      $turnaroundClass = 'early';
    } elseif ( strpos( $tier, 'rush' ) !== false ) {
      $turnaroundClass = 'rush';
    } elseif ( strpos( $tier, 'urgent' ) !== false ) {
      $turnaroundClass = 'urgent';
    } elseif ( strpos( $tier, 'critical' ) !== false ) {
      $turnaroundClass = 'critical';
    }
  }

  // Count priorities
  $filterAnalytics[ 'priority' ][ $turnaroundClass ] = ( $filterAnalytics[ 'priority' ][ $turnaroundClass ] ?? 0 ) + 1;

  // Count statuses
  $filterAnalytics[ 'status' ][ $order[ 'status' ] ] = ( $filterAnalytics[ 'status' ][ $order[ 'status' ] ] ?? 0 ) + 1;

  // Count due dates
  $dueDate = $order[ 'selectedDate' ] ?? 'Unknown';
  $filterAnalytics[ 'due_dates' ][ $dueDate ] = ( $filterAnalytics[ 'due_dates' ][ $dueDate ] ?? 0 ) + 1;
}

// Sort due dates chronologically
ksort( $filterAnalytics[ 'due_dates' ] );

// Define priority configuration
$priorityConfig = [
  'early' => [ 'label' => 'Early', 'class' => 'priority-early' ],
  'standard' => [ 'label' => 'Standard', 'class' => 'priority-standard' ],
  'rush' => [ 'label' => 'Rush', 'class' => 'priority-rush' ],
  'urgent' => [ 'label' => 'Urgent', 'class' => 'priority-urgent' ],
  'critical' => [ 'label' => 'Critical', 'class' => 'priority-critical' ],
  'lastminute' => [ 'label' => 'Last Minute', 'class' => 'priority-lastminute' ]
];

// Define paid statuses (post-payment)
$paid_statuses = ['paid', 'printing', 'delivered', 'picked up'];
$pending_statuses = ['unpaid', 'file_issue'];

// Calculate analytics with new metrics
$analytics = [
  'total_orders' => count( $orders ),
  'total_revenue' => array_sum( array_column( array_column( $orders, 'pricing' ), 'total' ) ),
  'today_orders' => count( array_filter( $orders, function ( $o ) use ($paid_statuses) {
    $submittedAt = $o['submittedAt'] ?? null;
    if (!$submittedAt) return false;
    return date( 'Y-m-d' ) === date( 'Y-m-d', strtotime( $submittedAt ) ) && in_array($o['status'] ?? '', $paid_statuses);
  } ) ),
  'today_revenue' => array_sum( array_map( function( $o ) use ($paid_statuses) {
    $submittedAt = $o['submittedAt'] ?? null;
    if (!$submittedAt) return 0;
    if ( date( 'Y-m-d' ) === date( 'Y-m-d', strtotime( $submittedAt ) ) && in_array($o['status'] ?? '', $paid_statuses) ) {
      return $o[ 'pricing' ][ 'total' ] ?? 0;
    }
    return 0;
  }, $orders ) ),
  'pending_orders' => count( array_filter( $orders, function ( $o ) use ($pending_statuses) {
    return in_array( $o[ 'status' ] ?? '', $pending_statuses );
  } ) ),
  'cancelled_orders' => count( array_filter( $orders, function ( $o ) {
    return ($o[ 'status' ] ?? '') === 'cancelled';
  } ) ),
	'cancelled_revenue' => array_sum( array_map( function( $o ) {
    return ($o[ 'status' ] ?? '') === 'cancelled' ? ($o[ 'pricing' ][ 'total' ] ?? 0) : 0;
}, $orders ) ),
  'rush_orders' => count( array_filter( $orders, function ( $o ) {
    return isset( $o[ 'pricing' ][ 'tier' ] ) && ( strpos( $o[ 'pricing' ][ 'tier' ], 'Urgent' ) !== false || strpos( $o[ 'pricing' ][ 'tier' ], 'Critical' ) !== false || strpos( $o[ 'pricing' ][ 'tier' ], 'Last Minute' ) !== false );
  } ) ),
  'avg_order_value' => (function($orders) use ($paid_statuses) {
    $validOrders = array_filter($orders, function($o) use ($paid_statuses) { return in_array($o['status'] ?? '', $paid_statuses); });
    return count($validOrders) > 0 ? array_sum(array_column(array_column($validOrders, 'pricing'), 'total')) / count($validOrders) : 0;
  })($orders),
// Total revenue - ONLY paid+ orders
'total_revenue_excluding_cancelled' => array_sum( array_map( function( $o ) use ($paid_statuses) {
  return in_array($o['status'] ?? '', $paid_statuses) ? ($o['pricing']['total'] ?? 0) : 0;
}, $orders ) ),
// Total base revenue - ONLY paid+ orders
'total_base_revenue' => array_sum( array_map( function( $o ) use ($paid_statuses) {
  return in_array($o['status'] ?? '', $paid_statuses) ? ($o['pricing']['basePrice'] ?? 0) : 0;
}, $orders ) ),
// MTCC Venue Fee - 10% of paid+ base revenue
'mtcc_venue_fee' => array_sum( array_map( function( $o ) use ($paid_statuses) {
  return in_array($o['status'] ?? '', $paid_statuses) ? ($o['pricing']['basePrice'] ?? 0) : 0;
}, $orders ) ) * 0.10,
'avg_base_price' => 0, // Will calculate below
  'status_breakdown' => [],
  'size_breakdown' => [],
  'turnaround_breakdown' => [],
  'material_breakdown' => [],
  'delivery_breakdown' => []
];

// Calculate pending orders revenue and base revenue
$analytics['pending_revenue'] = 0;
$analytics['pending_base_revenue'] = 0;
foreach($orders as $order) {
    if (in_array($order['status'] ?? '', $pending_statuses)) {
        $analytics['pending_revenue'] += $order['pricing']['total'] ?? 0;
        $analytics['pending_base_revenue'] += $order['pricing']['basePrice'] ?? 0;
    }
}

// Calculate paid+ order count
$analytics['paid_order_count'] = count(array_filter($orders, function($o) use ($paid_statuses) {
    return in_array($o['status'] ?? '', $paid_statuses);
}));


// Calculate status breakdown (exclude cancelled for Order Statuses card)
foreach ( $statusConfig as $key => $config ) {
  if ( $key !== 'cancelled' ) {
    $analytics[ 'status_breakdown' ][ $key ] = count( array_filter( $orders, function ( $o )use( $key ) {
      return ($o['status'] ?? '') === $key;
    } ) );
  }
}

// Calculate cancelled orders separately (for Cancelled Orders card only)
$analytics['cancelled_orders_count'] = count(array_filter($orders, function($o) {
    return ($o['status'] ?? '') === 'cancelled';
}));

// Calculate refunded orders
$analytics['refunded_orders_count'] = count(array_filter($orders, function($o) {
    return ($o['status'] ?? '') === 'refunded';
}));
$analytics['refunded_revenue'] = array_sum(array_map(function($o) {
    return ($o['status'] ?? '') === 'refunded' ? ($o['pricing']['total'] ?? 0) : 0;
}, $orders));

// Calculate percentages (cancelled and refunded as % of total orders)
$total_order_count = count($orders);
$analytics['cancelled_percentage'] = $total_order_count > 0 
    ? number_format(($analytics['cancelled_orders_count'] / $total_order_count) * 100, 3) 
    : '0.000';
$analytics['refunded_percentage'] = $total_order_count > 0 
    ? number_format(($analytics['refunded_orders_count'] / $total_order_count) * 100, 3) 
    : '0.000';

// Calculate material breakdown percentages (excluding cancelled and refunded)
$poster_count = count( array_filter( $orders, function( $o ) { return ($o['material'] ?? '') === 'poster' && !in_array($o['status'] ?? '', ['cancelled', 'refunded']); } ) );
$fabric_count = count( array_filter( $orders, function( $o ) { return ($o['material'] ?? '') === 'fabric' && !in_array($o['status'] ?? '', ['cancelled', 'refunded']); } ) );
$total_material = $poster_count + $fabric_count;

if ( $total_material > 0 ) {
  $analytics[ 'material_breakdown' ] = [
    'poster' => round( ( $poster_count / $total_material ) * 100, 1 ),
    'fabric' => round( ( $fabric_count / $total_material ) * 100, 1 ),
    'poster_count' => $poster_count,
    'fabric_count' => $fabric_count
  ];
}

// Calculate delivery breakdown percentages (excluding cancelled and refunded)
$mtcc_count = count( array_filter( $orders, function( $o ) { return ($o['deliveryOption'] ?? '') === 'mtcc' && !in_array($o['status'] ?? '', ['cancelled', 'refunded']); } ) );
$office_count = count( array_filter( $orders, function( $o ) { return ($o['deliveryOption'] ?? '') === 'office' && !in_array($o['status'] ?? '', ['cancelled', 'refunded']); } ) );
$total_delivery = $mtcc_count + $office_count;

if ( $total_delivery > 0 ) {
  $analytics[ 'delivery_breakdown' ] = [
    'mtcc' => round( ( $mtcc_count / $total_delivery ) * 100, 1 ),
    'office' => round( ( $office_count / $total_delivery ) * 100, 1 ),
    'mtcc_count' => $mtcc_count,
    'office_count' => $office_count
  ];
}

// Calculate size breakdown (keep existing logic)
$sizes = [];
foreach ( $orders as $order ) {
  // Skip cancelled and refunded orders
  if (in_array($order['status'] ?? '', ['cancelled', 'refunded'])) continue;
  
  // Skip orders without dimensions
  if (!isset($order['dimensions']['width']) || !isset($order['dimensions']['height'])) continue;
  
  $size = $order[ 'dimensions' ][ 'width' ] . 'x' . $order[ 'dimensions' ][ 'height' ];
  $sizes[ $size ] = ( $sizes[ $size ] ?? 0 ) + 1;
}
arsort( $sizes );
$analytics[ 'size_breakdown' ] = array_slice( $sizes, 0, 5, true ); // Top 5 sizes

// Calculate turnaround breakdown (keep existing logic)
$turnarounds = [];
foreach ( $orders as $order ) {
  // Skip cancelled and refunded orders
  if (in_array($order['status'] ?? '', ['cancelled', 'refunded'])) continue;
  
  $tier = $order[ 'pricing' ][ 'tier' ] ?? 'Standard';
  $tierLower = strtolower( $tier ); // Add case-insensitive matching

  // Categorize turnaround times
  if ( strpos( $tierLower, 'last minute' ) !== false ) {
    $turnarounds[ 'Last Minute' ] = ( $turnarounds[ 'Last Minute' ] ?? 0 ) + 1;
  } elseif ( strpos( $tierLower, 'critical' ) !== false ) {
    $turnarounds[ 'Critical' ] = ( $turnarounds[ 'Critical' ] ?? 0 ) + 1;
  } elseif ( strpos( $tierLower, 'urgent' ) !== false ) {
    $turnarounds[ 'Urgent' ] = ( $turnarounds[ 'Urgent' ] ?? 0 ) + 1;
  } elseif ( strpos( $tierLower, 'rush' ) !== false ) {
    $turnarounds[ 'Rush' ] = ( $turnarounds[ 'Rush' ] ?? 0 ) + 1;
  } elseif ( strpos( $tierLower, 'early' ) !== false ) {
    $turnarounds[ 'Early' ] = ( $turnarounds[ 'Early' ] ?? 0 ) + 1;
  } else {
    $turnarounds[ 'Standard' ] = ( $turnarounds[ 'Standard' ] ?? 0 ) + 1;
  }
}
$analytics[ 'turnaround_breakdown' ] = $turnarounds;

// Calculate average base price (paid+ orders only)
$paid_orders = array_filter( $orders, function( $o ) use ($paid_statuses) { return in_array($o['status'] ?? '', $paid_statuses); } );
$analytics['valid_order_count'] = count( $paid_orders ); // Now reflects paid+ orders only
if ( count( $paid_orders ) > 0 ) {
  $total_base_price = array_sum( array_map( function( $o ) { return $o['pricing']['basePrice'] ?? 0; }, $paid_orders ) );
  $analytics['avg_base_price'] = $total_base_price / count( $paid_orders );
}

// New Metrics: Average Turnaround Time, File Issue Rate, On-Time Delivery Rate
$turnaroundTimes = [];
$onTimeCount = 0;
$deliveredCount = 0;
$fileIssueCount = 0;
$totalOrdersForMetrics = count($orders);

foreach ($orders as $o) {
    // Average turnaround: time from submittedAt to when it reached 'delivered' or 'pickedup'
    if (in_array($o['status'] ?? '', ['delivered', 'pickedup']) && !empty($o['submittedAt'])) {
        $submitted = strtotime($o['submittedAt']);
        $modified = $o['modified'] ?? time();
        if ($submitted && $modified > $submitted) {
            $turnaroundTimes[] = $modified - $submitted;
        }
        $deliveredCount++;

        // On-time: was it delivered before or on the due date?
        if (!empty($o['selectedDate'])) {
            $dueEnd = strtotime($o['selectedDate'] . ' 23:59:59');
            if ($modified <= $dueEnd) $onTimeCount++;
        }
    }

    // File issue rate
    if (($o['status'] ?? '') === 'file_issue') $fileIssueCount++;
}

$avgTurnaroundSeconds = count($turnaroundTimes) > 0 ? array_sum($turnaroundTimes) / count($turnaroundTimes) : 0;
$avgTurnaroundHours = round($avgTurnaroundSeconds / 3600, 1);
// Format nicely: if > 48h show days, otherwise hours
if ($avgTurnaroundHours >= 48) {
    $analytics['avg_turnaround_display'] = round($avgTurnaroundHours / 24, 1) . 'd';
} else {
    $analytics['avg_turnaround_display'] = $avgTurnaroundHours . 'h';
}
$analytics['avg_turnaround_hours'] = $avgTurnaroundHours;
$analytics['on_time_rate'] = $deliveredCount > 0 ? round(($onTimeCount / $deliveredCount) * 100, 1) : 0;
$analytics['file_issue_rate'] = $totalOrdersForMetrics > 0 ? round(($fileIssueCount / $totalOrdersForMetrics) * 100, 1) : 0;
$analytics['file_issue_count'] = $fileIssueCount;

// ============================================================
// SMART ALERTS — Actionable notifications
// ============================================================
$alerts = [];
$today = date('Y-m-d');
$now = time();
$activeStatuses = ['paid', 'preflight', 'file_issue', 'printing', 'ready', 'shipped'];

// 1. Orders past due (due date passed, still in active status)
$pastDueOrders = array_filter($orders, function($o) use ($today, $activeStatuses) {
    $dueDate = $o['selectedDate'] ?? '';
    $status = $o['status'] ?? '';
    return $dueDate && $dueDate < $today && in_array($status, $activeStatuses);
});
if (count($pastDueOrders) > 0) {
    $alerts[] = [
        'type' => 'danger',
        'icon' => ICON_WARNING,
        'text' => '<strong>' . count($pastDueOrders) . '</strong> order(s) past due date',
        'filter' => 'pastdue',
        'count' => count($pastDueOrders)
    ];
}

// 2. Orders due today
$dueTodayOrders = array_filter($orders, function($o) use ($today, $activeStatuses) {
    return ($o['selectedDate'] ?? '') === $today && in_array($o['status'] ?? '', $activeStatuses);
});
if (count($dueTodayOrders) > 0) {
    $alerts[] = [
        'type' => 'warning',
        'icon' => ICON_CALENDAR,
        'text' => '<strong>' . count($dueTodayOrders) . '</strong> order(s) due today',
        'filter' => 'duetoday',
        'count' => count($dueTodayOrders)
    ];
}

// 3. Unpaid orders older than 24 hours
$unpaidStale = array_filter($orders, function($o) use ($now) {
    if (($o['status'] ?? '') !== 'unpaid') return false;
    $submitted = strtotime($o['submittedAt'] ?? '');
    return $submitted && ($now - $submitted) > 86400;
});
if (count($unpaidStale) > 0) {
    $alerts[] = [
        'type' => 'warning',
        'icon' => ICON_MONEY_BAG,
        'text' => '<strong>' . count($unpaidStale) . '</strong> unpaid order(s) over 24 hours old',
        'filter' => 'status-unpaid',
        'count' => count($unpaidStale)
    ];
}

// 4. Uncollected orders from past events
if ($problemOrdersCount > 0) {
    $alerts[] = [
        'type' => 'warning',
        'icon' => ICON_MAILBOX,
        'text' => '<strong>' . $problemOrdersCount . '</strong> uncollected order(s) from past events',
        'filter' => 'status-delivered',
        'count' => $problemOrdersCount
    ];
}

// 5. File issues (vendor flagged)
$fileIssueOrders = array_filter($orders, function($o) {
    return ($o['status'] ?? '') === 'file_issue';
});
if (count($fileIssueOrders) > 0) {
    $alerts[] = [
        'type' => 'danger',
        'icon' => ICON_CROSS,
        'text' => '<strong>' . count($fileIssueOrders) . '</strong> order(s) with file issues',
        'filter' => 'status-file_issue',
        'count' => count($fileIssueOrders)
    ];
}

// 6. Paid but not assigned to vendor (sitting in 'paid' for 2+ hours)
$paidUnassigned = array_filter($orders, function($o) use ($now) {
    if (($o['status'] ?? '') !== 'paid') return false;
    $paidAt = strtotime($o['paidAt'] ?? $o['submittedAt'] ?? '');
    return $paidAt && ($now - $paidAt) > 7200;
});
if (count($paidUnassigned) > 0) {
    $alerts[] = [
        'type' => 'warning',
        'icon' => ICON_CLOCK,
        'text' => '<strong>' . count($paidUnassigned) . '</strong> paid order(s) not yet assigned to vendor',
        'filter' => 'status-paid',
        'count' => count($paidUnassigned)
    ];
}

// Filter alerts for MTCC staff — only show venue-relevant alerts
if ($isMtccStaff) {
    $mtccAlertFilters = ['pastdue', 'duetoday', 'status-delivered'];
    $alerts = array_values(array_filter($alerts, function($a) use ($mtccAlertFilters) {
        return in_array($a['filter'], $mtccAlertFilters);
    }));
}

// ============================================================
// MTCC ANALYTICS — Venue fee calculated on BASE PRICE only (excludes tax + delivery fees)
// ============================================================
$mtccAnalytics = [];
$venueRate = 0;
$venueRatePct = 0;
if ($isMtccStaff) {
    $siteSettings = getSiteSettings();
    $venueRate = $siteSettings['mtcc_venue_fee_rate'] ?? 0.10;
    $venueRatePct = round($venueRate * 100);

    // Active statuses for revenue (excludes cancelled/refunded)
    $revenueStatuses = ['paid', 'preflight', 'file_issue', 'printing', 'ready', 'dispatched', 'shipped', 'delivered', 'pickedup'];

    // Revenue breakdown: base price (fee-eligible), delivery fees, tax, gross total
    $totalBase = 0; $totalDelivery = 0; $totalTax = 0; $grossRevenue = 0;
    $validOrderCount = 0;
    foreach ($orders as $o) {
        if (in_array($o['status'] ?? '', $revenueStatuses)) {
            $totalBase += $o['pricing']['basePrice'] ?? 0;
            $totalDelivery += $o['pricing']['deliveryFee'] ?? 0;
            $totalTax += $o['pricing']['tax'] ?? 0;
            $grossRevenue += $o['pricing']['total'] ?? 0;
            $validOrderCount++;
        }
    }

    // Rolling period calculations — all on basePrice
    $todayStr = date('Y-m-d');
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $monthStart = date('Y-m-01');

    $todayBase = 0; $todayOrders = 0;
    $weekBase = 0; $weekOrders = 0;
    $monthBase = 0; $monthOrders = 0;

    foreach ($orders as $o) {
        if (!in_array($o['status'] ?? '', $revenueStatuses)) continue;
        $submitted = isset($o['submittedAt']) ? date('Y-m-d', strtotime($o['submittedAt'])) : '';
        $base = $o['pricing']['basePrice'] ?? 0;

        if ($submitted === $todayStr) { $todayBase += $base; $todayOrders++; }
        if ($submitted >= $weekStart) { $weekBase += $base; $weekOrders++; }
        if ($submitted >= $monthStart) { $monthBase += $base; $monthOrders++; }
    }

    // Per-event breakdown
    $eventBreakdown = [];
    foreach ($orders as $o) {
        if (!in_array($o['status'] ?? '', $revenueStatuses)) continue;
        $prefix = strtoupper(explode('-', $o['referenceCode'] ?? '')[0]);
        $eventName = $o['event']['name'] ?? $o['event']['acronym'] ?? $prefix;
        if (!isset($eventBreakdown[$prefix])) {
            $eventBreakdown[$prefix] = ['name' => $eventName, 'orders' => 0, 'base_revenue' => 0, 'gross_revenue' => 0];
        }
        $eventBreakdown[$prefix]['orders']++;
        $eventBreakdown[$prefix]['base_revenue'] += $o['pricing']['basePrice'] ?? 0;
        $eventBreakdown[$prefix]['gross_revenue'] += $o['pricing']['total'] ?? 0;
    }
    foreach ($eventBreakdown as &$ev) {
        $ev['venue_fee'] = $ev['base_revenue'] * $venueRate;
    }
    unset($ev);

    // Status breakdown using MTCC labels
    $mtccStatusLabels = getStatusLabelsForRole('mtcc_staff');
    $statusBreakdown = [];
    foreach ($orders as $o) {
        $status = $o['status'] ?? 'unpaid';
        $label = $mtccStatusLabels[$status] ?? null;
        if ($label !== null) {
            $statusBreakdown[$label] = ($statusBreakdown[$label] ?? 0) + 1;
        }
    }

    $mtccAnalytics = [
        'total_orders' => count($orders),
        'valid_order_count' => $validOrderCount,
        'total_base' => $totalBase,
        'total_delivery' => $totalDelivery,
        'total_tax' => $totalTax,
        'gross_revenue' => $grossRevenue,
        'venue_fee_rate' => $venueRate,
        'venue_fee_rate_pct' => $venueRatePct,
        'venue_fee_total' => $totalBase * $venueRate,
        'today_orders' => $todayOrders,
        'today_base' => $todayBase,
        'today_venue_fee' => $todayBase * $venueRate,
        'week_orders' => $weekOrders,
        'week_base' => $weekBase,
        'week_venue_fee' => $weekBase * $venueRate,
        'month_orders' => $monthOrders,
        'month_base' => $monthBase,
        'month_venue_fee' => $monthBase * $venueRate,
        'on_time_rate' => $analytics['on_time_rate'] ?? 0,
        'event_breakdown' => $eventBreakdown,
        'status_breakdown' => $statusBreakdown,
    ];
}

// Main orders list
?>
<!DOCTYPE html>
<html>
<head>
<title>Order Management Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">

<!--<link rel="stylesheet" href="admin-orders.css">--> 

<!-- Gridstack for Dashboard Drag-and-Drop -->
<link href="https://cdn.jsdelivr.net/npm/gridstack@10.0.0/dist/gridstack.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/gridstack@10.0.0/dist/gridstack-extra.min.css" rel="stylesheet">

<!-- Core Styles (always load first) -->
<link rel="stylesheet" href="css/admin-base.css">
<link rel="stylesheet" href="css/admin-components.css">
<link rel="stylesheet" href="css/admin-layout.css">

<!-- Page-specific (load as needed) -->
<link rel="stylesheet" href="css/admin-tables.css">
<link rel="stylesheet" href="css/admin-orders.css">
<link rel="stylesheet" href="css/admin-slideout.css">

<!-- Responsive (load last) -->
<link rel="stylesheet" href="css/admin-responsive.css">

<!-- Sidebar Navigation -->
<link rel="stylesheet" href="css/admin-sidebar.css">

<!-- Print (load only when needed) -->
<link rel="stylesheet" href="css/admin-print.css" media="print">


<!-- Online Indicator Styles -->
<style>
<?= getOnlineIndicatorCSS() ?>
</style>
<!-- MODULE 1: Core Utilities --> 
<script src="js/shared/utils.js"></script>
<script src="js/admin-utilities.js"></script>
<!-- MODULE 2: Menu System -->
<script src="js/admin-menu-system.js"></script>
<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<!-- MODULE 3: Analytics (requires Chart.js) -->
<script src="js/admin-analytics.js"></script>
<!-- MODULE 4: Dashboard Controller (coordinates all modules) -->
<script src="js/admin-dashboard.js"></script>
<!-- Gridstack Library for Dashboard -->
<script src="https://cdn.jsdelivr.net/npm/gridstack@10.0.0/dist/gridstack-all.js"></script>
<!-- MODULE 5: Drag-and-Drop Dashboard Cards -->
<script src="js/admin-drag-drop.js"></script>
<!-- MODULE 6: Kanban Board View -->
<script src="js/admin-kanban.js"></script>
<!-- MODULE 7: Order Quick-View Slide-Out -->
<script src="js/admin-slideout.js"></script>

<!-- Icon Library for JavaScript -->
<?php outputIconsScript(); ?>

<!-- Permission bridge for JavaScript -->
<script>
window.PERMS = {
  canEdit: <?= json_encode($canEditOrders) ?>,
  canCreate: <?= json_encode($canCreateOrders) ?>,
  canDelete: <?= json_encode($canDeleteOrders) ?>,
  canViewVendor: <?= json_encode($canViewVendor) ?>,
  canViewAnalytics: <?= json_encode($canViewAnalytics) ?>,
  isMtccStaff: <?= json_encode($isMtccStaff) ?>,
  canChangeMtccStatus: <?= json_encode($canChangeMtccStatus) ?>,
  allowedStatuses: <?= json_encode($isMtccStaff ? ['delivered', 'pickedup', 'unclaimed', 'missing'] : null) ?>,
  role: <?= json_encode(getCurrentAdminRole()) ?>
};
</script>
<?php outputStatusConfigScript($statusRole); ?>

</head>
<body>
<?php require_once __DIR__ . '/includes/admin-sidebar.php'; renderSidebar('orders'); ?>
<script src="js/admin-sidebar.js"></script>
<div style="margin: 0 auto!important; padding: 0 20px!important;">
<!-- Page Header -->
<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title">Order Dashboard</h1>
    <div class="page-welcome">
      <span class="welcome-text">Welcome <?= htmlspecialchars(getCurrentAdminName()) ?>! <?= ICON_WAVE ?></span>
      <span class="welcome-date">Today is <?= date('l, F j, Y') ?></span>
    </div>
  </div>
  <div class="page-header-right">
    <!-- New Orders Badge - Bell icon -->
    <span class="new-orders-badge" id="newOrdersBadge" style="display: none;" title="Click to refresh and see new orders">
      <span class="new-orders-icon"><?= ICON_BELL ?></span>
      <span class="new-orders-count" id="newOrdersCount">0</span>
      <span class="new-orders-text">new</span>
    </span>
    <div class="events-segmented-control" id="eventsToggle">
      <button class="segment-btn active" data-mode="active">Active Events</button>
      <button class="segment-btn" data-mode="all">All Events</button>
    </div>
    <?php if ($canViewAnalytics): ?>
    <div class="header-controls-group">
      <button class="analytics-toggle-btn" id="analyticsToggleBtn" onclick="toggleAnalytics()">
        <span><?= ICON_CHART_UP ?> </span> Analytics <span class="toggle-icon" id="analyticsToggleIcon"><?= SYMBOL_ARROW_UP ?></span>
      </button>
      <span class="header-divider"></span>
      <button class="dashboard-settings-btn" id="dashboardSettingsBtn" onclick="toggleDashboardEditMode()" title="Customize dashboard layout">
        <span><?= ICON_GEAR ?></span>
      </button>
    </div>
    <?php endif; ?>
  </div>
</div>

</div>

<?php if (!empty($alerts) && !$isMtccStaff): ?>
<div class="smart-alerts-bar" id="smartAlertsBar">
  <div class="alerts-inner">
    <?php foreach ($alerts as $alert): ?>
    <button class="alert-chip alert-<?= $alert['type'] ?>" onclick="filterByAlert('<?= htmlspecialchars($alert['filter']) ?>')" title="Click to filter">
      <span class="alert-chip-icon"><?= $alert['icon'] ?></span>
      <span class="alert-chip-text"><?= $alert['text'] ?></span>
    </button>
    <?php endforeach; ?>
    <button class="alert-dismiss-btn" onclick="dismissAlerts()" title="Dismiss">&#10005;</button>
  </div>
</div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
<div class="update-notice"> <?= ICON_CHECK_GREEN ?> Successfully updated
  <?= intval($_GET['updated']) ?>
  order(s) </div>

<?php endif; ?>

<?php if ($canViewAnalytics): ?>
<div class="analytics-dashboard" id="analyticsContainer">
  <div class="grid-stack" id="analyticsGrid">
    
    <!-- ROW 0: Stat Cards -->
    <!-- Today's Revenue -->
    <div class="grid-stack-item" gs-id="today-revenue" gs-x="0" gs-y="0" gs-w="4" gs-h="6" gs-min-w="4" gs-min-h="3">
      <div class="grid-stack-item-content">
        <div class="analytics-card compact">
          <div class="card-header">
            <span class="card-icon"><?= ICON_MONEY_WINGS ?></span>
            <span class="card-title">Today's Revenue</span>
          </div>
          <div class="card-content">
            <div class="primary-metric" id="todayRevenue">$<?= number_format($analytics['today_revenue'], 2) ?></div>
            <div class="secondary-metric"><strong id="todayOrdersCount"><?= $analytics['today_orders'] ?></strong> orders today</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Average Order -->
    <div class="grid-stack-item" gs-id="avg-order" gs-x="4" gs-y="0" gs-w="4" gs-h="6" gs-min-w="4" gs-min-h="3">
      <div class="grid-stack-item-content">
        <div class="analytics-card compact">
          <div class="card-header">
            <span class="card-icon"><?= ICON_MONEY_WINGS ?></span>
            <span class="card-title">Average Order</span>
          </div>
          <div class="card-content">
            <div class="primary-metric" id="avgOrderValue">$<?= number_format($analytics['avg_order_value'], 2) ?></div>
            <div class="secondary-metric"><strong id="avgBasePrice">$<?= number_format($analytics['avg_base_price'], 2) ?></strong> avg base</div>
          </div>
        </div>
      </div>
    </div>

    <!-- File Conversions -->
    <!-- Total Revenue & Orders -->
    <div class="grid-stack-item" gs-id="total-revenue" gs-x="12" gs-y="0" gs-w="6" gs-h="6" gs-min-w="4" gs-min-h="3">
      <div class="grid-stack-item-content">
        <div class="analytics-card compact revenue-orders-card" style="background: linear-gradient(90deg,rgba(64, 0, 128, 1) 0%, rgba(115, 0, 196, 1) 100%); border-top: none;">
          <div class="card-header" style="border-bottom: 1px solid var(--primary)">
            <span class="card-icon"><?= ICON_MONEY_BAG ?></span>
            <span class="card-title" style="color:white;">Total Revenue</span>
          </div>
          <div class="card-content split-content">
            <div class="split-left">
              <div class="primary-metric" id="totalRevenue" style="color:white;">$<?= number_format($analytics['total_revenue_excluding_cancelled'], 2) ?></div>
              <div class="secondary-metric" style="color:white;"><strong id="totalBaseRevenue" style="color: var(--green);">$<?= number_format($analytics['total_base_revenue'], 2) ?></strong> base</div>
            </div>
            <div class="split-divider"></div>
            <div class="split-right">
              <div class="primary-metric" id="totalOrderCount" style="color:white;"><?= $analytics['valid_order_count'] ?></div>
              <div class="secondary-metric" style="color:white;">orders</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- MTCC Venue Fee -->
    <div class="grid-stack-item" gs-id="mtcc-venue-fee" gs-x="18" gs-y="0" gs-w="6" gs-h="6" gs-min-w="4" gs-min-h="3">
      <div class="grid-stack-item-content">
        <div class="analytics-card compact" style="background: linear-gradient(90deg,rgba(0, 51, 102, 1) 0%, rgba(15, 82, 186, 1) 100%); border-top: none;">
          <div class="card-header" style="border-bottom: 1px solid var(--blue)">
            <span class="card-icon"><?= ICON_DOLLAR ?></span>
            <span class="card-title" style="color:white;">MTCC Venue Fee</span>
          </div>
          <div class="card-content">
            <div class="primary-metric" id="mtccVenueFee" style="color:white;">$<?= number_format($analytics['mtcc_venue_fee'], 2) ?></div>
            <div class="secondary-metric" style="color:white;"><strong>10%</strong> of base</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ROW 1: More Stat Cards -->
    <!-- Pending Orders -->
    <div class="grid-stack-item" gs-id="pending-orders" gs-x="0" gs-y="6" gs-w="4" gs-h="6" gs-min-w="4" gs-min-h="3">
      <div class="grid-stack-item-content">
        <div class="analytics-card compact">
          <div class="card-header">
            <span class="card-icon"><?= ICON_HOURGLASS ?></span>
            <span class="card-title">Pending Orders</span>
          </div>
          <div class="card-content split-content">
            <div class="split-left">
              <div class="primary-metric" id="pendingRevenue">$<?= number_format($analytics['pending_revenue'] ?? 0, 2) ?></div>
              <div class="secondary-metric"><strong id="pendingBaseRevenue">$<?= number_format($analytics['pending_base_revenue'] ?? 0, 2) ?></strong> base</div>
            </div>
            <div class="split-divider"></div>
            <div class="split-right">
              <div class="primary-metric" id="pendingOrdersCount"><?= $analytics['pending_orders'] ?? 0 ?></div>
              <div class="secondary-metric">orders</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Cancelled Orders -->
    <div class="grid-stack-item" gs-id="cancelled-orders" gs-x="4" gs-y="6" gs-w="4" gs-h="6" gs-min-w="4" gs-min-h="3">
      <div class="grid-stack-item-content">
        <div class="analytics-card compact">
          <div class="card-header">
            <span class="card-icon"><?= ICON_CROSS ?></span>
            <span class="card-title">Cancelled</span>
          </div>
          <div class="card-content split-content">
            <div class="split-left">
              <div class="primary-metric" id="cancelledRevenue" style="color: #6b7280;">-$<?= number_format($analytics['cancelled_revenue'] ?? 0, 2) ?></div>
              <div class="secondary-metric"><strong id="cancelledPercentage"><?= $analytics['cancelled_percentage'] ?? '0.000' ?>%</strong> of orders</div>
            </div>
            <div class="split-divider"></div>
            <div class="split-right">
              <div class="primary-metric" id="cancelledOrdersCount" style="color: #6b7280;"><?= $analytics['cancelled_orders_count'] ?? 0 ?></div>
              <div class="secondary-metric">orders</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Refunded Orders -->
    <div class="grid-stack-item" gs-id="refunded-orders" gs-x="8" gs-y="6" gs-w="4" gs-h="6" gs-min-w="4" gs-min-h="3">
      <div class="grid-stack-item-content">
        <div class="analytics-card compact">
          <div class="card-header">
            <span class="card-icon"><?= ICON_PROHIBITED ?></span>
            <span class="card-title">Refunded</span>
          </div>
          <div class="card-content split-content">
            <div class="split-left">
              <div class="primary-metric" id="refundedRevenue" style="color: var(--red);">-$<?= number_format($analytics['refunded_revenue'] ?? 0, 2) ?></div>
              <div class="secondary-metric"><strong id="refundedPercentage"><?= $analytics['refunded_percentage'] ?? '0.000' ?>%</strong> of orders</div>
            </div>
            <div class="split-divider"></div>
            <div class="split-right">
              <div class="primary-metric" id="refundedOrdersCount" style="color: var(--red);"><?= $analytics['refunded_orders_count'] ?? 0 ?></div>
              <div class="secondary-metric">orders</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Material Type -->
    <div class="grid-stack-item" gs-id="material-type" gs-x="12" gs-y="6" gs-w="6" gs-h="6" gs-min-w="4" gs-min-h="3">
      <div class="grid-stack-item-content">
        <div class="analytics-card compact">
          <div class="card-header">
            <span class="card-icon"><?= ICON_DOCUMENT ?></span>
            <span class="card-title">Material Type</span>
          </div>
          <div class="card-content" id="materialTypeContent">
            <div class="horizontal-bar-layout">
              <div class="bar-side left">
                <div class="side-label">Poster</div>
                <div class="side-percentage" style="color: #7c3aed;"><?= $analytics['material_breakdown']['poster'] ?? 0 ?>%</div>
              </div>
              <div class="center-bar-wrapper">
                <div class="center-bar">
                  <div class="center-bar-fill primary" style="width: <?= $analytics['material_breakdown']['poster'] ?? 0 ?>%"></div>
                  <div class="center-bar-fill secondary" style="width: <?= $analytics['material_breakdown']['fabric'] ?? 0 ?>%"></div>
                </div>
                <div class="bar-divider" style="left: <?= $analytics['material_breakdown']['poster'] ?? 0 ?>%"></div>
              </div>
              <div class="bar-side right">
                <div class="side-label">Fabric</div>
                <div class="side-percentage" style="color: var(--blue);"><?= $analytics['material_breakdown']['fabric'] ?? 0 ?>%</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Delivery Method -->
    <div class="grid-stack-item" gs-id="delivery-method" gs-x="18" gs-y="6" gs-w="6" gs-h="6" gs-min-w="4" gs-min-h="3">
      <div class="grid-stack-item-content">
        <div class="analytics-card compact">
          <div class="card-header">
            <span class="card-icon"><?= ICON_TRUCK ?></span>
            <span class="card-title">Delivery Method</span>
          </div>
          <div class="card-content" id="deliveryMethodContent">
            <div class="horizontal-bar-layout">
              <div class="bar-side left">
                <div class="side-label">MTCC</div>
                <div class="side-percentage" style="color: #7c3aed;"><?= $analytics['delivery_breakdown']['mtcc'] ?? 0 ?>%</div>
              </div>
              <div class="center-bar-wrapper">
                <div class="center-bar">
                  <div class="center-bar-fill primary" style="width: <?= $analytics['delivery_breakdown']['mtcc'] ?? 0 ?>%"></div>
                  <div class="center-bar-fill secondary" style="width: <?= $analytics['delivery_breakdown']['office'] ?? 0 ?>%"></div>
                </div>
                <div class="bar-divider" style="left: <?= $analytics['delivery_breakdown']['mtcc'] ?? 0 ?>%"></div>
              </div>
              <div class="bar-side right">
                <div class="side-label">Office</div>
                <div class="side-percentage" style="color: var(--blue);"><?= $analytics['delivery_breakdown']['office'] ?? 0 ?>%</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ROW 2-3: Charts -->
    <!-- Timeline Chart (Large) -->
    <div class="grid-stack-item" gs-id="timeline-chart" gs-x="0" gs-y="12" gs-w="12" gs-h="21" gs-min-w="8" gs-min-h="12">
      <div class="grid-stack-item-content">
        <div class="analytics-card chart-card">
          <div class="card-header">
            <span class="card-icon"><?= ICON_CHART_UP ?></span>
            <span class="card-title">Orders & Revenue Timeline</span>
            <div class="chart-controls">
              <button class="timeline-period-btn active" data-period="weekly">Weekly</button>
              <button class="timeline-period-btn" data-period="monthly">Monthly</button>
              <button class="timeline-period-btn" data-period="yearly">Yearly</button>
            </div>
          </div>
          <div class="card-content chart-container">
            <canvas id="timelineChart"></canvas>
          </div>
        </div>
      </div>
    </div>

    <!-- Top 5 Sizes Chart -->
    <div class="grid-stack-item" gs-id="top-sizes" gs-x="12" gs-y="12" gs-w="6" gs-h="9" gs-min-w="4" gs-min-h="6">
      <div class="grid-stack-item-content">
        <div class="analytics-card chart-card">
          <div class="card-header">
            <span class="card-icon"><?= ICON_TRIANGULAR_RULER ?></span>
            <span class="card-title">Top 5 Sizes</span>
          </div>
          <div class="card-content chart-container">
            <div class="chart-with-legend">
              <div class="chart-canvas-container">
                <canvas id="topSizesChart"></canvas>
              </div>
              <div class="chart-legend-container" id="topSizesLegend"></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Turnaround Times -->
    <div class="grid-stack-item" gs-id="turnaround" gs-x="18" gs-y="12" gs-w="6" gs-h="12" gs-min-w="4" gs-min-h="6">
      <div class="grid-stack-item-content">
        <div class="analytics-card chart-card">
          <div class="card-header">
            <span class="card-icon"><?= ICON_HOURGLASS ?></span>
            <span class="card-title">Turnaround Times</span>
            <button id="turnaroundToggle" class="chart-toggle-btn">Show Revenue</button>
          </div>
          <div class="card-content">
            <div id="turnaroundList" class="turnaround-list"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Event Distribution -->
    <div class="grid-stack-item" gs-id="event-dist" gs-x="12" gs-y="21" gs-w="6" gs-h="9" gs-min-w="4" gs-min-h="6">
      <div class="grid-stack-item-content">
        <div class="analytics-card chart-card">
          <div class="card-header">
            <span class="card-icon"><?= ICON_CIRCUS_TENT ?></span>
            <span class="card-title">Event Distribution</span>
            <button id="eventPrefixToggle" class="chart-toggle-btn">Show Revenue</button>
          </div>
          <div class="card-content chart-container">
            <div class="chart-with-legend">
              <div class="chart-canvas-container">
                <canvas id="eventPrefixChart"></canvas>
              </div>
              <div class="chart-legend-container" id="eventPrefixLegend"></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Avg Turnaround Card -->
    <div class="grid-stack-item" gs-id="avg-turnaround" gs-x="0" gs-y="12" gs-w="4" gs-h="6" gs-min-w="4" gs-min-h="3">
      <div class="grid-stack-item-content">
        <div class="analytics-card compact">
          <div class="card-header"><span class="card-icon"><?= ICON_CLOCK ?></span><span class="card-title">Avg Turnaround</span></div>
          <div class="card-content">
            <div class="primary-metric" id="avgTurnaround"><?= $analytics['avg_turnaround_display'] ?></div>
            <div class="secondary-metric">Submit &#10142; Delivery</div>
          </div>
        </div>
      </div>
    </div>

    <!-- On-Time Delivery Card -->
    <div class="grid-stack-item" gs-id="on-time-delivery" gs-x="4" gs-y="12" gs-w="4" gs-h="6" gs-min-w="4" gs-min-h="3">
      <div class="grid-stack-item-content">
        <div class="analytics-card compact">
          <div class="card-header"><span class="card-icon"><?= ICON_CHECK_GREEN ?></span><span class="card-title">On-Time Delivery</span></div>
          <div class="card-content">
            <div class="primary-metric" id="onTimeRate" style="color: #059669;"><?= $analytics['on_time_rate'] ?>%</div>
            <div class="secondary-metric">Delivered before due date</div>
          </div>
        </div>
      </div>
    </div>

    <!-- File Issue Rate Card -->
    <div class="grid-stack-item" gs-id="file-issue-rate" gs-x="8" gs-y="12" gs-w="4" gs-h="6" gs-min-w="4" gs-min-h="3">
      <div class="grid-stack-item-content">
        <div class="analytics-card compact">
          <div class="card-header"><span class="card-icon"><?= ICON_WARNING ?></span><span class="card-title">File Issue Rate</span></div>
          <div class="card-content">
            <div class="primary-metric" id="fileIssueRate" <?= $analytics['file_issue_rate'] > 10 ? 'style="color: #dc2626;"' : '' ?>><?= $analytics['file_issue_rate'] ?>%</div>
            <div class="secondary-metric" id="fileIssueCount"><?= $analytics['file_issue_count'] ?> order(s) with issues</div>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>


</script>

<?php endif; ?>

<?php if ($isMtccStaff && $canViewMtccAnalytics):
  $readyCount = 0;
  $pickedUpToday = 0;
  $todayStr = date('Y-m-d');
  foreach ($orders as $o) {
    if (($o['status'] ?? '') === 'delivered') $readyCount++;
    if (($o['status'] ?? '') === 'pickedup') {
      $mod = $o['modified'] ?? 0;
      if ($mod && date('Y-m-d', $mod) === $todayStr) $pickedUpToday++;
    }
  }
  $onTimeRate = $mtccAnalytics['on_time_rate'] ?? 0;
  $onTimeColor = $onTimeRate >= 95 ? 'var(--success)' : ($onTimeRate >= 85 ? '#d97706' : 'var(--error)');
?>

<!-- MTCC Stats Row — revenue metrics -->
<div class="mtcc-stats-row">

  <!-- Today's Venue Fee -->
  <div class="mtcc-stat">
    <div class="mtcc-stat-top"><span class="mtcc-stat-label">Today's Venue Fee</span></div>
    <div class="mtcc-stat-value">$<?= number_format($mtccAnalytics['today_venue_fee'], 2) ?></div>
    <div class="mtcc-stat-sub"><?= $mtccAnalytics['today_orders'] ?> order<?= $mtccAnalytics['today_orders'] !== 1 ? 's' : '' ?> &middot; $<?= number_format($mtccAnalytics['today_base'], 2) ?> base</div>
  </div>

  <!-- This Week's Venue Fee -->
  <div class="mtcc-stat">
    <div class="mtcc-stat-top"><span class="mtcc-stat-label">This Week</span></div>
    <div class="mtcc-stat-value">$<?= number_format($mtccAnalytics['week_venue_fee'], 2) ?></div>
    <div class="mtcc-stat-sub"><?= $mtccAnalytics['week_orders'] ?> orders &middot; $<?= number_format($mtccAnalytics['week_base'], 2) ?> base</div>
  </div>

  <!-- Month-to-Date Venue Fee — the headline -->
  <div class="mtcc-stat mtcc-stat-primary">
    <div class="mtcc-stat-top"><span class="mtcc-stat-label">Venue Fee This Month</span></div>
    <div class="mtcc-stat-value">$<?= number_format($mtccAnalytics['month_venue_fee'], 2) ?></div>
    <div class="mtcc-stat-sub"><?= $mtccAnalytics['month_orders'] ?> orders &middot; $<?= number_format($mtccAnalytics['month_base'], 2) ?> base &middot; <?= $venueRatePct ?>%</div>
  </div>

  <!-- On-Time Rate -->
  <div class="mtcc-stat">
    <div class="mtcc-stat-top"><span class="mtcc-stat-label">On-Time Delivery</span></div>
    <div class="mtcc-stat-value" style="color: <?= $onTimeColor ?>;"><?= $onTimeRate ?>%</div>
    <div class="mtcc-stat-sub">Last 30 days</div>
  </div>

</div>
<?php endif; ?>

<!-- Pass data to JavaScript -->
<script>
<?php
// Sanitize orders for MTCC staff — strip vendor/COGS/PII, keep name + price
$jsOrders = $orders;
if ($isMtccStaff) {
    $jsOrders = array_map(function($o) {
        unset($o['customerInfo']['email']);
        unset($o['customerInfo']['phone']);
        unset($o['vendor_pricing']);
        unset($o['vendor_name']);
        unset($o['vendor_id']);
        if (isset($o['pricing'])) {
            unset($o['pricing']['cogs']);
            unset($o['pricing']['margin']);
        }
        return $o;
    }, $jsOrders);
}
?>
window.dashboardData = {
  analytics: <?= json_encode($isMtccStaff && isset($mtccAnalytics) ? $mtccAnalytics : $analytics) ?>,
  orders: <?= json_encode($jsOrders) ?>,
  activeEventPrefixes: <?= json_encode(array_keys($activeEventPrefixes)) ?>
};
</script>
	
<?php if ($isMtccStaff):
  $todayPickupCount = 0;
  $arrivingTodayCount = 0;
  $readyNowCount = 0;
  $overdueCount = 0;
  $issuesCount = 0;
  $todayDateStr = date('Y-m-d');
  foreach ($orders as $o) {
    $dueDate = $o['selectedDate'] ?? '';
    $status = $o['status'] ?? '';
    // Today's pickups: due today + ready/delivered/shipped (broad filter)
    if ($dueDate === $todayDateStr && in_array($status, ['ready', 'delivered', 'shipped'])) {
      $todayPickupCount++;
    }
    // Arriving today: courier en route to MTCC, due today
    if ($dueDate === $todayDateStr && in_array($status, ['shipped', 'dispatched', 'ready'])) {
      $arrivingTodayCount++;
    }
    // Ready now: at MTCC awaiting customer pickup
    if ($status === 'delivered') {
      $readyNowCount++;
      // Overdue: ready but past due date
      if ($dueDate && $dueDate < $todayDateStr) $overdueCount++;
    }
    // Issues: missing, unclaimed, file_issue
    if (in_array($status, ['missing', 'unclaimed', 'file_issue'])) {
      $issuesCount++;
    }
  }
?>

<!-- MTCC Refresh Banner — appears when new orders arrive via auto-polling -->
<div id="mtccRefreshBanner" class="mtcc-refresh-banner" style="display:none;" onclick="location.reload()"></div>

<!-- MTCC Live Status Cards — operational at-a-glance, clickable filters -->
<div class="mtcc-live-cards">

  <button class="mtcc-live-card mtcc-live-amber" data-mtcc-filter="arriving" onclick="mtccFilterLive('arriving', event)">
    <div class="mtcc-live-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
    </div>
    <div class="mtcc-live-text">
      <div class="mtcc-live-label-main">Arriving Today</div>
      <div class="mtcc-live-label-sub">Couriers en route</div>
    </div>
    <div class="mtcc-live-count"><?= $arrivingTodayCount ?></div>
  </button>

  <button class="mtcc-live-card mtcc-live-green" data-mtcc-filter="ready" onclick="mtccFilterLive('ready', event)">
    <div class="mtcc-live-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
    </div>
    <div class="mtcc-live-text">
      <div class="mtcc-live-label-main">Ready for Pickup</div>
      <div class="mtcc-live-label-sub">At MTCC now</div>
    </div>
    <div class="mtcc-live-count"><?= $readyNowCount ?></div>
  </button>

  <button class="mtcc-live-card mtcc-live-red<?= $overdueCount === 0 ? ' mtcc-live-muted' : '' ?>" data-mtcc-filter="overdue" onclick="mtccFilterLive('overdue', event)">
    <div class="mtcc-live-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <div class="mtcc-live-text">
      <div class="mtcc-live-label-main">Overdue</div>
      <div class="mtcc-live-label-sub">Past due date</div>
    </div>
    <div class="mtcc-live-count"><?= $overdueCount ?></div>
  </button>

  <button class="mtcc-live-card mtcc-live-grey<?= $issuesCount === 0 ? ' mtcc-live-muted' : '' ?>" data-mtcc-filter="issues" onclick="mtccFilterLive('issues', event)">
    <div class="mtcc-live-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    </div>
    <div class="mtcc-live-text">
      <div class="mtcc-live-label-main">Issues</div>
      <div class="mtcc-live-label-sub">Missing / unclaimed</div>
    </div>
    <div class="mtcc-live-count"><?= $issuesCount ?></div>
  </button>

</div>

<!-- Active filter banner — shows when a Live Status filter is applied -->
<div id="mtccFilterBanner" class="mtcc-filter-banner" style="display:none;">
  <div class="mtcc-filter-banner-left">
    <span class="mtcc-filter-banner-label">Filtering:</span>
    <span id="mtccFilterBannerText" class="mtcc-filter-banner-text"></span>
    <span id="mtccFilterBannerCount" class="mtcc-filter-banner-count"></span>
  </div>
  <button class="mtcc-filter-banner-clear" onclick="mtccClearFilters()">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px; margin-right:4px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    Clear Filter
  </button>
</div>

<?php
// Build list of unique event prefixes with counts for the event filter dropdown
$mtccEventList = [];
foreach ($orders as $o) {
  $prefix = strtoupper(explode('-', $o['referenceCode'] ?? '')[0]);
  if (!$prefix) continue;
  if (!isset($mtccEventList[$prefix])) {
    $evName = $o['event']['name'] ?? $o['event']['acronym'] ?? $prefix;
    $mtccEventList[$prefix] = ['name' => $evName, 'count' => 0];
  }
  $mtccEventList[$prefix]['count']++;
}
ksort($mtccEventList);
?>

<!-- MTCC Quick-Access Toolbar: Search + Scan + Event Filter + Today's Pickups -->
<div class="mtcc-toolbar">
  <div class="mtcc-toolbar-search">
    <span class="mtcc-toolbar-search-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    </span>
    <input type="text" class="mtcc-toolbar-input" id="mtccSearchInput" placeholder="Customer is here to pick up &mdash; search by name or order #...">
  </div>
  <select class="mtcc-toolbar-select" id="mtccEventFilter" onchange="mtccApplyEventBuildingFilters()" title="Filter by event">
    <option value="">All Events</option>
    <?php foreach ($mtccEventList as $prefix => $ev): ?>
    <option value="<?= htmlspecialchars($prefix) ?>"><?= htmlspecialchars($ev['name']) ?> (<?= $ev['count'] ?>)</option>
    <?php endforeach; ?>
  </select>
  <select class="mtcc-toolbar-select mtcc-toolbar-select-narrow" id="mtccBuildingFilter" onchange="mtccApplyEventBuildingFilters()" title="Filter by building">
    <option value="">Both Buildings</option>
    <option value="north">North Building</option>
    <option value="south">South Building</option>
  </select>
  <button class="mtcc-toolbar-btn mtcc-toolbar-btn-scan" onclick="mtccOpenScanner()" title="Scan order barcode">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px; vertical-align:-3px;"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="7" y1="12" x2="17" y2="12"/></svg>
    Scan
  </button>
  <button class="mtcc-toolbar-btn mtcc-toolbar-btn-primary" onclick="mtccFilterTodayPickups()">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px; vertical-align:-3px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    Today's Pickups
    <span class="mtcc-toolbar-count"><?= $todayPickupCount ?></span>
  </button>
  <button class="mtcc-toolbar-btn" onclick="mtccPrintPickupList()" title="Print today's pickup list">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px; vertical-align:-3px;"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
    Print List
  </button>
  <button class="mtcc-toolbar-btn" onclick="mtccClearFilters()">Clear</button>
</div>

<!-- Empty state when a filter returns 0 rows -->
<div id="mtccEmptyState" class="mtcc-empty-state" style="display:none;">
  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
  <div class="mtcc-empty-title">No orders match this filter</div>
  <div class="mtcc-empty-sub">Try a different filter or clear to see all orders.</div>
  <button class="mtcc-toolbar-btn" onclick="mtccClearFilters()">Clear Filter</button>
</div>

<!-- Barcode Scanner Modal -->
<div id="mtccScannerModal" class="mtcc-scanner-modal" style="display:none;">
  <div class="mtcc-scanner-backdrop" onclick="mtccCloseScanner()"></div>
  <div class="mtcc-scanner-box">
    <div class="mtcc-scanner-header">
      <h3>Scan Order Barcode</h3>
      <button class="mtcc-scanner-close" onclick="mtccCloseScanner()">&times;</button>
    </div>
    <div class="mtcc-scanner-body">
      <div id="mtccScannerView" class="mtcc-scanner-view"></div>
      <div class="mtcc-scanner-hint">Point the camera at the barcode on the customer's order confirmation</div>
      <div id="mtccScannerStatus" class="mtcc-scanner-status"></div>
    </div>
  </div>
</div>

<!-- QuaggaJS for barcode scanning -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/quagga/0.12.1/quagga.min.js"></script>

<script>
// Forward MTCC search input to existing search box with live filtering
(function(){
  var mtccSearch = document.getElementById('mtccSearchInput');
  if (!mtccSearch) return;
  mtccSearch.addEventListener('input', function() {
    var searchBox = document.getElementById('searchBox');
    if (searchBox) {
      searchBox.value = mtccSearch.value;
      searchBox.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });
  // Focus the search bar on page load for quick access
  setTimeout(function(){ mtccSearch.focus(); }, 100);
})();

// Filter to today's pickups: due today AND status in [ready, delivered, shipped]
function mtccFilterTodayPickups() {
  var today = new Date();
  var y = today.getFullYear();
  var m = String(today.getMonth() + 1).padStart(2, '0');
  var d = String(today.getDate()).padStart(2, '0');
  var todayStr = y + '-' + m + '-' + d;

  // Bypass simpleFilterManager's pagination + eventsMode
  mtccTakeOverTable();

  var matchCount = 0;
  var rows = document.querySelectorAll('#ordersTableBody tr');
  rows.forEach(function(row) {
    var dueDate = row.dataset.duedate || '';
    var status = row.dataset.status || '';
    var show = (dueDate === todayStr) && (status === 'ready' || status === 'delivered' || status === 'shipped');
    row.classList.toggle('filtered-out', !show);
    row.style.display = show ? '' : 'none';
    if (show) matchCount++;
  });

  var table = document.getElementById('ordersTable');
  if (table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });

  document.querySelectorAll('.mtcc-toolbar-btn-primary').forEach(function(b) { b.classList.add('mtcc-toolbar-active'); });
  document.querySelectorAll('.mtcc-live-card').forEach(function(c) { c.classList.remove('mtcc-live-active'); });

  // Show banner
  var banner = document.getElementById('mtccFilterBanner');
  var text = document.getElementById('mtccFilterBannerText');
  var count = document.getElementById('mtccFilterBannerCount');
  if (banner && text) {
    text.textContent = "Today's Pickups";
    if (count) count.textContent = '(' + matchCount + ' order' + (matchCount !== 1 ? 's' : '') + ')';
    banner.style.display = 'flex';
  }
}

// ===== MTCC Live Updates: Auto-refresh + audio alert for new arrivals =====
(function() {
  var perms = window.PERMS || {};
  if (!perms.isMtccStaff) return; // admin view unchanged

  // Audio context for subtle chime when new arrivals detected
  var audioCtx = null;
  function chime() {
    try {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      // Two-note chime: rising C-E
      [523.25, 659.25].forEach(function(freq, i) {
        var osc = audioCtx.createOscillator();
        var gain = audioCtx.createGain();
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.type = 'sine';
        osc.frequency.value = freq;
        var start = audioCtx.currentTime + i * 0.18;
        gain.gain.setValueAtTime(0, start);
        gain.gain.linearRampToValueAtTime(0.12, start + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.001, start + 0.35);
        osc.start(start);
        osc.stop(start + 0.4);
      });
    } catch (e) { /* silent fail if audio blocked */ }
  }

  // Track known refs so we can detect what's new
  var knownRefs = new Set();
  if (window.dashboardData && window.dashboardData.orders) {
    window.dashboardData.orders.forEach(function(o) {
      if (o.referenceCode && (o.status === 'delivered' || o.status === 'pickedup')) {
        knownRefs.add(o.referenceCode);
      }
    });
  }

  function checkForUpdates() {
    fetch(window.location.pathname + '?check_new_orders=1', { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data || !data.orders) return;
        var newlyDelivered = [];
        data.orders.forEach(function(o) {
          if (o.status === 'delivered' && o.referenceCode && !knownRefs.has(o.referenceCode)) {
            newlyDelivered.push(o.referenceCode);
            knownRefs.add(o.referenceCode);
          }
        });
        if (newlyDelivered.length > 0) {
          chime();
          if (typeof showNotification === 'function') {
            showNotification(newlyDelivered.length + ' new order' + (newlyDelivered.length > 1 ? 's' : '') + ' arrived at MTCC: ' + newlyDelivered.slice(0, 3).join(', '), 'success');
          }
          // Flag page so the user can refresh; avoid auto-reload which would disrupt interactions
          var banner = document.getElementById('mtccRefreshBanner');
          if (banner) {
            banner.textContent = '↻ ' + newlyDelivered.length + ' new order' + (newlyDelivered.length > 1 ? 's' : '') + ' arrived — click to refresh';
            banner.style.display = 'block';
          }
        }
      })
      .catch(function() { /* silent */ });
  }

  // Poll every 45 seconds (cache-friendly, low server load)
  setInterval(checkForUpdates, 45000);
})();

// ===== MTCC Session Timeout Warning =====
(function() {
  var perms = window.PERMS || {};
  if (!perms.role) return;
  // Admin session is 8 hours. Warn at 7h 55min (5 min before expiry).
  var SESSION_MINUTES = 8 * 60;
  var WARN_MINUTES = SESSION_MINUTES - 5;
  setTimeout(function() {
    if (confirm('Your session will expire in 5 minutes. Click OK to stay logged in.')) {
      // Ping server to refresh session
      fetch(window.location.pathname + '?check_new_orders=1', { credentials: 'same-origin' });
    }
  }, WARN_MINUTES * 60 * 1000);
})();

// MTCC Print Order via row action menu — uses the slideout's clean print template
// (without flashing the slideout open). Bypasses printOrderFromMenu which loads
// the full order detail URL (blocked for MTCC staff).
function mtccPrintOrderFromMenu(referenceCode) {
  if (typeof closeAllMenus === 'function') closeAllMenus();
  if (window.OrderSlideout && typeof window.OrderSlideout.printByRef === 'function') {
    window.OrderSlideout.printByRef(referenceCode);
  } else if (window.OrderSlideout && typeof window.OrderSlideout.open === 'function') {
    // Fallback: open then print, then close
    window.OrderSlideout.open(referenceCode);
    setTimeout(function() {
      if (window.OrderSlideout.print) window.OrderSlideout.print();
      setTimeout(function() { if (window.OrderSlideout.close) window.OrderSlideout.close(); }, 200);
    }, 100);
  } else {
    alert('Print is not available right now. Please open the order details and print from there.');
  }
}

// ===== MTCC Printable Daily Pickup List =====
// Clean, paper-friendly list of orders Ready for Pickup with checkboxes for manual use.
function mtccPrintPickupList() {
  if (!window.dashboardData || !window.dashboardData.orders) return;

  var today = new Date();
  var todayStr = today.getFullYear() + '-' + String(today.getMonth()+1).padStart(2,'0') + '-' + String(today.getDate()).padStart(2,'0');

  // Gather orders ready for pickup (status=delivered)
  var ready = [];
  window.dashboardData.orders.forEach(function(o) {
    if (o.status === 'delivered') ready.push(o);
  });

  function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
  function fmtDueDate(s) { if (!s) return '—'; var d = new Date(s); return isNaN(d) ? s : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); }

  // Group orders by event acronym (prefix)
  var groups = {}; // { 'CPMA': { name, acronym, building, orders: [...] } }
  var overdueCount = 0, onTimeCount = 0;

  ready.forEach(function(o) {
    var acronym = ((o.event || {}).acronym || (o.referenceCode || '').split('-')[0] || 'Other').toUpperCase();
    var fullName = (o.event || {}).name || acronym;
    var building = ((o.event || {}).building || o.building || '').toLowerCase();
    var isPastDue = o.selectedDate && o.selectedDate < todayStr;
    if (isPastDue) overdueCount++; else onTimeCount++;

    if (!groups[acronym]) {
      groups[acronym] = { acronym: acronym, name: fullName, building: building, orders: [] };
    }
    groups[acronym].orders.push(o);
  });

  // Sort events: events with overdue items first, then alphabetical
  var sortedGroups = Object.values(groups);
  sortedGroups.forEach(function(g) {
    // Sort orders within each group: overdue first, then by due date
    g.orders.sort(function(a, b) {
      var aOverdue = a.selectedDate && a.selectedDate < todayStr;
      var bOverdue = b.selectedDate && b.selectedDate < todayStr;
      if (aOverdue !== bOverdue) return aOverdue ? -1 : 1;
      var da = a.selectedDate || '9999-12-31';
      var db = b.selectedDate || '9999-12-31';
      if (da !== db) return da < db ? -1 : 1;
      return (a.referenceCode || '').localeCompare(b.referenceCode || '');
    });
    // Mark if group has any overdue
    g.hasOverdue = g.orders.some(function(o) { return o.selectedDate && o.selectedDate < todayStr; });
  });
  sortedGroups.sort(function(a, b) {
    if (a.hasOverdue !== b.hasOverdue) return a.hasOverdue ? -1 : 1;
    return a.name.localeCompare(b.name);
  });

  // Build a table per event group
  var eventTables = '';
  if (sortedGroups.length === 0) {
    eventTables = '<div class="pl-empty-state">No orders currently ready for pickup.</div>';
  } else {
    sortedGroups.forEach(function(g) {
      var buildingLabel = g.building === 'south' ? 'MTCC South' : (g.building === 'north' ? 'MTCC North' : '');
      var groupOverdue = g.orders.filter(function(o) { return o.selectedDate && o.selectedDate < todayStr; }).length;

      var groupRows = '';
      g.orders.forEach(function(o) {
        var ref = esc(o.referenceCode || '');
        var name = esc((o.customerInfo || {}).name || '');
        var due = esc(fmtDueDate(o.selectedDate));
        var w = (o.dimensions || {}).width || '?';
        var h = (o.dimensions || {}).height || '?';
        var isPastDue = o.selectedDate && o.selectedDate < todayStr;

        groupRows +=
          '<tr' + (isPastDue ? ' class="pl-overdue"' : '') + '>' +
            '<td class="pl-check"></td>' +
            '<td class="pl-ref">#' + ref + '</td>' +
            '<td class="pl-cust">' + name + '</td>' +
            '<td class="pl-due">' + due + (isPastDue ? ' <span class="pl-badge">OVERDUE</span>' : '') + '</td>' +
            '<td class="pl-size">' + w + '&quot; &times; ' + h + '&quot;</td>' +
            '<td class="pl-notes"></td>' +
          '</tr>';
      });

      eventTables +=
        '<div class="pl-group">' +
          '<div class="pl-group-header">' +
            '<div class="pl-group-title"><span class="pl-group-acronym">' + esc(g.acronym) + '</span> ' + esc(g.name) + '</div>' +
            '<div class="pl-group-meta">' +
              (buildingLabel ? '<span class="pl-group-bldg">' + buildingLabel + '</span>' : '') +
              '<span class="pl-group-count">' + g.orders.length + ' order' + (g.orders.length !== 1 ? 's' : '') +
                (groupOverdue > 0 ? ' · <span class="pl-group-overdue">' + groupOverdue + ' overdue</span>' : '') +
              '</span>' +
            '</div>' +
          '</div>' +
          '<table>' +
            '<thead><tr>' +
              '<th></th>' +
              '<th>Order #</th>' +
              '<th>Customer</th>' +
              '<th>Due</th>' +
              '<th>Size</th>' +
              '<th>Notes</th>' +
            '</tr></thead>' +
            '<tbody>' + groupRows + '</tbody>' +
          '</table>' +
        '</div>';
    });
  }

  var dateLong = today.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
  var timestamp = today.toLocaleString();

  var html = '<!DOCTYPE html><html><head><meta charset="UTF-8">' +
    '<title>Pickup List — ' + today.toLocaleDateString() + '</title>' +
    '<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">' +
    '<style>' +
      '* { box-sizing: border-box; margin: 0; padding: 0; }' +
      'body { font-family: Montserrat, -apple-system, BlinkMacSystemFont, Arial, sans-serif; color: #1e1b2e; background: white; padding: 20px 28px; font-size: 10pt; line-height: 1.3; }' +

      /* Header */
      '.pl-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; padding-bottom: 12px; border-bottom: 2px solid #1e1b2e; margin-bottom: 14px; }' +
      '.pl-brand img { max-width: 260px; height: auto; display: block; }' +
      '.pl-brand-fallback { font-size: 17pt; font-weight: 700; color: #7c3aed; letter-spacing: 1px; }' +
      '.pl-brand-fallback small { display: block; font-size: 9pt; font-weight: 500; color: #6b7280; letter-spacing: 0.3px; }' +
      '.pl-title { text-align: right; }' +
      '.pl-title h1 { font-size: 14pt; font-weight: 700; color: #7c3aed; letter-spacing: 0.5px; margin-bottom: 2px; }' +
      '.pl-title .pl-date { font-size: 9pt; color: #1e1b2e; font-weight: 600; }' +

      /* Summary bar */
      '.pl-summary { display: flex; gap: 24px; padding: 8px 14px; background: #faf8ff; border-radius: 4px; margin-bottom: 14px; }' +
      '.pl-stat { display: flex; align-items: baseline; gap: 8px; }' +
      '.pl-stat-label { font-size: 8pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #6b7280; }' +
      '.pl-stat-value { font-size: 13pt; font-weight: 700; color: #1e1b2e; line-height: 1; }' +
      '.pl-stat-value.red { color: #dc2626; }' +

      /* Event group header */
      '.pl-group { margin-bottom: 18px; page-break-inside: auto; }' +
      '.pl-group-header { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; padding: 6px 8px; background: #1e1b2e; color: white; border-radius: 3px 3px 0 0; }' +
      '.pl-group-title { font-size: 10pt; font-weight: 700; letter-spacing: 0.3px; }' +
      '.pl-group-acronym { display: inline-block; padding: 1px 6px; background: #7c3aed; color: white; border-radius: 2px; font-family: "Courier New", monospace; font-size: 9pt; font-weight: 700; margin-right: 6px; }' +
      '.pl-group-meta { font-size: 8pt; color: #d1d5db; display: flex; gap: 12px; align-items: baseline; }' +
      '.pl-group-bldg { font-weight: 600; letter-spacing: 0.3px; }' +
      '.pl-group-count { font-size: 8pt; }' +
      '.pl-group-overdue { color: #fca5a5; font-weight: 700; }' +

      /* Table — single-line rows, 9pt */
      'table { width: 100%; border-collapse: collapse; }' +
      'thead tr { border-bottom: 1.5px solid #1e1b2e; }' +
      'th { text-align: left; padding: 5px 4px; font-size: 7pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #6b7280; background: #f3f4f6; }' +
      'td { padding: 7px 4px; border-bottom: 1px solid #e5e7eb; vertical-align: middle; font-size: 9pt; white-space: nowrap; }' +

      /* Zebra striping */
      'tbody tr:nth-child(even) td { background: #f9fafb; }' +

      /* Columns */
      '.pl-check { width: 16px; padding: 0 !important; text-align: center; }' +
      '.pl-check::before { content: ""; display: inline-block; width: 11px; height: 11px; border: 1.5px solid #1e1b2e; border-radius: 2px; vertical-align: middle; }' +
      '.pl-ref { font-family: "Courier New", monospace; font-weight: 700; color: #7c3aed; padding-left: 8px !important; }' +
      '.pl-cust { font-weight: 600; }' +
      '.pl-size { font-family: "Courier New", monospace; font-size: 8.5pt; }' +
      '.pl-notes { border-left: 1px dashed #d1d5db; min-width: 120px; }' +

      /* Overdue cue */
      '.pl-overdue .pl-due { color: #dc2626; font-weight: 700; }' +
      '.pl-badge { display: inline-block; padding: 0 4px; border-radius: 2px; background: #dc2626; color: white; font-size: 6.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; margin-left: 3px; vertical-align: 1px; }' +

      '.pl-empty-state { text-align: center; color: #9ca3af; padding: 32px 12px; font-style: italic; }' +

      /* Footer / signature */
      '.pl-signoff { margin-top: 20px; padding-top: 12px; border-top: 1px solid #e5e7eb; display: flex; gap: 32px; }' +
      '.pl-signoff-field { flex: 1; }' +
      '.pl-signoff-label { font-size: 8pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #6b7280; margin-bottom: 18px; }' +
      '.pl-signoff-line { border-bottom: 1px solid #1e1b2e; }' +
      '.pl-footer { margin-top: 14px; text-align: center; font-size: 7pt; color: #9ca3af; letter-spacing: 0.3px; }' +

      /* Print behavior */
      '@media print { body { padding: 10mm 12mm; } @page { size: letter; margin: 0; } thead { display: table-header-group; } tr { page-break-inside: avoid; } }' +
    '</style></head><body>' +

    '<div class="pl-head">' +
      '<div class="pl-brand">' +
        '<img src="/mtcc-ps-logo.png" alt="MTCC + Print Stuff" onerror="this.outerHTML=\'<div class=pl-brand-fallback>PRINT STUFF<small>MTCC Print Services</small></div>\'">' +
      '</div>' +
      '<div class="pl-title">' +
        '<h1>Pickup List</h1>' +
        '<div class="pl-date">' + dateLong + '</div>' +
      '</div>' +
    '</div>' +

    '<div class="pl-summary">' +
      '<div class="pl-stat"><div class="pl-stat-label">Ready for Pickup</div><div class="pl-stat-value">' + ready.length + '</div></div>' +
      '<div class="pl-stat"><div class="pl-stat-label">On Time</div><div class="pl-stat-value">' + onTimeCount + '</div></div>' +
      '<div class="pl-stat"><div class="pl-stat-label">Overdue</div><div class="pl-stat-value' + (overdueCount > 0 ? ' red' : '') + '">' + overdueCount + '</div></div>' +
    '</div>' +

    eventTables +

    '<div class="pl-signoff">' +
      '<div class="pl-signoff-field"><div class="pl-signoff-label">Staff Signature</div><div class="pl-signoff-line"></div></div>' +
      '<div class="pl-signoff-field"><div class="pl-signoff-label">Completed Date/Time</div><div class="pl-signoff-line"></div></div>' +
    '</div>' +

    '<div class="pl-footer">Generated ' + timestamp + ' &middot; Print Stuff &middot; Metro Toronto Convention Centre</div>' +

    '<script>window.addEventListener("load", function(){ setTimeout(function(){ window.print(); }, 300); });</scr' + 'ipt>' +

    '</body></html>';

  var w = window.open('', '_blank', 'width=900,height=1100');
  w.document.write(html);
  w.document.close();
}

// ===== MTCC Issue Reporting =====
// Opens a prompt to report a problem with an order (damage, mismatch, etc.).
// Sends an email to admin via a small endpoint.
function mtccReportIssue(referenceCode) {
  var description = prompt(
    'Report an issue with #' + referenceCode + '\n\n' +
    'Describe the problem (damage, wrong size, missing item, customer complaint, etc.).\n' +
    'Print Stuff will be notified by email.',
    ''
  );
  if (description === null) return;
  description = (description || '').trim();
  if (description === '') return;

  var formData = new FormData();
  formData.append('mtcc_report_issue', '1');
  formData.append('reference_code', referenceCode);
  formData.append('description', description);

  fetch(window.location.pathname, { method: 'POST', body: formData, credentials: 'same-origin' })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data && data.success) {
        if (typeof showNotification === 'function') {
          showNotification('Issue reported to Print Stuff. Thank you.', 'success');
        } else {
          alert('Issue reported to Print Stuff.');
        }
      } else {
        var err = (data && data.error) || 'Unknown error';
        if (typeof showNotification === 'function') showNotification('Failed to report issue: ' + err, 'error');
        else alert('Failed to report issue: ' + err);
      }
    })
    .catch(function() {
      if (typeof showNotification === 'function') showNotification('Network error. Please try again.', 'error');
      else alert('Network error. Please try again.');
    });
}

// Quick pickup — one-click mark-as-picked-up for MTCC staff
function mtccQuickPickup(referenceCode) {
  // Close any open action menu
  if (typeof closeActionMenu === 'function') closeActionMenu();

  // Look up customer name for the prompt
  var customerName = '';
  if (window.dashboardData && window.dashboardData.orders) {
    var order = window.dashboardData.orders.find(function(o){
      return (o.referenceCode || '').toUpperCase() === referenceCode.toUpperCase();
    });
    if (order) customerName = (order.customerInfo || {}).name || '';
  }

  var pickupPerson = prompt(
    'Mark #' + referenceCode + ' as picked up.\n\nEnter the name of the person picking up.\nLeave blank if same as customer (' + (customerName || 'customer') + ').',
    ''
  );
  if (pickupPerson === null) return; // cancelled

  pickupPerson = (pickupPerson || '').trim();
  var extras = {};
  if (pickupPerson !== '') extras.pickup_person = pickupPerson;

  if (typeof updateOrderStatus === 'function') {
    updateOrderStatus(referenceCode, 'pickedup',
      function(data) {
        if (typeof updateQuickStatusBadgeData === 'function') updateQuickStatusBadgeData(referenceCode, 'pickedup');
        if (typeof showNotification === 'function') showNotification('Order #' + referenceCode + ' marked as picked up', 'success');
        // Update the row's status data attribute so subsequent filters work
        var row = document.querySelector('#ordersTableBody tr[data-reference="' + referenceCode.toLowerCase() + '"]');
        if (row) row.dataset.status = 'pickedup';
      },
      function(err) {
        if (typeof showNotification === 'function') showNotification('Pickup failed: ' + err, 'error');
        else alert('Pickup failed: ' + err);
      },
      extras
    );
  }
}

// Quick mark delivered — one-click mark-received at MTCC
function mtccQuickDelivered(referenceCode) {
  if (typeof closeActionMenu === 'function') closeActionMenu();

  if (!confirm('Mark #' + referenceCode + ' as received at MTCC?')) return;

  if (typeof updateOrderStatus === 'function') {
    updateOrderStatus(referenceCode, 'delivered',
      function(data) {
        if (typeof updateQuickStatusBadgeData === 'function') updateQuickStatusBadgeData(referenceCode, 'delivered');
        if (typeof showNotification === 'function') showNotification('Order #' + referenceCode + ' marked as received at MTCC', 'success');
        var row = document.querySelector('#ordersTableBody tr[data-reference="' + referenceCode.toLowerCase() + '"]');
        if (row) row.dataset.status = 'delivered';
      },
      function(err) {
        if (typeof showNotification === 'function') showNotification('Failed: ' + err, 'error');
        else alert('Failed: ' + err);
      }
    );
  }
}

// Filter the order table by event + building (both combined via AND logic)
function mtccApplyEventBuildingFilters() {
  var evSel = document.getElementById('mtccEventFilter');
  var bldSel = document.getElementById('mtccBuildingFilter');
  var prefix = evSel ? evSel.value : '';
  var building = bldSel ? bldSel.value : '';

  mtccTakeOverTable();

  var rows = document.querySelectorAll('#ordersTableBody tr');
  var matchCount = 0;
  rows.forEach(function(row) {
    var rowPrefix = (row.dataset.reference || '').toUpperCase().split('-')[0];
    var rowBuilding = (row.dataset.building || '').toLowerCase();
    var matchEvent = !prefix || rowPrefix === prefix;
    var matchBuilding = !building || rowBuilding === building;
    var show = matchEvent && matchBuilding;
    row.classList.toggle('filtered-out', !show);
    row.style.display = show ? '' : 'none';
    if (show) matchCount++;
  });

  // Clear other active states
  document.querySelectorAll('.mtcc-live-card').forEach(function(c) { c.classList.remove('mtcc-live-active'); });
  if (typeof mtccActiveFilters !== 'undefined') mtccActiveFilters.clear();
  document.querySelectorAll('.mtcc-toolbar-btn-primary').forEach(function(b) { b.classList.remove('mtcc-toolbar-active'); });

  // Banner — build label from active filters
  var banner = document.getElementById('mtccFilterBanner');
  var text = document.getElementById('mtccFilterBannerText');
  var count = document.getElementById('mtccFilterBannerCount');
  var parts = [];
  if (prefix) {
    var evLabel = evSel && evSel.selectedOptions[0] ? evSel.selectedOptions[0].textContent.split(' (')[0] : prefix;
    parts.push('Event: ' + evLabel);
  }
  if (building) {
    parts.push('Building: MTCC ' + building.charAt(0).toUpperCase() + building.slice(1));
  }

  if (parts.length && banner && text) {
    text.textContent = parts.join(' + ');
    if (count) count.textContent = '(' + matchCount + ' order' + (matchCount !== 1 ? 's' : '') + ')';
    banner.style.display = 'flex';
  } else if (banner) {
    banner.style.display = 'none';
  }
}

// MTCC takes over the table display — disables simpleFilterManager's pagination/eventsMode
// so chip filters can show all matching orders across active AND archived events.
function mtccTakeOverTable() {
  if (!window.simpleFilterManager) return;
  var fm = window.simpleFilterManager;
  fm.eventsMode = 'all';
  if (fm.pagination) fm.pagination.perPage = 'all';
  if (fm.filters) {
    if (fm.filters.priority && fm.filters.priority.clear) fm.filters.priority.clear();
    if (fm.filters.status && fm.filters.status.clear) fm.filters.status.clear();
    if (fm.filters.duedate && fm.filters.duedate.clear) fm.filters.duedate.clear();
    if (fm.filters.prefix && fm.filters.prefix.clear) fm.filters.prefix.clear();
    fm.filters.search = '';
  }
  // Apply once so simpleFilterManager marks all rows visible, then our filter below overrides
  if (typeof fm.applyFilters === 'function') fm.applyFilters();
}

function mtccClearFilters(silent) {
  // Reset active filter tracking
  if (typeof mtccActiveFilters !== 'undefined') mtccActiveFilters.clear();

  // Show all rows — remove .filtered-out class and inline display
  var rows = document.querySelectorAll('#ordersTableBody tr');
  rows.forEach(function(row) {
    row.classList.remove('filtered-out');
    row.style.display = '';
  });

  var mtccSearch = document.getElementById('mtccSearchInput');
  if (mtccSearch) mtccSearch.value = '';
  var searchBox = document.getElementById('searchBox');
  if (searchBox) { searchBox.value = ''; }
  var eventSelect = document.getElementById('mtccEventFilter');
  if (eventSelect) eventSelect.value = '';
  var buildingSelect = document.getElementById('mtccBuildingFilter');
  if (buildingSelect) buildingSelect.value = '';

  // Reset active card/button state (skip if called silently from a toggle that already handled it)
  if (!silent) {
    document.querySelectorAll('.mtcc-live-card').forEach(function(c) { c.classList.remove('mtcc-live-active'); });
  }
  document.querySelectorAll('.mtcc-toolbar-btn-primary').forEach(function(b) { b.classList.remove('mtcc-toolbar-active'); });

  // Hide banner
  var banner = document.getElementById('mtccFilterBanner');
  if (banner) banner.style.display = 'none';

  // Hide empty state
  var empty = document.getElementById('mtccEmptyState');
  if (empty) empty.style.display = 'none';
}

// Live Status filter — multi-select toggle. Tracks active categories in a Set.
var mtccActiveFilters = new Set();

function mtccRowMatchesCategory(row, category, todayStr) {
  var dueDate = row.dataset.duedate || '';
  var status = row.dataset.status || '';
  if (category === 'arriving') {
    return (dueDate === todayStr) && (status === 'shipped' || status === 'dispatched' || status === 'ready');
  }
  if (category === 'ready') return (status === 'delivered');
  if (category === 'overdue') return (status === 'delivered') && dueDate && dueDate < todayStr;
  if (category === 'issues') return (status === 'missing' || status === 'unclaimed' || status === 'file_issue');
  return false;
}

function mtccFilterLive(category, evt) {
  // Toggle the filter on/off
  if (mtccActiveFilters.has(category)) {
    mtccActiveFilters.delete(category);
  } else {
    mtccActiveFilters.add(category);
  }

  // Update card visual states
  document.querySelectorAll('.mtcc-live-card').forEach(function(c) {
    var cat = c.getAttribute('data-mtcc-filter');
    c.classList.toggle('mtcc-live-active', mtccActiveFilters.has(cat));
  });

  // If no filters active, clear and return
  if (mtccActiveFilters.size === 0) {
    mtccClearFilters(true); // silent = don't touch cards (already done above)
    return;
  }

  mtccApplyLiveFilters();
}

function mtccApplyLiveFilters() {
  var today = new Date();
  var y = today.getFullYear();
  var m = String(today.getMonth() + 1).padStart(2, '0');
  var d = String(today.getDate()).padStart(2, '0');
  var todayStr = y + '-' + m + '-' + d;

  mtccTakeOverTable();

  var labels = {
    arriving: 'Arriving Today',
    ready: 'Ready for Pickup',
    overdue: 'Overdue',
    issues: 'Issues'
  };

  var matchCount = 0;
  var rows = document.querySelectorAll('#ordersTableBody tr');
  rows.forEach(function(row) {
    // OR logic — row matches if it matches ANY active category
    var show = false;
    mtccActiveFilters.forEach(function(cat) {
      if (mtccRowMatchesCategory(row, cat, todayStr)) show = true;
    });
    row.classList.toggle('filtered-out', !show);
    row.style.display = show ? '' : 'none';
    if (show) matchCount++;
  });

  // Banner — show list of active filter names
  var banner = document.getElementById('mtccFilterBanner');
  var text = document.getElementById('mtccFilterBannerText');
  var count = document.getElementById('mtccFilterBannerCount');
  if (banner && text) {
    var activeLabels = [];
    mtccActiveFilters.forEach(function(cat) { activeLabels.push(labels[cat] || cat); });
    text.textContent = activeLabels.join(' + ');
    if (count) count.textContent = '(' + matchCount + ' order' + (matchCount !== 1 ? 's' : '') + ')';
    banner.style.display = 'flex';
  }

  mtccToggleEmptyState(matchCount);

  var table = document.getElementById('ordersTable');
  if (table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Show/hide empty-state message when a filter returns 0 rows
function mtccToggleEmptyState(matchCount) {
  var empty = document.getElementById('mtccEmptyState');
  if (!empty) return;
  empty.style.display = matchCount === 0 ? 'block' : 'none';
}

// ===== Barcode Scanner =====
var mtccScannerRunning = false;

function mtccOpenScanner() {
  var modal = document.getElementById('mtccScannerModal');
  var status = document.getElementById('mtccScannerStatus');
  if (!modal) return;
  modal.style.display = 'flex';
  modal.style.alignItems = 'center';
  modal.style.justifyContent = 'center';
  status.textContent = 'Initializing camera...';
  status.className = 'mtcc-scanner-status';

  if (typeof Quagga === 'undefined') {
    status.textContent = 'Scanner library not loaded. Please refresh and try again.';
    status.className = 'mtcc-scanner-status error';
    return;
  }

  Quagga.init({
    inputStream: {
      type: 'LiveStream',
      target: document.getElementById('mtccScannerView'),
      constraints: {
        facingMode: 'environment',
        width: { min: 480 },
        height: { min: 320 }
      }
    },
    decoder: {
      readers: ['code_128_reader', 'code_39_reader', 'ean_reader', 'upc_reader']
    },
    locate: true
  }, function(err) {
    if (err) {
      console.error('Quagga init error:', err);
      status.textContent = 'Camera access denied or unavailable.';
      status.className = 'mtcc-scanner-status error';
      return;
    }
    Quagga.start();
    mtccScannerRunning = true;
    status.textContent = 'Point the camera at a barcode...';
  });

  // Handle successful detection
  Quagga.onDetected(mtccOnBarcodeDetected);
}

function mtccOnBarcodeDetected(result) {
  if (!result || !result.codeResult || !result.codeResult.code) return;
  var code = result.codeResult.code.toUpperCase().trim();

  var status = document.getElementById('mtccScannerStatus');
  status.textContent = 'Detected: ' + code;
  status.className = 'mtcc-scanner-status success';

  // Check that the scanned code looks like an order reference (PREFIX-### format)
  var isOrderRef = /^[A-Z0-9]+-\d+$/i.test(code);
  if (!isOrderRef) {
    status.textContent = 'Not a valid order code: ' + code;
    status.className = 'mtcc-scanner-status error';
    return;
  }

  // Stop scanner and apply search
  setTimeout(function() {
    mtccCloseScanner();
    var mtccSearch = document.getElementById('mtccSearchInput');
    if (mtccSearch) {
      mtccSearch.value = code;
      mtccSearch.dispatchEvent(new Event('input', { bubbles: true }));
      mtccSearch.focus();
    }
    // Scroll to table
    var table = document.getElementById('ordersTable');
    if (table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }, 400);
}

function mtccCloseScanner() {
  var modal = document.getElementById('mtccScannerModal');
  if (modal) modal.style.display = 'none';
  if (mtccScannerRunning && typeof Quagga !== 'undefined') {
    try {
      Quagga.offDetected(mtccOnBarcodeDetected);
      Quagga.stop();
    } catch (e) {
      console.warn('Scanner stop error:', e);
    }
    mtccScannerRunning = false;
  }
}

// Close scanner on escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    var modal = document.getElementById('mtccScannerModal');
    if (modal && modal.style.display !== 'none') mtccCloseScanner();
  }
});
</script>
<?php endif; ?>

<!-- Table Card Wrapper -->
<div class="table-card-wrapper">

<!-- Table Header Bar -->
<div class="table-header-bar">
  <div class="table-header-left">
    <h2 class="table-title">
      <span class="table-title-main">Orders</span>
      <span class="table-title-divider">|</span>
      <span class="table-title-mode" id="tableTitleMode">Active Events</span>
    </h2>
    <div id="resultsSummary" class="results-summary-inline">
      (<strong id="showingCount"><?= count($orders) ?></strong> of <strong id="totalCount"><?= count($orders) ?></strong>)
    </div>
    <span id="selectionCountBadge" class="selection-count-badge">
      <span id="selectionCount">0</span> Selected
      <button class="clear-selection" onclick="clearAllSelections()" title="Clear selection"><?= ICON_CROSS ?></button>
    </span>
    <div id="activeFilterChips" class="active-filter-chips"></div>
  </div>
  <div class="table-header-right">
    <input type="text" id="searchBox" class="search-box-compact" placeholder="<?= ICON_EYE ?> Search...">
    <select id="perPageSelect" class="per-page-select-compact">
      <option value="25">25 rows</option>
      <option value="50">50 rows</option>
      <option value="100">100 rows</option>
      <option value="all">All</option>
    </select>
    <?php if (!$isMtccStaff): ?>
    <div class="view-toggle-group">
      <button class="view-toggle-btn active" id="tableViewBtn" onclick="switchView('table')" title="Table View">&#9776; Table</button>
      <button class="view-toggle-btn" id="boardViewBtn" onclick="switchView('board')" title="Board View">&#9638; Board</button>
    </div>
    <button id="filtersToggleBtn" class="table-header-btn-soft" onclick="toggleFiltersPanel()">
      <span><?= ICON_LABEL ?></span> Filters <span id="filterCountBadge" class="filter-count-badge" style="display: none;">0</span> <span id="filtersToggleIcon"><?= SYMBOL_ARROW_UP ?></span>
    </button>
    <?php endif; ?>
    <?php if ($canCreateOrders): ?><a href="admin-create-order.php" class="create-order-btn-soft">+ Create Order</a><?php endif; ?>
    <button id="actionsMenuBtn" class="actions-menu-btn" onclick="toggleActionsMenu(event)" title="Actions Menu">
      <span class="actions-menu-icon"><?= SYMBOL_DOTS_VERTICAL ?></span>
    </button>
  </div>
</div>

<!-- Collapsible Filters Container (hidden for MTCC staff) -->
<?php if (!$isMtccStaff): ?>
<div id="filtersContainer" class="filters-container collapsed">
  <div class="filters-header-row">
    <span class="filters-title"><?= ICON_LABEL ?> Filter Orders</span>
    <div class="filter-presets-group">
      <select id="filterPresetSelect" class="filter-preset-select" onchange="loadFilterPreset(this.value)">
        <option value="">Saved Presets...</option>
      </select>
      <button class="filter-preset-save-btn" onclick="saveFilterPreset()" title="Save current filters as preset"><?= ICON_SAVE ?> Save</button>
      <button class="filter-preset-delete-btn" id="deletePresetBtn" onclick="deleteFilterPreset()" title="Delete selected preset" style="display:none"><?= ICON_CROSS ?></button>
    </div>
    <button id="clearFiltersBtn" class="clear-filters-btn" disabled><?= ICON_CROSS ?> Clear All Filters</button>
  </div>
  
  <!-- Event Prefix Filters -->
<div class="controls-section">
  <div class="controls-header">
    <span class="controls-title"><?= ICON_CALENDAR ?>  Event Filters</span>
    <span id="prefixFilterCount" class="active-filters-count" style="display: none;">0</span>
  </div>
  <div class="filter-row" id="prefixFilters">
    <?php
    // Extract unique prefixes from orders
    $prefixes = [];
    foreach ($orders as $order) {
      if (isset($order['referenceCode'])) {
        $prefix = strtoupper(explode('-', $order['referenceCode'])[0]);
        if (!isset($prefixes[$prefix])) {
          $prefixes[$prefix] = 0;
        }
        $prefixes[$prefix]++;
      }
    }
    ksort($prefixes); // Sort alphabetically
    
    foreach ($prefixes as $prefix => $count):
    ?>
    <button class="filter-btn prefix-filter" 
            data-filter-type="prefix" 
            data-filter-value="<?= $prefix ?>">
      <?= $prefix ?>
      <span class="filter-count"><?= $count ?></span>
    </button>
    <?php endforeach; ?>
  </div>
</div>
	
  
  <!-- Priority Filters -->
  <div class="controls-section">
    <div class="controls-header">
      <span class="controls-title"><?= ICON_SIREN ?> Priority Filters</span>
      <span id="priorityFilterCount" class="active-filters-count" style="display: none;">0</span>
    </div>
    <div class="filter-row" id="priorityFilters">
      <?php foreach ($priorityConfig as $key => $config): ?>
      <button class="filter-btn <?= $config['class'] ?>" 
              data-filter-type="priority" 
              data-filter-value="<?= $key ?>">
        <?= $config['label'] ?>
        <span class="filter-count"><?= $filterAnalytics['priority'][$key] ?? 0 ?></span>
      </button>
      <?php endforeach; ?>
    </div>
  </div>
  
  <!-- Status Filters -->
  <div class="controls-section">
    <div class="controls-header">
      <span class="controls-title"><?= ICON_FLAG ?>&copy; Status Filters</span>
      <span id="statusFilterCount" class="active-filters-count" style="display: none;">0</span>
    </div>
    <div class="filter-row" id="statusFilters">
      <?php foreach ($statusConfig as $key => $config): ?>
      <button class="filter-btn status-<?= $key ?>" 
              data-filter-type="status" 
              data-filter-value="<?= $key ?>">
        <?= $config['label'] ?>
        <span class="filter-count"><?= $filterAnalytics['status'][$key] ?? 0 ?></span>
      </button>
      <?php endforeach; ?>
    </div>
  </div>


</div>
<?php endif; /* !$isMtccStaff filters container */ ?>

<?php if (empty($orders)): ?>
<div class="no-orders">
  <h3>No orders yet</h3>
  <p>Orders will appear here once customers submit them.</p>
</div>
<?php else: ?>
<!-- Kanban Board View (hidden for MTCC staff) -->
<?php if (!$isMtccStaff): ?>
<div id="kanbanContainer" style="display: none;"></div>
<?php endif; ?>

<div class="orders-table-container<?= $isMtccStaff ? ' mtcc-view' : '' ?>">
  <table class="orders-table" id="ordersTable">
    <thead>
      <tr>
        <th class="checkbox-column">
          <input type="checkbox" id="selectAllOrders" class="select-all-checkbox" title="Select all orders on this page">
        </th>
        <th class="sortable" data-sort="reference">Order #</th>
        <th class="sortable" data-sort="priority">Priority</th>
        <th class="sortable" data-sort="customer">Customer</th>
        <?php if ($isMtccStaff): ?><th class="sortable" data-sort="event">Event</th><?php endif; ?>
        <th class="date-column-header" id="dateSortHeader">
          <div class="date-sort-toggle">
            <button class="date-sort-btn" data-sort="deadline">Due Date</button>
            <button class="date-sort-btn active" data-sort="unpaid">Submitted</button>
          </div>
        </th>
        <th class="sortable" data-sort="size">Size</th>
        <th class="sortable" data-sort="price">Price</th>
        <?php if ($canViewVendor): ?><th class="sortable" data-sort="vendor">Vendor</th><?php endif; ?>
        <th class="sortable" data-sort="status">Status</th>
        <th class="actions-column">
          <div class="column-config-wrapper">
            <button class="col-config-btn" onclick="toggleColumnConfig()" title="Customize columns"><?= SYMBOL_DOTS_VERTICAL ?></button>
            <div class="column-config-dropdown" id="columnConfigDropdown" style="display:none">
              <div class="col-config-title">Show/Hide Columns</div>
              <label class="col-config-item"><input type="checkbox" data-col="1" checked onchange="toggleColumn(1, this.checked)"> Order #</label>
              <label class="col-config-item"><input type="checkbox" data-col="2" checked onchange="toggleColumn(2, this.checked)"> Priority</label>
              <label class="col-config-item"><input type="checkbox" data-col="3" checked onchange="toggleColumn(3, this.checked)"> Customer</label>
              <label class="col-config-item"><input type="checkbox" data-col="4" checked onchange="toggleColumn(4, this.checked)"> Due Date</label>
              <label class="col-config-item"><input type="checkbox" data-col="5" checked onchange="toggleColumn(5, this.checked)"> Size</label>
              <label class="col-config-item"><input type="checkbox" data-col="6" checked onchange="toggleColumn(6, this.checked)"> Price</label>
              <label class="col-config-item"><input type="checkbox" data-col="7" checked onchange="toggleColumn(7, this.checked)"> Status</label>
            </div>
          </div>
        </th>
      </tr>
    </thead>
    <tbody id="ordersTableBody">
      <?php
      // Build map: event prefix → building name (for row data attribute + column display)
      $mtccEventBuilding = [];
      $mtccEventNameByPrefix = [];
      if ($isMtccStaff) {
          $allEventsForMap = array_merge($eventsData['active'] ?? [], $eventsData['archived'] ?? []);
          foreach ($allEventsForMap as $ev) {
              $pfx = strtoupper($ev['acronym'] ?? '');
              if (!$pfx) continue;
              $mtccEventBuilding[$pfx] = strtolower($ev['building'] ?? '');
              $mtccEventNameByPrefix[$pfx] = $ev['name'] ?? $pfx;
          }
      }
      ?>
      <?php
      // ============================================================
      // MTCC staff: sort orders by operational urgency (not submitted date)
      // 1. Overdue (delivered + past due) → customer is late
      // 2. Ready for Pickup (delivered, oldest first) → waiting longest = top priority
      // 3. Arriving Today (shipped/dispatched/ready due today)
      // 4. Issues (missing/unclaimed/file_issue)
      // 5. Everything else, sorted by due date ascending
      // ============================================================
      $ordersForTable = $orders;
      if ($isMtccStaff) {
          $mtccTodayStr = date('Y-m-d');
          $getUrgencyRank = function($o) use ($mtccTodayStr) {
              $status = $o['status'] ?? '';
              $due = $o['selectedDate'] ?? '';
              if ($status === 'delivered' && $due && $due < $mtccTodayStr) return 1; // Overdue
              if ($status === 'delivered') return 2; // Ready for Pickup
              if ($due === $mtccTodayStr && in_array($status, ['shipped', 'dispatched', 'ready'])) return 3; // Arriving today
              if (in_array($status, ['missing', 'unclaimed', 'file_issue'])) return 4; // Issues
              return 5; // Everything else
          };
          usort($ordersForTable, function($a, $b) use ($getUrgencyRank) {
              $rankA = $getUrgencyRank($a);
              $rankB = $getUrgencyRank($b);
              if ($rankA !== $rankB) return $rankA - $rankB;
              // Within same urgency bucket, sort by due date ascending (soonest first)
              $dueA = $a['selectedDate'] ?? '9999-12-31';
              $dueB = $b['selectedDate'] ?? '9999-12-31';
              return strcmp($dueA, $dueB);
          });
      }
      ?>
      <?php foreach ($ordersForTable as $order): ?>
      <?php
      $orderStatus = $order['status'] ?? 'unpaid';
      $currentStatus = $statusConfig[$orderStatus] ?? $statusConfig['unpaid'];

      // Determine if order is "new"
      // Admin: new = unpaid orders (just submitted, not yet paid)
      // MTCC: new = delivered at MTCC within last 24 hours, not yet picked up
      if ($isMtccStaff) {
        $modifiedTime = $order['modified'] ?? 0;
        $isNew = ($orderStatus === 'delivered') && $modifiedTime && ((time() - $modifiedTime) < 86400);
      } else {
        $isNew = ($orderStatus === 'unpaid');
      }

      // MTCC urgency category for row coloring
      $mtccUrgency = '';
      if ($isMtccStaff) {
        $due = $order['selectedDate'] ?? '';
        $today = date('Y-m-d');
        if ($orderStatus === 'delivered' && $due && $due < $today) $mtccUrgency = 'overdue';
        elseif ($orderStatus === 'delivered') $mtccUrgency = 'ready';
        elseif ($due === $today && in_array($orderStatus, ['shipped', 'dispatched', 'ready'])) $mtccUrgency = 'arriving';
        elseif (in_array($orderStatus, ['missing', 'unclaimed', 'file_issue'])) $mtccUrgency = 'issue';
      }

      // Extract turnaround type from pricing tier
      $turnaroundClass = 'standard';
      $turnaroundLabel = 'Standard';
      if ( isset( $order[ 'pricing' ][ 'tier' ] ) ) {
        $tier = strtolower( $order[ 'pricing' ][ 'tier' ] );

        if ( strpos( $tier, 'last minute' ) !== false ) {
          $turnaroundClass = 'lastminute';
          $turnaroundLabel = 'Last Minute';
        } elseif ( strpos( $tier, 'early' ) !== false ) {
          $turnaroundClass = 'early';
          $turnaroundLabel = 'Early';
        } elseif ( strpos( $tier, 'rush' ) !== false ) {
          $turnaroundClass = 'rush';
          $turnaroundLabel = 'Rush';
        } elseif ( strpos( $tier, 'urgent' ) !== false ) {
          $turnaroundClass = 'urgent';
          $turnaroundLabel = 'Urgent';
        } elseif ( strpos( $tier, 'critical' ) !== false ) {
          $turnaroundClass = 'critical';
          $turnaroundLabel = 'Critical';
        }
      }
      ?>
      <?php
      $orderPrefix = strtoupper(explode('-', $order['referenceCode'] ?? '')[0]);
      $orderBuilding = $mtccEventBuilding[$orderPrefix] ?? ($order['event']['building'] ?? $order['building'] ?? '');
      $orderEventName = $mtccEventNameByPrefix[$orderPrefix] ?? ($order['event']['name'] ?? $order['event']['acronym'] ?? $orderPrefix);
      ?>
      <tr class="<?= $order['status'] ?? 'unpaid' ?><?= $mtccUrgency ? ' mtcc-row-' . $mtccUrgency : '' ?>"
                            data-reference="<?= strtolower($order['referenceCode'] ?? '') ?>"
                            data-customer="<?= strtolower($order['customerInfo']['name'] ?? '') ?>"
                            <?php if (!$isMtccStaff): ?>data-email="<?= strtolower($order['customerInfo']['email'] ?? '') ?>"<?php endif; ?>
                            data-status="<?= $order['status'] ?? 'unpaid' ?>"
                            data-priority="<?= $turnaroundClass ?>"
                            data-submitted="<?= isset($order['submittedAt']) ? strtotime($order['submittedAt']) : 0 ?>"
                            data-deadline="<?= isset($order['selectedDate']) ? strtotime($order['selectedDate']) : 0 ?>"
                            data-duedate="<?= $order['selectedDate'] ?? '' ?>"
                            data-value="<?= $order['pricing']['total'] ?? 0 ?>"
                            data-event-name="<?= strtolower($orderEventName) ?>"
                            data-building="<?= htmlspecialchars($orderBuilding) ?>"
                            <?php if (!$isMtccStaff): ?>data-cogs="<?= isset($order['vendor_pricing']['total']) ? $order['vendor_pricing']['total'] : 0 ?>"<?php endif; ?>
                            data-event-status="<?= isset($activeEventPrefixes[strtoupper(explode('-', $order['referenceCode'] ?? '')[0])]) ? 'active' : 'archived' ?>"> 
        
        <!-- Checkbox Column -->
        <td class="checkbox-column">
          <input type="checkbox" class="order-checkbox" data-reference="<?= htmlspecialchars($order['referenceCode'] ?? '') ?>">
        </td>
        
        <!-- Order Number Column -->
        <td class="order-number-cell"><?php
          $filePreviewPath = '';
          if (!empty($order['uploadedFile']['path'])) {
              $filePreviewPath = $order['uploadedFile']['path'];
          } elseif (!empty($order['uploadedFile']['savedName'])) {
              $filePreviewPath = 'uploads/files/' . $order['uploadedFile']['savedName'];
          }
          $hasPreview = $filePreviewPath && file_exists($filePreviewPath) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $filePreviewPath);
          ?>
          <?php if ($isNew): ?>
          <div class="new-badge"> <a href="javascript:void(0)" class="order-number" onclick="OrderSlideout.open('<?= htmlspecialchars($order['referenceCode'] ?? '') ?>')"<?php if ($hasPreview): ?> data-preview="<?= htmlspecialchars($filePreviewPath) ?>"<?php endif; ?>>
            <?= htmlspecialchars($order['referenceCode'] ?? 'Unknown') ?>
            </a> </div>
          <?php else: ?>
          <a href="javascript:void(0)" class="order-number" onclick="OrderSlideout.open('<?= htmlspecialchars($order['referenceCode'] ?? '') ?>')"<?php if ($hasPreview): ?> data-preview="<?= htmlspecialchars($filePreviewPath) ?>"<?php endif; ?>>
          <?= htmlspecialchars($order['referenceCode'] ?? 'Unknown') ?>
          </a>
          <?php endif; ?></td>
        
        <!-- Priority Column -->
        <td><span class="priority-indicator <?= $turnaroundClass ?>">
          <?= $turnaroundLabel ?>
          </span></td>
        
        <!-- Customer Column -->
        <td>
          <div class="cell-main"><?= htmlspecialchars($order['customerInfo']['name'] ?? 'Unknown') ?></div>
          <?php if (!$isMtccStaff): ?><div class="cell-micro"><?= htmlspecialchars($order['customerInfo']['email'] ?? '') ?></div><?php endif; ?>
        </td>

        <!-- Event Column (MTCC only) -->
        <?php if ($isMtccStaff): ?>
        <td>
          <div class="cell-main"><?= htmlspecialchars($orderEventName) ?></div>
          <?php if ($orderBuilding): ?><div class="cell-micro">MTCC <?= ucfirst(htmlspecialchars($orderBuilding)) ?></div><?php endif; ?>
        </td>
        <?php endif; ?>

        <!-- Due Date Column -->
        <td>
          <div class="cell-main">
            <?php
            $deliveryTimeDisplay = '';
            if (isset($order['deliveryTime']) && $order['deliveryTime'] !== 'anytime') {
              $timeLabels = ['9am' => '9:00am', '12pm' => '12:00pm', '3pm' => '3:00pm', '6pm' => '6:00pm'];
              $deliveryTimeDisplay = ' at ' . ($timeLabels[$order['deliveryTime']] ?? $order['deliveryTime']);
            } else {
              $deliveryTimeDisplay = ' at anytime';
            }
            $selectedDate = $order['selectedDate'] ?? null;
            echo $selectedDate ? date('D, M j, Y', strtotime($selectedDate)) . $deliveryTimeDisplay : 'No date';
            ?>
          </div>
          <div class="cell-micro">Submitted: <?= isset($order['submittedAt']) ? date('D, M j, Y g:i A', strtotime($order['submittedAt'])) : 'Unknown' ?></div>
        </td>
        
        <!-- Size Column -->
        <td>
          <div class="cell-main"><?= $order['dimensions']['width'] ?? '?' ?>"  x  <?= $order['dimensions']['height'] ?? '?' ?>"</div>
          <div class="cell-micro"><?= ($order['material'] ?? 'poster') === 'fabric' ? 'Fabric' : 'Poster paper' ?></div>
        </td>
        
        <!-- Price Column -->
        <td>$
          <?= number_format($order['pricing']['total'] ?? 0, 2) ?></td>
        
        <!-- Vendor Column (god_mode/super_admin only) -->
        <?php if ($canViewVendor): ?>
        <td class="vendor-cell">
          <div class="cell-main"><?= htmlspecialchars($order['vendor_name'] ?? '') ?: '<span class="vendor-unassigned">Unassigned</span>' ?></div>
        </td>
        <?php endif; ?>

        <!-- Status Column -->
        <td><?php
        $statusIcons = [
          'unpaid' => '<?= ICON_HOURGLASS ?>',
          'paid' => '<?= ICON_MONEY_BAG ?>',
          'file_issue' => '<?= ICON_EYE ?>',
          'printing' => '<?= ICON_PRINTER ?>',
          'preflight' => '<?= ICON_EYE ?>',
          'ready' => '<?= ICON_PACKAGE ?>',
          'dispatched' => '<?= ICON_TRUCK ?>',
          'shipped' => '<?= ICON_TRUCK ?>',
          'delivered' => '<?= ICON_PACKAGE ?>',
          'pickedup' => '<?= ICON_CHECK_GREEN ?>',
          'unclaimed' => '<?= ICON_MAILBOX ?>',
          'missing' => '<?= ICON_WARNING ?>',
          'cancelled' => '<?= ICON_CROSS ?>',
          'refunded' => '<?= ICON_SIREN ?>'
        ];
        $orderStatus = $order['status'] ?? 'unpaid';
        $orderRefCode = $order['referenceCode'] ?? '';
        ?>
          <div class="status-badge-wrapper">
            <span class="status-badge status-badge-clickable status-<?= $orderStatus ?>"
                  data-current-status="<?= $orderStatus ?>"
                  onclick="toggleQuickStatusDropdown(event, '<?= $orderRefCode ?>', '<?= $orderStatus ?>')">
              <?= $statusIcons[$orderStatus] ?? '<?= ICON_MEMO ?>' ?>
              <?= $statusConfig[$orderStatus]['label'] ?? 'Unknown' ?>
            </span>
          </div></td>
        
        <!-- Actions Column - SANDWICH MENU -->
        <td><div class="action-menu-container">
            <button class="action-menu-trigger" onclick="toggleActionMenu(event, '<?= $orderRefCode ?>')"
                title="Order Actions"> <span class="menu-icon"><?= SYMBOL_DOTS_VERTICAL ?></span> </button>
            <div class="action-menu-dropdown" id="menu_<?= htmlspecialchars($orderRefCode) ?>">
              <?php if ($isMtccStaff && $orderStatus === 'delivered'): ?>
              <!-- Quick Pickup for MTCC (only when order is ready for pickup) -->
              <div class="menu-section">
                <button class="menu-item menu-item-primary" onclick="mtccQuickPickup('<?= htmlspecialchars($orderRefCode) ?>')"><span class="menu-icon"><?= ICON_CHECK_GREEN ?></span> <span>Mark Picked Up</span></button>
              </div>
              <div class="menu-divider"></div>
              <?php elseif ($isMtccStaff && in_array($orderStatus, ['shipped', 'dispatched', 'ready'])): ?>
              <!-- Quick Mark Delivered for MTCC (when order is en route but not yet logged as at MTCC) -->
              <div class="menu-section">
                <button class="menu-item menu-item-primary" onclick="mtccQuickDelivered('<?= htmlspecialchars($orderRefCode) ?>')"><span class="menu-icon"><?= ICON_PACKAGE ?></span> <span>Mark Received at MTCC</span></button>
              </div>
              <div class="menu-divider"></div>
              <?php endif; ?>
              <!-- View & Edit Section -->
              <div class="menu-section">
                <?php if ($isMtccStaff): ?>
                <button class="menu-item" onclick="OrderSlideout.open('<?= htmlspecialchars($orderRefCode) ?>')"> <span class="menu-icon"><?= ICON_USER ?></span> <span>View Details</span> </button>
                <?php else: ?>
                <a href="?view=<?= urlencode($orderRefCode) ?>" class="menu-item"> <span class="menu-icon"><?= ICON_USER ?></span> <span>View Details</span> </a>
                <?php endif; ?>
                <?php if ($canEditOrders): ?>
                <a href="?view=<?= urlencode($orderRefCode) ?>&edit=1" class="menu-item"> <span class="menu-icon"><?= ICON_PENCIL ?></span> <span>Edit Order</span> </a>
                <?php endif; ?>
              </div>
              <div class="menu-divider"></div>

              <!-- File & Print Section -->
              <div class="menu-section">
                <?php if (!$isMtccStaff && isset($order['uploadedFile'])): ?>
                <a href="?download=<?= urlencode($orderRefCode) ?>" class="menu-item"> <span class="menu-icon"><?= ICON_DOWNLOAD ?></span> <span>Download File</span> </a>
                <?php endif; ?>
                <?php if ($isMtccStaff): ?>
                <button class="menu-item" onclick="mtccPrintOrderFromMenu('<?= $orderRefCode ?>')"> <span class="menu-icon"><?= ICON_PRINTER ?></span> <span>Print Order</span> </button>
                <?php else: ?>
                <button class="menu-item" onclick="printOrderFromMenu('<?= $orderRefCode ?>')"> <span class="menu-icon"><?= ICON_PRINTER ?></span> <span>Print Order</span> </button>
                <?php endif; ?>
                <?php if (!$isMtccStaff): ?>
                <button class="menu-item" onclick="printLabelFromMenu('<?= $orderRefCode ?>')"> <span class="menu-icon"><?= ICON_PACKAGE ?></span> <span>Print Label</span> </button>
                <?php endif; ?>
              </div>

              <?php if ($canDeleteOrders): ?>
              <div class="menu-divider"></div>
              <!-- Danger Section -->
              <div class="menu-section">
                <button class="menu-item danger" onclick="deleteOrderFromMenu('<?= $orderRefCode ?>')"> <span class="menu-icon"><?= ICON_PENCIL ?></span> <span>Delete Order</span> </button>
              </div>
              <?php endif; ?>
            </div>
          </div></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
</div> <!-- End table-card-wrapper -->
<?php endif; ?>
	
<!-- Bulk Action Sticky Toolbar (matches Production dock style) -->
<div class="queue-bulk-dock" id="bulkActionToolbar">
  <div class="queue-bulk-inner">
    <span class="queue-bulk-count" id="bulkToolbarCount">0</span>
    <span class="queue-bulk-label">selected</span>
    <button class="queue-bulk-btn queue-bulk-push" onclick="bulkChangeStatus()">Change Status</button>
    <?php if (!$isMtccStaff): ?>
    <button class="queue-bulk-btn queue-bulk-batch" onclick="printSelectedOrders()">Print Labels</button>
    <?php endif; ?>
    <button class="queue-bulk-btn" onclick="exportSelectedOrders()" style="background: rgba(255,255,255,0.1); color: white;">Export CSV</button>
    <?php if (!$isMtccStaff): ?>
    <button class="queue-bulk-btn" onclick="downloadSelectedFiles()" style="background: rgba(255,255,255,0.1); color: white;">Download Files</button>
    <?php endif; ?>
    <?php if ($canDeleteOrders): ?>
    <button class="queue-bulk-btn queue-bulk-issue" onclick="bulkDeleteOrders()">Delete</button>
    <?php endif; ?>
    <button class="queue-bulk-clear" onclick="clearAllSelections()">&#10005;</button>
  </div>
</div>

<!-- Remove the old scripts and use only this simple one -->
<script src="js/simple-filters.js"></script>
<script src="js/admin-bulk-selection.js"></script>
<script src="js/admin-actions-menu.js"></script>

<!-- SimpleFilterManager is initialized by admin-dashboard.js DashboardController -->

<script>
// Filters panel toggle (standalone function)
// Column Customization — show/hide table columns
var COL_CONFIG_KEY = 'mtcc_column_config';

function toggleColumnConfig() {
  var dd = document.getElementById('columnConfigDropdown');
  if (dd) dd.style.display = dd.style.display === 'none' ? '' : 'none';
}

// Close dropdown on outside click
document.addEventListener('click', function(e) {
  if (!e.target.closest('.column-config-wrapper')) {
    var dd = document.getElementById('columnConfigDropdown');
    if (dd) dd.style.display = 'none';
  }
});

function toggleColumn(colIndex, visible) {
  // colIndex is 1-based (skipping checkbox col 0 and actions col 9)
  // Actual table column index: colIndex maps to nth-child(colIndex + 1) since checkbox is col 0
  var actualIndex = colIndex + 1; // +1 because checkbox is first col
  var table = document.getElementById('ordersTable');
  if (!table) return;

  var cells = table.querySelectorAll('th:nth-child(' + actualIndex + '), td:nth-child(' + actualIndex + ')');
  cells.forEach(function(cell) {
    cell.style.display = visible ? '' : 'none';
  });

  // Save to localStorage
  var config = getColumnConfig();
  config[colIndex] = visible;
  localStorage.setItem(COL_CONFIG_KEY, JSON.stringify(config));
}

function getColumnConfig() {
  try {
    return JSON.parse(localStorage.getItem(COL_CONFIG_KEY)) || {};
  } catch(e) { return {}; }
}

function applyColumnConfig() {
  var config = getColumnConfig();
  Object.keys(config).forEach(function(col) {
    var colIndex = parseInt(col);
    if (!config[colIndex]) {
      toggleColumn(colIndex, false);
      // Uncheck the checkbox
      var checkbox = document.querySelector('.column-config-dropdown input[data-col="' + colIndex + '"]');
      if (checkbox) checkbox.checked = false;
    }
  });
}

// Apply saved column config on load
document.addEventListener('DOMContentLoaded', applyColumnConfig);

// Bulk action toolbar — show/hide based on selection count
(function() {
  var toolbar = document.getElementById('bulkActionToolbar');
  var countEl = document.getElementById('bulkToolbarCount');
  if (!toolbar) return;

  // Watch for selection changes via MutationObserver on the selection badge
  var selectionBadge = document.getElementById('selectionCountBadge');
  if (selectionBadge) {
    var observer = new MutationObserver(function() {
      var countSpan = document.getElementById('selectionCount');
      var count = countSpan ? parseInt(countSpan.textContent) || 0 : 0;
      if (count > 0) {
        toolbar.classList.add('visible');
        if (countEl) countEl.textContent = count;
      } else {
        toolbar.classList.remove('visible');
      }
    });
    observer.observe(selectionBadge, { attributes: true, childList: true, subtree: true });
  }

  // Also listen for checkbox changes directly
  document.addEventListener('change', function(e) {
    if (e.target.classList.contains('order-checkbox') || e.target.id === 'selectAllOrders') {
      setTimeout(function() {
        var checked = document.querySelectorAll('.order-checkbox:checked').length;
        if (checked > 0) {
          toolbar.classList.add('visible');
          if (countEl) countEl.textContent = checked;
        } else {
          toolbar.classList.remove('visible');
        }
      }, 50);
    }
  });
})();

// File preview tooltip on order number hover
(function() {
  var tooltip = null;

  function createTooltip() {
    tooltip = document.createElement('div');
    tooltip.className = 'file-preview-tooltip';
    tooltip.innerHTML = '<img src="" alt="Preview">';
    document.body.appendChild(tooltip);
  }

  document.addEventListener('mouseover', function(e) {
    var link = e.target.closest('.order-number[data-preview]');
    if (!link) return;

    if (!tooltip) createTooltip();
    var img = tooltip.querySelector('img');
    img.src = link.dataset.preview;
    tooltip.style.display = 'block';

    var rect = link.getBoundingClientRect();
    var top = rect.bottom + 8;
    var left = rect.left;

    // Keep within viewport
    if (top + 400 > window.innerHeight) top = rect.top - 408;
    if (left + 400 > window.innerWidth) left = window.innerWidth - 410;

    tooltip.style.top = top + 'px';
    tooltip.style.left = left + 'px';
  });

  document.addEventListener('mouseout', function(e) {
    var link = e.target.closest('.order-number[data-preview]');
    if (link && tooltip) tooltip.style.display = 'none';
  });
})();

/* Event tab bar and Today filter removed — using filters panel instead */

// View toggle — switch between table and kanban board
function switchView(view) {
  var tableBtn = document.getElementById('tableViewBtn');
  var boardBtn = document.getElementById('boardViewBtn');

  if (view === 'board') {
    KanbanBoard.show();
    if (tableBtn) tableBtn.classList.remove('active');
    if (boardBtn) boardBtn.classList.add('active');
  } else {
    KanbanBoard.hide();
    if (tableBtn) tableBtn.classList.add('active');
    if (boardBtn) boardBtn.classList.remove('active');
  }
}

// Smart Alerts — filter by alert type
function filterByAlert(filterType) {
  // Expand filters panel if collapsed
  var filtersContainer = document.getElementById('filtersContainer');
  if (filtersContainer && filtersContainer.classList.contains('collapsed')) {
    toggleFiltersPanel();
  }

  if (filterType === 'pastdue' || filterType === 'duetoday') {
    // These need special handling — sort by due date and scroll to table
    var table = document.getElementById('ordersTable');
    if (table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });

    // For past due / due today, filter by searching the date in the table
    var searchBox = document.getElementById('searchBox');
    if (filterType === 'duetoday') {
      var today = new Date();
      var monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      searchBox.value = monthNames[today.getMonth()] + ' ' + today.getDate();
      searchBox.dispatchEvent(new Event('input', { bubbles: true }));
    }
  } else if (filterType.startsWith('status-')) {
    // Click the corresponding status filter button
    var status = filterType.replace('status-', '');
    var filterBtn = document.querySelector('.filter-btn.status-' + status);
    if (filterBtn && !filterBtn.classList.contains('active')) {
      filterBtn.click();
    }
  }
}

// ===== FILTER PRESETS =====
var PRESET_STORAGE_KEY = 'mtcc_filter_presets';

function getFilterPresets() {
  try {
    return JSON.parse(localStorage.getItem(PRESET_STORAGE_KEY)) || {};
  } catch (e) { return {}; }
}

function populatePresetSelect() {
  var select = document.getElementById('filterPresetSelect');
  if (!select) return;
  var presets = getFilterPresets();
  var names = Object.keys(presets);

  // Keep the default option, remove the rest
  select.innerHTML = '<option value="">Saved Presets... (' + names.length + ')</option>';
  names.forEach(function(name) {
    var opt = document.createElement('option');
    opt.value = name;
    opt.textContent = name;
    select.appendChild(opt);
  });
}

function saveFilterPreset() {
  if (!window.filterManager) return;

  var name = prompt('Name this filter preset:');
  if (!name || !name.trim()) return;
  name = name.trim();

  var state = {
    priority: Array.from(window.filterManager.filters.priority),
    status: Array.from(window.filterManager.filters.status),
    prefix: Array.from(window.filterManager.filters.prefix),
    search: window.filterManager.filters.search || '',
    eventsMode: window.filterManager.eventsMode || 'active'
  };

  var presets = getFilterPresets();
  presets[name] = state;
  localStorage.setItem(PRESET_STORAGE_KEY, JSON.stringify(presets));
  populatePresetSelect();

  if (typeof showNotification === 'function') {
    showNotification('Preset "' + name + '" saved', 'success');
  }
}

function loadFilterPreset(name) {
  if (!name || !window.filterManager) {
    document.getElementById('deletePresetBtn').style.display = 'none';
    return;
  }

  var presets = getFilterPresets();
  var preset = presets[name];
  if (!preset) return;

  // Clear existing filters
  window.filterManager.filters.priority.clear();
  window.filterManager.filters.status.clear();
  window.filterManager.filters.prefix.clear();
  window.filterManager.filters.search = '';

  // Apply saved filters
  if (preset.priority) preset.priority.forEach(function(v) { window.filterManager.filters.priority.add(v); });
  if (preset.status) preset.status.forEach(function(v) { window.filterManager.filters.status.add(v); });
  if (preset.prefix) preset.prefix.forEach(function(v) { window.filterManager.filters.prefix.add(v); });
  if (preset.search) {
    window.filterManager.filters.search = preset.search;
    var searchBox = document.getElementById('searchBox');
    if (searchBox) searchBox.value = preset.search;
  }

  // Update button active states
  document.querySelectorAll('.filter-btn').forEach(function(btn) {
    var type = btn.dataset.filterType;
    var value = btn.dataset.filterValue;
    if (type && value) {
      var filterSet = window.filterManager.filters[type];
      if (filterSet && filterSet.has && filterSet.has(value)) {
        btn.classList.add('active');
      } else {
        btn.classList.remove('active');
      }
    }
  });

  // Show delete button
  document.getElementById('deletePresetBtn').style.display = '';

  // Re-apply filters
  window.filterManager.applyFilters();

  if (typeof showNotification === 'function') {
    showNotification('Preset "' + name + '" loaded', 'info');
  }
}

function deleteFilterPreset() {
  var select = document.getElementById('filterPresetSelect');
  var name = select ? select.value : '';
  if (!name) return;

  if (!confirm('Delete preset "' + name + '"?')) return;

  var presets = getFilterPresets();
  delete presets[name];
  localStorage.setItem(PRESET_STORAGE_KEY, JSON.stringify(presets));
  populatePresetSelect();
  document.getElementById('deletePresetBtn').style.display = 'none';

  if (typeof showNotification === 'function') {
    showNotification('Preset "' + name + '" deleted', 'info');
  }
}

// Initialize presets on load
document.addEventListener('DOMContentLoaded', function() {
  populatePresetSelect();
});

function dismissAlerts() {
  var bar = document.getElementById('smartAlertsBar');
  if (bar) {
    bar.style.transition = 'all 0.3s ease';
    bar.style.opacity = '0';
    bar.style.maxHeight = '0';
    bar.style.marginBottom = '0';
    bar.style.overflow = 'hidden';
    setTimeout(function() { bar.style.display = 'none'; }, 300);
  }
}

function toggleFiltersPanel() {
    const filtersContainer = document.getElementById('filtersContainer');
    const toggleIcon = document.getElementById('filtersToggleIcon');
    const toggleBtn = document.getElementById('filtersToggleBtn');
    
    if (!filtersContainer) {
        console.error('Filters container not found');
        return;
    }
    
    const isCollapsed = filtersContainer.classList.contains('collapsed');
    
    if (isCollapsed) {
        filtersContainer.classList.remove('collapsed');
        if (toggleIcon) toggleIcon.innerHTML = '\u2191';
        if (toggleBtn) toggleBtn.classList.add('active');
    } else {
        filtersContainer.classList.add('collapsed');
        if (toggleIcon) toggleIcon.innerHTML = '\u21BA';
        if (toggleBtn) toggleBtn.classList.remove('active');
    }
}

// Analytics toggle functionality
function toggleAnalytics() {
    const container = document.getElementById('analyticsContainer');
    const icon = document.getElementById('analyticsToggleIcon');
    const btn = document.getElementById('analyticsToggleBtn');
    
    if (container.classList.contains('collapsed')) {
        container.classList.remove('collapsed');
        icon.innerHTML = '\u2191';
        btn.classList.remove('collapsed');
    } else {
        container.classList.add('collapsed');
        icon.innerHTML = '\u21BA';
        btn.classList.add('collapsed');
    }
}

// ============================================
// Feature #4: Auto-Refresh + New Order Indicator
// With Sound & Browser Notifications
// ============================================

// Configuration
const AUTO_REFRESH_CONFIG = {
    pollInterval: 30000,      // Check every 30 seconds
    enabled: true,            // Auto-refresh enabled by default
    knownOrderRefs: new Set(), // Track known order reference codes
    knownStatusHash: '',      // Track status changes from vendor/webhook
    soundEnabled: true,       // Sound notification enabled
    browserNotifyEnabled: true // Browser notification enabled
};

// Initialize auto-refresh on page load
document.addEventListener('DOMContentLoaded', function() {
    initAutoRefresh();
    initNotificationPermission();
});

function initAutoRefresh() {
    // Get initial order references from current page data
    if (window.dashboardData && window.dashboardData.orders) {
        window.dashboardData.orders.forEach(order => {
            if (order.referenceCode) {
                AUTO_REFRESH_CONFIG.knownOrderRefs.add(order.referenceCode);
            }
        });
    }
    
    console.log('[Auto-Refresh] Initialized with', AUTO_REFRESH_CONFIG.knownOrderRefs.size, 'known orders');
    
    // Start polling
    if (AUTO_REFRESH_CONFIG.enabled) {
        setInterval(checkForNewOrders, AUTO_REFRESH_CONFIG.pollInterval);
        console.log('[Auto-Refresh] Polling every', AUTO_REFRESH_CONFIG.pollInterval / 1000, 'seconds');
    }
}

// ============================================
// Browser Notification Permission
// ============================================

function initNotificationPermission() {
    if (!('Notification' in window)) {
        console.log('[Notifications] Browser does not support notifications');
        AUTO_REFRESH_CONFIG.browserNotifyEnabled = false;
        return;
    }
    
    console.log('[Notifications] Current permission:', Notification.permission);
    
    // If permission is default, we'll ask when new orders arrive
    if (Notification.permission === 'denied') {
        AUTO_REFRESH_CONFIG.browserNotifyEnabled = false;
    }
}

async function requestNotificationPermission() {
    if (!('Notification' in window)) return false;
    if (Notification.permission === 'granted') return true;
    if (Notification.permission === 'denied') return false;
    
    try {
        const permission = await Notification.requestPermission();
        console.log('[Notifications] Permission result:', permission);
        return permission === 'granted';
    } catch (error) {
        console.error('[Notifications] Permission request error:', error);
        return false;
    }
}

// ============================================
// Sound Notification
// ============================================

function playNotificationSound() {
    if (!AUTO_REFRESH_CONFIG.soundEnabled) return;
    
    try {
        // Create audio context for notification sound
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        
        // Create a pleasant notification chime (two-tone)
        const playTone = (frequency, startTime, duration) => {
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.frequency.value = frequency;
            oscillator.type = 'sine';
            
            // Fade in and out for a pleasant sound
            gainNode.gain.setValueAtTime(0, startTime);
            gainNode.gain.linearRampToValueAtTime(0.3, startTime + 0.05);
            gainNode.gain.linearRampToValueAtTime(0, startTime + duration);
            
            oscillator.start(startTime);
            oscillator.stop(startTime + duration);
        };
        
        const now = audioContext.currentTime;
        playTone(523.25, now, 0.15);       // C5
        playTone(659.25, now + 0.15, 0.2); // E5
        
        console.log('[Sound] Notification chime played');
    } catch (error) {
        console.error('[Sound] Error playing notification:', error);
    }
}

// ============================================
// Browser Notification
// ============================================

async function showBrowserNotification(count) {
    if (!AUTO_REFRESH_CONFIG.browserNotifyEnabled) {
        console.log('[Notifications] Browser notifications disabled in config');
        return;
    }
    
    if (!('Notification' in window)) {
        console.log('[Notifications] Notification API not supported');
        return;
    }
    
    console.log('[Notifications] Current permission state:', Notification.permission);
    
    // Request permission if needed
    if (Notification.permission === 'default') {
        console.log('[Notifications] Requesting permission...');
        const granted = await requestNotificationPermission();
        if (!granted) {
            console.log('[Notifications] Permission not granted');
            return;
        }
    }
    
    if (Notification.permission !== 'granted') {
        console.log('[Notifications] Permission is:', Notification.permission);
        return;
    }
    
    try {
        // Create notification with minimal options for maximum compatibility
        const options = {
            body: `${count} new order${count > 1 ? 's' : ''} received! Click to view.`,
            icon: 'logo.png',
            tag: 'printstuff-new-orders-' + Date.now(), // Unique tag to ensure it shows
            requireInteraction: true // Keep visible until user interacts
        };
        
        console.log('[Notifications] Creating notification with options:', options);
        
        const notification = new Notification('<?= ICON_PRINTER ?> New Order Alert', options);
        
        console.log('[Notifications] Notification object created:', notification);
        console.log('[Notifications] Notification permission:', notification.permission);
        
        notification.onshow = function() {
            console.log('[Notifications] <?= ICON_CHECK_GREEN ?> Notification SHOWN successfully');
        };
        
        notification.onclick = function(event) {
            console.log('[Notifications] Notification clicked');
            event.preventDefault();
            window.focus();
            window.location.reload();
            notification.close();
        };
        
        notification.onerror = function(error) {
            console.error('[Notifications] <?= ICON_CROSS ?> Notification ERROR:', error);
        };
        
        notification.onclose = function() {
            console.log('[Notifications] Notification closed');
        };
        
        // Auto-close after 15 seconds
        setTimeout(() => {
            notification.close();
        }, 15000);
        
        console.log('[Notifications] Browser notification created - check system tray or notification center');
        
    } catch (error) {
        console.error('[Notifications] <?= ICON_CROSS ?> Error creating notification:', error);
    }
}

// ============================================
// New Orders Detection
// ============================================

async function checkForNewOrders() {
    try {
        const response = await fetch('admin-orders.php?check_new_orders=1');
        const data = await response.json();

        if (data.success && data.orderRefs) {
            // Find new orders by comparing reference codes
            const newRefs = data.orderRefs.filter(ref => !AUTO_REFRESH_CONFIG.knownOrderRefs.has(ref));

            if (newRefs.length > 0) {
                console.log('[Auto-Refresh] New orders detected:', newRefs);

                // Add new refs to known set so we don't notify again
                newRefs.forEach(ref => AUTO_REFRESH_CONFIG.knownOrderRefs.add(ref));

                // Show notifications
                showNewOrdersBadge(newRefs.length);
                playNotificationSound();
                showBrowserNotification(newRefs.length);
            }

            // Check for status changes (from vendor portal, webhooks, other admins)
            if (data.statusHash && AUTO_REFRESH_CONFIG.knownStatusHash) {
                if (data.statusHash !== AUTO_REFRESH_CONFIG.knownStatusHash) {
                    console.log('[Auto-Refresh] Status changes detected, refreshing...');
                    AUTO_REFRESH_CONFIG.knownStatusHash = data.statusHash;
                    showStatusUpdateBanner();
                }
            }
            // Store initial hash
            if (data.statusHash && !AUTO_REFRESH_CONFIG.knownStatusHash) {
                AUTO_REFRESH_CONFIG.knownStatusHash = data.statusHash;
            }
        }
    } catch (error) {
        console.error('[Auto-Refresh] Error checking for new orders:', error);
    }
}

function showStatusUpdateBanner() {
    // Don't show duplicate banners
    if (document.getElementById('statusUpdateBanner')) return;

    var banner = document.createElement('div');
    banner.id = 'statusUpdateBanner';
    banner.className = 'status-update-banner';
    banner.innerHTML = '<span>&#128260; Order statuses have been updated by another user or system.</span>' +
        '<button onclick="window.location.reload()" class="status-update-refresh-btn">Refresh Now</button>' +
        '<button onclick="this.parentElement.remove()" class="status-update-dismiss-btn">&#10005;</button>';

    // Insert after page header
    var header = document.querySelector('.page-header');
    if (header && header.parentElement) {
        header.parentElement.insertBefore(banner, header.nextSibling);
    }
}

function showNewOrdersBadge(count) {
    const badge = document.getElementById('newOrdersBadge');
    const countSpan = document.getElementById('newOrdersCount');
    
    if (badge && countSpan) {
        countSpan.textContent = count;
        badge.style.display = 'inline-flex';
        
        // Click badge to reload the page (ensures fresh data)
        badge.onclick = function() {
            window.location.reload();
        };
    }
}

function hideNewOrdersBadge() {
    const badge = document.getElementById('newOrdersBadge');
    if (badge) {
        badge.style.display = 'none';
    }
}

// ============================================
// Debug: Test notification manually
// Run testNotification() in browser console
// ============================================
function testNotification() {
    console.log('=== NOTIFICATION TEST ===');
    console.log('Notification API exists:', 'Notification' in window);
    console.log('Current permission:', Notification.permission);
    
    if (Notification.permission === 'granted') {
        try {
            const n = new Notification('Test Notification', {
                body: 'If you see this, notifications are working!',
                requireInteraction: true
            });
            n.onshow = () => console.log('<?= ICON_CHECK_GREEN ?> Test notification SHOWN');
            n.onerror = (e) => console.error('<?= ICON_CROSS ?> Test notification ERROR:', e);
            console.log('Notification object:', n);
        } catch (e) {
            console.error('Failed to create notification:', e);
        }
    } else {
        console.log('Permission not granted. Requesting...');
        Notification.requestPermission().then(p => {
            console.log('Permission result:', p);
            if (p === 'granted') {
                testNotification(); // Try again
            }
        });
    }
}

// Make it globally accessible for console testing
window.testNotification = testNotification;

// Update table title and analytics when events toggle changes
document.addEventListener('DOMContentLoaded', function() {
    const eventsToggle = document.getElementById('eventsToggle');
    const tableTitleMode = document.getElementById('tableTitleMode');

    if (eventsToggle && tableTitleMode) {
        eventsToggle.addEventListener('click', function(e) {
            if (e.target.classList.contains('segment-btn')) {
                const mode = e.target.dataset.mode;

                // Update active button state
                eventsToggle.querySelectorAll('.segment-btn').forEach(function(btn) {
                    btn.classList.remove('active');
                });
                e.target.classList.add('active');

                // Update table title
                tableTitleMode.textContent = mode === 'active' ? 'Active Events' : 'All Events';

                // Update analytics charts and metrics
                if (window.analyticsManager && typeof window.analyticsManager.recalculateForEventsMode === 'function') {
                    window.analyticsManager.recalculateForEventsMode(mode);
                }

                // Update table filtering
                if (window.simpleFilters && typeof window.simpleFilters.setEventsMode === 'function') {
                    window.simpleFilters.setEventsMode(mode);
                }
            }
        });
    }
});
</script>

<!-- Payment Link Functions -->
<script src="js/admin-payment-link.js"></script>

</body>
</html>
