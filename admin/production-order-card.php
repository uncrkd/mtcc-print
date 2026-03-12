<?php
/**
 * Preflight/Vendor Information Card
 * Include in admin-orders.php order detail view
 * 
 * Location: /includes/production-order-card.php
 * 
 * Required variables (set before including):
 *   $order - Order data array
 *   $currentStatus - Current order status string
 *   $canEditOrders - Boolean permission check
 * 
 * Reads from:
 *   preflight-log.json - Vendor push tracking
 *   vendors.json - Vendor details
 *   vendor-tokens.json - Portal token data
 *   reminder-log.json - Reminder history
 */

// ============================================
// LOAD PREFLIGHT DATA
// ============================================
$refCode = $order['referenceCode'];
$basePath = __DIR__ . '/../';

// Load vendor deadline utility
$vendorDeadlinePath = __DIR__ . '/vendor-deadline.php';
if (file_exists($vendorDeadlinePath)) {
    require_once $vendorDeadlinePath;
}

// Load preflight log
$preflightLogFile = $basePath . 'preflight-log.json';
$preflightLog = [];
if (file_exists($preflightLogFile)) {
    $preflightLog = json_decode(file_get_contents($preflightLogFile), true) ?: [];
}
$pfEntry = $preflightLog['entries'][$refCode] ?? null;

// Load vendor data if order was sent
$vendor = null;
if ($pfEntry && !empty($pfEntry['vendor_id'])) {
    $vendorsFile = $basePath . 'vendors.json';
    if (file_exists($vendorsFile)) {
        $vendorData = json_decode(file_get_contents($vendorsFile), true) ?: [];
        foreach ($vendorData['vendors'] ?? [] as $v) {
            if ($v['id'] === $pfEntry['vendor_id']) {
                $vendor = $v;
                break;
            }
        }
    }
}

// Load token data
$tokenData = null;
$activeToken = null;
$tokensFile = $basePath . 'vendor-tokens.json';
if (file_exists($tokensFile)) {
    $tokens = json_decode(file_get_contents($tokensFile), true) ?: [];
    foreach ($tokens['tokens'] ?? [] as $t => $data) {
        if (($data['reference_code'] ?? '') === $refCode && empty($data['revoked'])) {
            $createdAt = strtotime($data['created_at']);
            $expiresAt = $createdAt + (7 * 24 * 60 * 60);
            if (time() < $expiresAt) {
                $tokenData = $data;
                $activeToken = $t;
                break;
            }
        }
    }
}

// Load reminder history
$reminderCount = 0;
$lastReminder = null;
$reminderLogFile = $basePath . 'reminder-log.json';
if (file_exists($reminderLogFile)) {
    $reminderLog = json_decode(file_get_contents($reminderLogFile), true) ?: [];
    foreach ($reminderLog['reminders'] ?? [] as $reminder) {
        if (($reminder['reference_code'] ?? '') === $refCode) {
            $reminderCount++;
            if (!$lastReminder || strtotime($reminder['sent_at']) > strtotime($lastReminder['sent_at'])) {
                $lastReminder = $reminder;
            }
        }
    }
}

// Determine preflight state
$hasPreflight = !empty($pfEntry);
$isSentToVendor = $hasPreflight && !empty($pfEntry['sent_at']);
$isConfirmed = $hasPreflight && !empty($pfEntry['confirmed_at']);
$hasFileIssue = $hasPreflight && !empty($pfEntry['file_issue']);
$preflightStatuses = ['preflight', 'printing', 'ready_to_ship', 'file_issue'];
$showPreflightCard = $hasPreflight || in_array($currentStatus, $preflightStatuses);

// Only render if relevant
if (!$showPreflightCard && !in_array($currentStatus, ['paid'])) {
    return;
}

// Calculate vendor deadline if we have the data
$vendorDeadlineInfo = null;
if (function_exists('getVendorDeadlineForOrder') && $hasPreflight) {
    $vendorDeadlineInfo = getVendorDeadlineForOrder($order);
}
?>

<!-- ==========================================
     PREFLIGHT / VENDOR INFORMATION CARD 
     ========================================== -->
<div class="card card-compact" id="preflightInfoCard" style="border: 2px solid <?= $hasFileIssue ? '#ea580c' : ($isConfirmed ? '#10b981' : ($isSentToVendor ? '#7c3aed' : '#94a3b8')) ?>; background: <?= $hasFileIssue ? 'linear-gradient(135deg, #fff7ed 0%, #fef3c7 100%)' : ($isConfirmed ? 'linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%)' : ($isSentToVendor ? 'linear-gradient(135deg, #faf5ff 0%, #f5f3ff 100%)' : '#f8fafc')) ?>;">
  
  <div class="section-header" style="color: <?= $hasFileIssue ? '#ea580c' : ($isConfirmed ? '#059669' : '#7c3aed') ?>;">
    <span class="card-icon"><?= $hasFileIssue ? ICON_WARNING : ($isConfirmed ? ICON_CHECK_GREEN : ICON_PRINTER) ?></span> 
    Preflight & Vendor Information
  </div>
  
  <div style="padding: 0 var(--space-md) var(--space-md);">
    
    <?php if (!$hasPreflight): ?>
    <!-- NOT YET SENT TO VENDOR -->
    <div style="text-align: center; padding: 20px 0;">
      <div style="font-size: 2rem; margin-bottom: 10px;">&#128230;</div>
      <div style="color: #6b7280; font-size: 0.9rem; margin-bottom: 15px;">
        This order has not been sent to a vendor yet.
      </div>
      <?php if ($canEditOrders): ?>
      <a href="preflight.php" class="btn" style="background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); color: white; border: none; text-decoration: none; display: inline-block; padding: 10px 24px; border-radius: 8px; font-weight: 600;">
        <?= ICON_PRINTER ?> Go to Preflight
      </a>
      <?php endif; ?>
    </div>
    
    <?php else: ?>
    <!-- VENDOR ASSIGNMENT INFO -->
    
    <!-- Status Banner -->
    <div style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: white; border-radius: 8px; margin-bottom: 16px; border: 1px solid <?= $hasFileIssue ? '#fed7aa' : ($isConfirmed ? '#a7f3d0' : '#ddd6fe') ?>;">
      <div style="font-size: 1.5rem;">
        <?php if ($hasFileIssue): ?>
          <?= ICON_WARNING ?>
        <?php elseif ($isConfirmed): ?>
          <?= ICON_CHECK_GREEN ?>
        <?php else: ?>
          <?= ICON_HOURGLASS ?>
        <?php endif; ?>
      </div>
      <div>
        <div style="font-weight: 600; color: <?= $hasFileIssue ? '#ea580c' : ($isConfirmed ? '#059669' : '#7c3aed') ?>; font-size: 0.95rem;">
          <?php if ($hasFileIssue): ?>
            File Issue Reported
          <?php elseif ($isConfirmed): ?>
            Vendor Confirmed
          <?php else: ?>
            Awaiting Vendor Confirmation
          <?php endif; ?>
        </div>
        <div style="font-size: 0.8rem; color: #6b7280;">
          <?php if ($hasFileIssue): ?>
            <?= ucfirst(str_replace('_', ' ', $pfEntry['file_issue']['type'] ?? 'Unknown')) ?> 
            — reported <?= date('l, F j, Y \a\t g:i A', strtotime($pfEntry['file_issue']['reported_at'])) ?>
          <?php elseif ($isConfirmed): ?>
            Confirmed <?= date('l, F j, Y \a\t g:i A', strtotime($pfEntry['confirmed_at'])) ?>
          <?php elseif ($isSentToVendor): ?>
            Sent <?= date('l, F j, Y \a\t g:i A', strtotime($pfEntry['sent_at'])) ?>
            <?php
            // Show elapsed time
            $elapsed = time() - strtotime($pfEntry['sent_at']);
            $hours = floor($elapsed / 3600);
            $mins = floor(($elapsed % 3600) / 60);
            if ($hours > 0): ?>
              — <strong style="color: <?= $hours >= 8 ? '#dc2626' : ($hours >= 4 ? '#f59e0b' : '#6b7280') ?>;"><?= $hours ?>h <?= $mins ?>m ago</strong>
            <?php else: ?>
              — <?= $mins ?>m ago
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    
    <!-- Vendor Details -->
    <?php if ($vendor): ?>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px;">
      <div style="padding: 10px 14px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
        <div style="font-size: 0.7rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 3px;">Vendor</div>
        <div style="font-weight: 600; color: #1e293b; font-size: 0.9rem;"><?= htmlspecialchars($vendor['business_name'] ?? 'Unknown') ?></div>
      </div>
      <div style="padding: 10px 14px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
        <div style="font-size: 0.7rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 3px;">Contact</div>
        <div style="font-weight: 600; color: #1e293b; font-size: 0.9rem;"><?= htmlspecialchars($vendor['contact_name'] ?? $vendor['email'] ?? '-') ?></div>
      </div>
    </div>
    <?php endif; ?>
    
    <?php if ($vendorDeadlineInfo && $isSentToVendor): ?>
    <!-- Vendor Production Deadline -->
    <div style="padding: 12px 16px; background: white; border-radius: 8px; margin-bottom: 16px; border: 1px solid <?= $vendorDeadlineInfo['urgency']['color'] === 'red' ? '#FECACA' : ($vendorDeadlineInfo['urgency']['color'] === 'amber' ? '#FDE68A' : '#e5e7eb') ?>;">
      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
        <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;">Production Deadline</div>
        <?= getUrgencyBadgeHTML($vendorDeadlineInfo) ?>
      </div>
      <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
        <div>
          <div style="font-size: 0.7rem; color: #9ca3af; margin-bottom: 2px;">Vendor Must Complete By</div>
          <div style="font-weight: 600; color: #1e293b; font-size: 0.88rem;"><?= htmlspecialchars($vendorDeadlineInfo['deadline_formatted']) ?></div>
        </div>
        <div>
          <div style="font-size: 0.7rem; color: #9ca3af; margin-bottom: 2px;">Courier Pickup</div>
          <div style="font-weight: 600; color: #1e293b; font-size: 0.88rem;"><?= htmlspecialchars($vendorDeadlineInfo['pickup_formatted']) ?></div>
        </div>
        <div>
          <div style="font-size: 0.7rem; color: #9ca3af; margin-bottom: 2px;">Production Time</div>
          <div style="font-weight: 600; color: <?= $vendorDeadlineInfo['production_hours'] <= 3 ? '#DC2626' : ($vendorDeadlineInfo['production_hours'] <= 6 ? '#D97706' : '#1e293b') ?>; font-size: 0.88rem;"><?= $vendorDeadlineInfo['production_hours'] ?> hours</div>
        </div>
      </div>
      <?php if ($vendorDeadlineInfo['is_previous_day']): ?>
      <div style="margin-top: 8px; font-size: 0.78rem; color: #7c3aed; background: #F5F3FF; padding: 6px 10px; border-radius: 4px;">
        &#128337; Previous-day production &amp; pickup required for 9 AM delivery
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Timeline Details -->
    <div style="margin-bottom: 16px;">
      <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px;">Timeline</div>
      
      <div style="display: flex; flex-direction: column; gap: 8px;">
        <!-- Sent -->
        <?php if ($isSentToVendor): ?>
        <div style="display: flex; align-items: center; gap: 10px; font-size: 0.85rem;">
          <span style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; background: #ddd6fe; border-radius: 50%; font-size: 0.7rem;"><?= ICON_ENVELOPE_ARROW ?></span>
          <span style="color: #374151;">Sent to vendor</span>
          <span style="color: #9ca3af; margin-left: auto;"><?= date('l, F j, Y g:i A', strtotime($pfEntry['sent_at'])) ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Portal Viewed -->
        <?php if ($tokenData && !empty($tokenData['portal_views'])): ?>
        <div style="display: flex; align-items: center; gap: 10px; font-size: 0.85rem;">
          <span style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; background: #e0e7ff; border-radius: 50%; font-size: 0.7rem;"><?= ICON_EYE ?></span>
          <span style="color: #374151;">Portal viewed (<?= count($tokenData['portal_views']) ?>x)</span>
          <span style="color: #9ca3af; margin-left: auto;"><?= date('l, F j, Y g:i A', strtotime(end($tokenData['portal_views'])['timestamp'])) ?></span>
        </div>
        <?php endif; ?>
        
        <!-- File Downloaded -->
        <?php if ($tokenData && !empty($tokenData['downloads'])): ?>
        <div style="display: flex; align-items: center; gap: 10px; font-size: 0.85rem;">
          <span style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; background: #d1fae5; border-radius: 50%; font-size: 0.7rem;"><?= ICON_DOWNLOAD ?></span>
          <span style="color: #374151;">File downloaded (<?= count($tokenData['downloads']) ?>x)</span>
          <span style="color: #9ca3af; margin-left: auto;"><?= date('l, F j, Y g:i A', strtotime(end($tokenData['downloads'])['timestamp'])) ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Reminders -->
        <?php if ($reminderCount > 0): ?>
        <div style="display: flex; align-items: center; gap: 10px; font-size: 0.85rem;">
          <span style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; background: #fef3c7; border-radius: 50%; font-size: 0.7rem;"><?= ICON_BELL ?></span>
          <span style="color: #374151;">Reminders sent (<?= $reminderCount ?>x)</span>
          <span style="color: #9ca3af; margin-left: auto;"><?= date('l, F j, Y g:i A', strtotime($lastReminder['sent_at'])) ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Confirmed -->
        <?php if ($isConfirmed): ?>
        <div style="display: flex; align-items: center; gap: 10px; font-size: 0.85rem;">
          <span style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; background: #d1fae5; border-radius: 50%; font-size: 0.7rem;"><?= ICON_CHECK_GREEN ?></span>
          <span style="color: #059669; font-weight: 600;">Confirmed by vendor</span>
          <span style="color: #9ca3af; margin-left: auto;"><?= date('l, F j, Y g:i A', strtotime($pfEntry['confirmed_at'])) ?></span>
        </div>
        <?php endif; ?>
        
        <!-- File Issue -->
        <?php if ($hasFileIssue): ?>
        <div style="display: flex; align-items: center; gap: 10px; font-size: 0.85rem;">
          <span style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; background: #fee2e2; border-radius: 50%; font-size: 0.7rem;"><?= ICON_WARNING ?></span>
          <span style="color: #dc2626; font-weight: 600;">Issue: <?= ucfirst(str_replace('_', ' ', $pfEntry['file_issue']['type'] ?? 'Unknown')) ?></span>
          <span style="color: #9ca3af; margin-left: auto;"><?= date('l, F j, Y g:i A', strtotime($pfEntry['file_issue']['reported_at'])) ?></span>
        </div>
        <?php if (!empty($pfEntry['file_issue']['description'])): ?>
        <div style="margin-left: 34px; padding: 8px 12px; background: #fef2f2; border-radius: 6px; border-left: 3px solid #dc2626; font-size: 0.8rem; color: #991b1b;">
          <?= htmlspecialchars($pfEntry['file_issue']['description']) ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Preflight Notes -->
    <?php if (!empty($pfEntry['notes'])): ?>
    <div style="padding: 10px 14px; background: #fef3c7; border-left: 3px solid #f59e0b; border-radius: 0 6px 6px 0; margin-bottom: 16px; font-size: 0.85rem;">
      <strong style="color: #92400e; display: block; margin-bottom: 3px;"><?= ICON_MEMO ?> Preflight Notes</strong>
      <span style="color: #78350f;"><?= nl2br(htmlspecialchars($pfEntry['notes'])) ?></span>
    </div>
    <?php endif; ?>
    
    <!-- Quick Actions -->
    <?php if ($canEditOrders): ?>
    <div style="display: flex; gap: 10px; flex-wrap: wrap; padding-top: 12px; border-top: 1px solid <?= $hasFileIssue ? '#fed7aa' : ($isConfirmed ? '#a7f3d0' : '#e5e7eb') ?>;">
      
      <!-- View Vendor Portal -->
      <a href="../vendor-portal.php?ref=<?= urlencode($refCode) ?>" target="_blank" class="btn btn-light" style="border: 1px solid #7c3aed; color: #7c3aed; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; font-size: 0.8rem; font-weight: 600;">
        <?= ICON_EYE ?> Vendor Portal
      </a>
      
      <!-- Go to Preflight -->
      <a href="preflight.php" class="btn btn-light" style="border: 1px solid #6b7280; color: #6b7280; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; font-size: 0.8rem; font-weight: 600;">
        <?= ICON_PRINTER ?> Preflight Dashboard
      </a>
      
      <?php if ($isSentToVendor && !$isConfirmed && !$hasFileIssue): ?>
      <!-- Send Reminder -->
      <button onclick="sendPreflightReminder('<?= htmlspecialchars($refCode) ?>')" class="btn" style="background: #f59e0b; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-size: 0.8rem; font-weight: 600;">
        <?= ICON_BELL ?> Send Reminder
      </button>
      <?php endif; ?>
      
      <?php if ($hasFileIssue): ?>
      <!-- Resend to Vendor -->
      <button onclick="resendToVendor('<?= htmlspecialchars($refCode) ?>')" class="btn" style="background: #7c3aed; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-size: 0.8rem; font-weight: 600;">
        <?= ICON_CYCLE ?> Resend to Vendor
      </button>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php endif; // end hasPreflight check ?>
  </div>
</div>

<!-- Preflight Card JavaScript -->
<script>
function sendPreflightReminder(refCode) {
    if (!confirm('Send a confirmation reminder to the vendor for order ' + refCode + '?')) return;
    
    fetch('preflight.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax_action=send_manual_reminder&reference_code=' + encodeURIComponent(refCode)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Show notification
            if (typeof showNotification === 'function') {
                showNotification('Reminder sent successfully!', 'success');
            } else {
                alert('Reminder sent successfully!');
            }
            // Reload to update timeline
            setTimeout(() => location.reload(), 1500);
        } else {
            alert('Failed to send reminder: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => {
        alert('Error sending reminder: ' + err.message);
    });
}

function resendToVendor(refCode) {
    if (!confirm('This will resend the order to the vendor. Continue?')) return;
    
    // Redirect to preflight page with the order pre-selected
    window.location.href = 'preflight.php?resend=' + encodeURIComponent(refCode);
}
</script>
