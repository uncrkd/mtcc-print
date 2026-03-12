<?php
/**
 * Dispatch Earnings Management
 * MTCC Print Services
 *
 * Courier earnings tracking, payment recording (cash/e-transfer/cheque).
 * Server path: /dispatch/earnings.php
 */

require_once __DIR__ . '/../admin-auth.php';
requirePermission('dispatch');

require_once __DIR__ . '/dispatch-functions.php';

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'mark_paid') {
        $pin = $_POST['pin'] ?? '';
        $method = $_POST['method'] ?? 'cash';
        $indices = !empty($_POST['indices']) ? json_decode($_POST['indices'], true) : [];
        if (empty($pin)) { echo json_encode(['success' => false, 'error' => 'Missing courier']); exit; }
        $result = dispatch_markPaid($pin, $indices, $method);
        echo json_encode($result);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

$period = $_GET['period'] ?? 'all';
$earningsSummary = dispatch_getEarningsSummary($period);

$periodLabels = [
    'today' => 'Today',
    '7days' => 'This Week',
    '30days' => 'This Month',
    'all' => 'All Time',
];

$totalPending = 0;
$totalEarned = 0;
$totalPaid = 0;
$totalDeliveries = 0;
foreach ($earningsSummary as $c) {
    $totalPending += $c['pending'];
    $totalEarned += $c['earned'];
    $totalPaid += $c['paid'];
    $totalDeliveries += $c['deliveries'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Courier Earnings - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin-base.css">
    <link rel="stylesheet" href="../css/admin-components.css">
    <link rel="stylesheet" href="../css/admin-layout.css">
    <link rel="stylesheet" href="../css/admin-tables.css">
    <link rel="stylesheet" href="../css/admin-sidebar.css">
    <style>
    .earnings-container { max-width: 1200px; margin: 0 auto; padding: 0 20px 40px; }

    .earnings-header {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 20px; flex-wrap: wrap; gap: 12px;
    }
    .earnings-header h1 { font-size: 1.5rem; font-weight: 800; color: #1f2937; margin: 0; }
    .earnings-header p { color: #6b7280; font-size: 0.85rem; margin: 4px 0 0; }

    .period-pills {
        display: flex; gap: 6px; background: #f3f4f6; padding: 4px; border-radius: 10px;
    }
    .period-pill {
        padding: 6px 14px; border-radius: 8px; font-size: 0.82rem; font-weight: 600;
        text-decoration: none; color: #6b7280; transition: all 0.15s;
    }
    .period-pill:hover { color: #374151; background: #e5e7eb; }
    .period-pill.active { color: #fff; background: #7c3aed; }

    /* Summary Cards */
    .earnings-summary {
        display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 24px;
    }
    .earn-card {
        background: #fff; border-radius: 12px; padding: 18px; border: 1px solid #e5e7eb;
    }
    .earn-card-label { font-size: 0.78rem; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; }
    .earn-card-value { font-size: 1.6rem; font-weight: 800; color: #1f2937; margin-top: 4px; }
    .earn-card-sub { font-size: 0.78rem; color: #9ca3af; margin-top: 2px; }
    .earn-card.pending { border-left: 4px solid #f59e0b; }
    .earn-card.paid { border-left: 4px solid #10b981; }
    .earn-card.total { border-left: 4px solid #7c3aed; }

    /* Courier Table */
    .earnings-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; border: 1px solid #e5e7eb; }
    .earnings-table thead th {
        padding: 12px 14px; font-size: 0.78rem; font-weight: 700; color: #6b7280;
        text-transform: uppercase; letter-spacing: 0.03em; background: #f9fafb;
        border-bottom: 2px solid #e5e7eb; text-align: left;
    }
    .earnings-table thead th.text-right { text-align: right; }
    .earnings-table tbody td { padding: 12px 14px; font-size: 0.88rem; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
    .earnings-table tbody tr:hover { background: #f9fafb; }
    .earnings-table .text-right { text-align: right; }

    .courier-name { font-weight: 700; color: #1f2937; }
    .pending-amount { color: #d97706; font-weight: 700; }
    .paid-amount { color: #10b981; }

    .pay-btn {
        padding: 5px 12px; border-radius: 7px; font-size: 0.78rem; font-weight: 700;
        border: none; cursor: pointer; transition: all 0.15s;
    }
    .pay-btn-primary {
        background: #7c3aed; color: #fff;
    }
    .pay-btn-primary:hover { background: #6d28d9; }
    .pay-btn-secondary {
        background: #f3f4f6; color: #374151; border: 1px solid #d1d5db;
    }
    .pay-btn-secondary:hover { background: #e5e7eb; }
    .pay-btn:disabled { opacity: 0.4; cursor: not-allowed; }

    /* Expandable Detail */
    .detail-row { display: none; }
    .detail-row.open { display: table-row; }
    .detail-row td { padding: 0; background: #f9fafb; }
    .detail-content { padding: 14px 20px; }
    .detail-deliveries { width: 100%; border-collapse: collapse; }
    .detail-deliveries th { font-size: 0.72rem; font-weight: 700; color: #6b7280; padding: 6px 8px; text-align: left; border-bottom: 1px solid #e5e7eb; }
    .detail-deliveries td { font-size: 0.82rem; padding: 6px 8px; border-bottom: 1px solid #f3f4f6; }
    .detail-deliveries .text-right { text-align: right; }
    .badge-paid { background: #dcfce7; color: #16a34a; padding: 2px 6px; border-radius: 4px; font-size: 0.72rem; font-weight: 700; }
    .badge-pending { background: #fef3c7; color: #d97706; padding: 2px 6px; border-radius: 4px; font-size: 0.72rem; font-weight: 700; }
    .badge-method { background: #e5e7eb; color: #374151; padding: 2px 6px; border-radius: 4px; font-size: 0.72rem; }
    .toggle-btn { cursor: pointer; color: #7c3aed; font-weight: 600; }
    .toggle-btn:hover { text-decoration: underline; }

    /* Payment Modal */
    .pay-modal-overlay {
        position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9000;
        display: flex; align-items: center; justify-content: center; backdrop-filter: blur(4px);
    }
    .pay-modal {
        background: #fff; border-radius: 14px; width: 95%; max-width: 400px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.2); overflow: hidden;
    }
    .pay-modal-header { padding: 16px 18px; border-bottom: 1px solid #e5e7eb; background: #f9fafb; }
    .pay-modal-header h3 { margin: 0; font-size: 1rem; font-weight: 700; }
    .pay-modal-body { padding: 18px; }
    .pay-modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 18px; border-top: 1px solid #e5e7eb; }

    .method-options { display: flex; gap: 8px; margin-top: 8px; }
    .method-option {
        flex: 1; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px;
        text-align: center; cursor: pointer; font-size: 0.85rem; font-weight: 600; transition: all 0.15s;
    }
    .method-option:hover { border-color: #7c3aed; }
    .method-option.selected { border-color: #7c3aed; background: #f5f3ff; color: #7c3aed; }
    .method-icon { font-size: 1.3rem; display: block; margin-bottom: 4px; }

    .empty-state { text-align: center; padding: 60px 20px; color: #9ca3af; }
    .empty-state .icon { font-size: 3rem; margin-bottom: 12px; }
    .empty-state .title { font-size: 1.1rem; font-weight: 700; color: #6b7280; }

    @media (max-width: 768px) {
        .earnings-summary { grid-template-columns: repeat(2, 1fr); }
    }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('earnings'); ?>
<script src="../js/admin-sidebar.js"></script>

<div class="earnings-container">

<div class="earnings-header">
    <div>
        <h1>&#128176; Courier Earnings</h1>
        <p>Track deliveries, record payments &mdash; <?= htmlspecialchars($periodLabels[$period] ?? 'All Time') ?></p>
    </div>
    <div class="period-pills">
        <?php foreach ($periodLabels as $key => $label): ?>
        <a href="?period=<?= $key ?>" class="period-pill <?= $period === $key ? 'active' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Summary Cards -->
<div class="earnings-summary">
    <div class="earn-card">
        <div class="earn-card-label">Deliveries</div>
        <div class="earn-card-value"><?= $totalDeliveries ?></div>
    </div>
    <div class="earn-card total">
        <div class="earn-card-label">Total Earned</div>
        <div class="earn-card-value">$<?= number_format($totalEarned, 2) ?></div>
    </div>
    <div class="earn-card pending">
        <div class="earn-card-label">Pending Payment</div>
        <div class="earn-card-value pending-amount">$<?= number_format($totalPending, 2) ?></div>
    </div>
    <div class="earn-card paid">
        <div class="earn-card-label">Paid Out</div>
        <div class="earn-card-value" style="color: #10b981;">$<?= number_format($totalPaid, 2) ?></div>
    </div>
</div>

<?php if (empty($earningsSummary)): ?>
<div class="empty-state">
    <div class="icon">&#128176;</div>
    <div class="title">No Earnings Recorded</div>
    <p>Earnings will appear here as couriers complete deliveries. They are auto-recorded when a delivery is marked as delivered or picked up.</p>
</div>
<?php else: ?>

<!-- Courier Earnings Table -->
<table class="earnings-table">
    <thead>
        <tr>
            <th>Courier</th>
            <th class="text-right">Deliveries</th>
            <th class="text-right">Earned</th>
            <th class="text-right">Bonuses</th>
            <th class="text-right">Pending</th>
            <th class="text-right">Paid</th>
            <th class="text-right">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($earningsSummary as $pin => $c): ?>
        <tr>
            <td>
                <span class="courier-name"><?= htmlspecialchars($c['name']) ?></span>
                <span class="toggle-btn" onclick="toggleDetail('<?= htmlspecialchars($pin) ?>')" title="View delivery details"> &#9660;</span>
            </td>
            <td class="text-right"><?= $c['deliveries'] ?></td>
            <td class="text-right">$<?= number_format($c['earned'], 2) ?></td>
            <td class="text-right"><?= $c['bonuses'] > 0 ? '$' . number_format($c['bonuses'], 2) : '&mdash;' ?></td>
            <td class="text-right"><span class="pending-amount"><?= $c['pending'] > 0 ? '$' . number_format($c['pending'], 2) : '$0.00' ?></span></td>
            <td class="text-right"><span class="paid-amount">$<?= number_format($c['paid'], 2) ?></span></td>
            <td class="text-right">
                <?php if ($c['pending'] > 0): ?>
                <button class="pay-btn pay-btn-primary" onclick="showPayModal('<?= htmlspecialchars($pin) ?>', '<?= htmlspecialchars($c['name']) ?>', <?= $c['pending'] ?>)">
                    &#128181; Mark Paid
                </button>
                <?php else: ?>
                <span style="color: #10b981; font-size: 0.82rem; font-weight: 600;">&#10003; All Paid</span>
                <?php endif; ?>
            </td>
        </tr>
        <!-- Expandable Detail Row -->
        <tr class="detail-row" id="detail-<?= htmlspecialchars($pin) ?>">
            <td colspan="7">
                <div class="detail-content">
                    <?php if (!empty($c['recent_deliveries'])): ?>
                    <table class="detail-deliveries">
                        <thead>
                            <tr>
                                <th>Ref</th>
                                <th>Date</th>
                                <th class="text-right">Amount</th>
                                <th class="text-right">Bonus</th>
                                <th class="text-right">Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($c['recent_deliveries'] as $d): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($d['ref'] ?? '') ?></strong></td>
                                <td><?= !empty($d['earned_at']) ? date('M j, g:ia', strtotime($d['earned_at'])) : '&mdash;' ?></td>
                                <td class="text-right">$<?= number_format($d['amount'] ?? 0, 2) ?></td>
                                <td class="text-right"><?= ($d['bonus'] ?? 0) > 0 ? '$' . number_format($d['bonus'], 2) : '&mdash;' ?></td>
                                <td class="text-right"><strong>$<?= number_format($d['total'] ?? 0, 2) ?></strong></td>
                                <td>
                                    <?php if ($d['paid']): ?>
                                    <span class="badge-paid">Paid</span>
                                    <?php if (!empty($d['payment_method'])): ?>
                                    <span class="badge-method"><?= ucfirst($d['payment_method']) ?></span>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="badge-pending">Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p style="color: #9ca3af; text-align: center; padding: 20px;">No delivery records for this period.</p>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php endif; ?>

</div>

<!-- Payment Modal -->
<div class="pay-modal-overlay" id="payModalOverlay" style="display:none" onclick="closePayModal()">
    <div class="pay-modal" onclick="event.stopPropagation()">
        <div class="pay-modal-header">
            <h3>&#128181; Record Payment</h3>
        </div>
        <div class="pay-modal-body">
            <p style="margin:0 0 6px; font-size:0.85rem; color:#6b7280;">Courier: <strong id="payModalName"></strong></p>
            <p style="margin:0 0 16px; font-size:1.2rem; font-weight:800; color:#1f2937;">Amount: <span id="payModalAmount"></span></p>
            <label style="font-size:0.82rem; font-weight:700; color:#374151; display:block; margin-bottom:6px;">Payment Method</label>
            <div class="method-options">
                <div class="method-option selected" data-method="cash" onclick="selectMethod(this)">
                    <span class="method-icon">&#128181;</span> Cash
                </div>
                <div class="method-option" data-method="etransfer" onclick="selectMethod(this)">
                    <span class="method-icon">&#128177;</span> E-Transfer
                </div>
                <div class="method-option" data-method="cheque" onclick="selectMethod(this)">
                    <span class="method-icon">&#128221;</span> Cheque
                </div>
            </div>
        </div>
        <div class="pay-modal-footer">
            <button class="pay-btn pay-btn-secondary" onclick="closePayModal()">Cancel</button>
            <button class="pay-btn pay-btn-primary" id="payModalSubmit" onclick="submitPayment()">&#10003; Confirm Payment</button>
        </div>
    </div>
</div>

<script>
var currentPayPin = '';
var currentPayMethod = 'cash';

function toggleDetail(pin) {
    var row = document.getElementById('detail-' + pin);
    if (row) row.classList.toggle('open');
}

function showPayModal(pin, name, amount) {
    currentPayPin = pin;
    currentPayMethod = 'cash';
    document.getElementById('payModalName').textContent = name;
    document.getElementById('payModalAmount').textContent = '$' + amount.toFixed(2);
    document.querySelectorAll('.method-option').forEach(function(el) {
        el.classList.toggle('selected', el.dataset.method === 'cash');
    });
    document.getElementById('payModalOverlay').style.display = 'flex';
}

function closePayModal() {
    document.getElementById('payModalOverlay').style.display = 'none';
    currentPayPin = '';
}

function selectMethod(el) {
    document.querySelectorAll('.method-option').forEach(function(o) { o.classList.remove('selected'); });
    el.classList.add('selected');
    currentPayMethod = el.dataset.method;
}

function submitPayment() {
    if (!currentPayPin) return;
    var btn = document.getElementById('payModalSubmit');
    btn.disabled = true;
    btn.textContent = 'Processing...';

    var formData = new FormData();
    formData.append('action', 'mark_paid');
    formData.append('pin', currentPayPin);
    formData.append('method', currentPayMethod);

    fetch('earnings.php', { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        if (result.success) {
            closePayModal();
            location.reload();
        } else {
            alert(result.error || 'Payment failed');
            btn.disabled = false;
            btn.textContent = '✓ Confirm Payment';
        }
    })
    .catch(function() {
        alert('Network error');
        btn.disabled = false;
        btn.textContent = '✓ Confirm Payment';
    });
}
</script>
</body>
</html>
