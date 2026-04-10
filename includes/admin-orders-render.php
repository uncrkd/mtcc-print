<?php
/**
 * Admin Orders - Rendering Functions
 * MTCC Print Services
 *
 * Location: /includes/admin-orders-render.php
 * Extracted from: admin-orders.php
 *
 * Functions: generateShippingLabel, generateBulkShippingLabels,
 *            generateBulkOrderPrint, displayOrderView
 */

require_once __DIR__ . '/status-config.php';

function generateShippingLabel( $order ) {
  // Load statuses for this order
  $statusFile = 'data/statuses.json';
  $statuses = [];
  if ( file_exists( $statusFile ) ) {
    $statuses = json_decode( file_get_contents( $statusFile ), true ) ? : [];
  }
  $currentStatus = isset( $statuses[ $order[ 'referenceCode' ] ] ) ? $statuses[ $order[ 'referenceCode' ] ] : 'unpaid';

  // *** USE THE SHARED FUNCTION ***
  $trackingNumber = generateMTCCTrackingNumber( $order );

  // Determine shipping address
  $shipToAddress = '';
  if ( $order[ 'deliveryOption' ] === 'office' && isset( $order[ 'deliveryAddress' ] ) ) {
    // Use custom delivery address
    $addr = $order[ 'deliveryAddress' ];
    $shipToAddress = '<strong>' . htmlspecialchars( $order[ 'customerInfo' ][ 'name' ] ) . "</strong>\n";
    if ( !empty( $addr[ 'company' ] ) ) {
      $shipToAddress .= htmlspecialchars( $addr[ 'company' ] ) . "\n";
    }
    $shipToAddress .= "Attn: " . htmlspecialchars( $addr[ 'attn' ] ) . "\n";
    $shipToAddress .= htmlspecialchars( $addr[ 'address' ] );
    if ( !empty( $addr[ 'unit' ] ) ) {
      $shipToAddress .= " " . htmlspecialchars( $addr[ 'unit' ] );
    }
    $shipToAddress .= "\n" . htmlspecialchars( $addr[ 'city' ] ) . ", " . htmlspecialchars( $addr[ 'province' ] ) . " " . htmlspecialchars( $addr[ 'postal' ] );
  } else {
    // Use MTCC address
    $shipToAddress = '<strong>' . htmlspecialchars( $order[ 'customerInfo' ][ 'name' ] ) . "</strong>\n";
    $shipToAddress .= "Metro Toronto Convention Centre\n";
    $shipToAddress .= "Exhibitor Services - Business Centre\n";
    $shipToAddress .= "300 Level - Outside of Hall C\n";
    $shipToAddress .= "255 Front Street West\n";
    $shipToAddress .= "Toronto, ON M5V 2W6";
  }

  // Calculate due date (full format) and time
  $dueDate = date( 'l, F j, Y', strtotime( $order[ 'selectedDate' ] ) );
  $timeLabelsLabel = ['9am' => '9:00am', '12pm' => '12:00pm', '3pm' => '3:00pm', '6pm' => '6:00pm'];
  $dueTime = 'Anytime';
  if ( isset( $order['deliveryTime'] ) && $order['deliveryTime'] !== 'anytime' ) {
    $dueTime = 'by ' . ( $timeLabelsLabel[$order['deliveryTime']] ?? $order['deliveryTime'] );
  }

  ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Shipping Label -
<?= htmlspecialchars($order['referenceCode']) ?>
</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
<!-- JsBarcode Library from CDN --> 
<script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.5/JsBarcode.all.min.js"></script>
<style>
/* Updated Shipping Label Styles with Boxed Sections */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Montserrat', sans-serif;
}
@page {
    size: 4in 6in;
    margin: 2mm;
}
body {
    width: 4in;
    height: 6in;
    background: white;
    color: black;
    font-family: 'Montserrat', sans-serif;
    line-height: 1.2;
    overflow: hidden;
    position: relative;
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}
.label-container {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    position: relative;
    box-sizing: border-box;
    justify-content: space-between; /* This might be causing spacing */
}
/* Header with logo - 0.90" height */
.header {
    background: white;
    height: 0.90in;
    padding: 8px;
    text-align: center;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    box-sizing: border-box;
}
.logo {
    max-width: 375px;
    max-height: 90px;
    margin-bottom: 4px;
}
/* Ship From section - 0.35" height with horizontal divider */
.ship-from {
    background: white;
    height: 0.30in;
    padding: 0 8px;
    border-width: 2px 2px 2px 2px;
    border-color: black;
    border-style: solid;
    font-size: 8pt;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    box-sizing: border-box;
    white-space: nowrap;
    overflow: hidden;
    text-align: left;
}
.ship-from-label {
    font-weight: bold;
    margin-right: 8px;
}
/* Ship To section - 1.20" height with horizontal divider */
.ship-to-section {
    display: flex;
    background: white;
    border-width: 0px 2px 2px 2px;
    border-color: black;
    border-style: solid;
    height: 1.20in;
    box-sizing: border-box;
}
.ship-to-label {
    background: black;
    color: white;
    width: 1in;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 24pt;
    line-height: 1;
    box-sizing: border-box;
}
.ship-to-address {
    flex: 1;
    padding: 8px;
    font-size: 9pt;
    line-height: 1.3;
    /*white-space: pre-line; */
    display: flex;
    flex-direction: column;
    justify-content: center;
    box-sizing: border-box;
}
.ship-to-address strong {
    font-weight: bold;
    display: block;
    margin-bottom: 0px;
    font-size: 10pt;
}
/* Ship Via section - 0.35" height with horizontal divider */
.ship-via {
    background: white;
    height: 0.30in;
    padding: 0 8px;
    border-width: 0px 2px 2px 2px;
    border-color: black;
    border-style: solid;
    font-size: 8pt;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    box-sizing: border-box;
    text-align: left;
}
.ship-via-label {
    font-weight: bold;
    margin-right: 8px;
}
/* Gap between ship via and order number - 0.14" */
.spacer-gap {
    height: 0.14in;
    background: white;
}
/* Order number section - 0.37" height, black background */
.order-section {
    background: black;
    color: white;
    height: 0.30in;
    text-align: center;
    border-bottom: 1px solid black;
    display: flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
}
.order-number {
    font-size: 15pt;
    font-weight: bold;
    letter-spacing: 1px;
}
/* Job Name section - 0.27" height with left and right borders */
.job-name-row {
    background: white;
    height: 0.30in;
    border-bottom: 1px solid black;
    border-left: 2px solid black;
    border-right: 2px solid black;
    font-size: 10pt;
    display: flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
}
.job-name-label {
    font-weight: bold;
    margin-right: 8px;
}
/* Date row - 0.35" height with horizontal divider and side borders */
.date-row {
    display: flex;
    height: 0.30in;
    border-bottom: 2px solid black;
    border-left: 2px solid black;
    border-right: 2px solid black;
    box-sizing: border-box;
}
.date-cell {
    border-right: 1px solid black;
    font-size: 9pt;
    display: flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    text-align: center;
}
.date-cell-due {
    flex: 7;
}
.date-cell-time {
    flex: 3;
}
.date-cell:last-child {
    border-right: none;
}
.date-label {
    font-weight: bold;
    margin-right: 8px;
}
/* Items table with horizontal divider and side borders */
.items-section {
    flex: 1;
    background: white;
    position: relative;
    border-bottom: 2px solid black;
    border-left: 2px solid black;
    border-right: 2px solid black;
    display: flex;
    flex-direction: column;
}
.items-header {
    display: flex;
    background: black;
    color: white;
    border-bottom: 1px solid black;
    font-weight: bold;
    font-size: 8pt;
    text-transform: uppercase;
    min-height: 20px;
}
.col-qty {
    width: 40px;
    padding: 4px;
    border-right: 1px solid white;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
}
.col-type {
    width: 80px;
    padding: 4px;
    border-right: 1px solid white;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
}
.col-description {
    flex: 1;
    padding: 4px;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
}
.items-body {
    font-size: 8pt;
    flex: 1;
}
.item-row {
    display: flex;
    border-bottom: 1px solid #ddd;
    min-height: 20px;
}
.item-row .col-qty, .item-row .col-type, .item-row .col-description {
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 4px;
}
.item-row .col-qty {
    border-right: 1px solid black;
}
.item-row .col-type {
    border-right: 1px solid black;
}
/* Bottom section with barcode and box count side by side */
.bottom-section {
    position: absolute;
    bottom: 25px; /* Moved up from 15px to 35px */
    left: 8px;
    right: 8px;
    height: 0.60in;
    display: flex;
    gap: 4px;
    align-items: stretch;
}
/* Barcode container - left side */
.barcode-container {
    flex: 1;
    border: 1px solid black; /* Reduced from 2px */
    background: white;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-family: 'Montserrat', sans-serif;
    padding: 2px; /* Reduced from 4px */
}
.barcode-header {
    font-size: 6pt; /* Reduced from 7pt */
    font-weight: bold;
    background: black;
    color: white;
    padding: 1px 3px; /* Reduced from 2px 4px */
    width: 100%;
    text-align: center;
    box-sizing: border-box;
    margin-bottom: 1px; /* Reduced from 2px */
}
.barcode-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
}
#barcode {
    height: 20px; /* Reduced from 25px */
    margin-bottom: 1px;
}
.barcode-text {
    font-size: 6pt; /* Reduced from 7pt */
    font-family: 'Montserrat', sans-serif;
    font-weight: bold;
    color: black;
    text-align: center;
}
/* Box Count - right side */
.box-count-container {
    width: 0.75in; /* Reduced from 0.90in */
    border: 1px solid black; /* Reduced from 2px */
    background: white;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-family: 'Montserrat', sans-serif;
    flex-shrink: 0;
}
.box-count-header {
    font-size: 6pt; /* Reduced from 7pt */
    font-weight: bold;
    background: black;
    color: white;
    padding: 1px 3px; /* Reduced from 2px 4px */
    width: 100%;
    text-align: center;
    box-sizing: border-box;
}
.box-count-numbers {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px; /* Reduced from 6px */
    width: 100%;
    font-family: 'Montserrat', sans-serif;
    padding: 1px 0; /* Reduced from 2px 0 */
}
.count-large {
    font-size: 12pt; /* Reduced from 14pt */
    font-weight: bold;
    line-height: 1;
    font-family: 'Montserrat', sans-serif;
}
.count-of {
    font-size: 7pt; /* Reduced from 8pt */
    font-weight: bold;
    font-family: 'Montserrat', sans-serif;
    text-transform: uppercase;
    line-height: 1;
}
/* Footer */
.footer {
    background: black;
    color: white;
    padding: 4px 8px;
    text-align: center;
    font-size: 6.5pt;
    font-family: 'Montserrat', sans-serif;
    font-weight: bold;
    letter-spacing: 1px;
    position: fixed !important;
    bottom: 0mm !important; /* Account for the 2mm page margin */
    left: 0 !important;
    right: 0 !important;
    margin: 0 !important;
    z-index: 1000;
}

/* Print specific styles */
@media print {
body {
    -webkit-print-color-adjust: exact !important;
    color-adjust: exact !important;
    print-color-adjust: exact !important;
}
* {
    -webkit-print-color-adjust: exact !important;
    color-adjust: exact !important;
    print-color-adjust: exact !important;
}
.label-container {
    page-break-inside: avoid;
}
}
</style>
	
</head>
<body>
<div class="label-container"> 
  <!-- Header with logo -->
  <div class="header"> <img src="mtcc-ps-logo.png" alt="PrintStuff.ca" class="logo" onerror="this.style.display='none'"> </div>
  
  <!-- Ship From -->
  <div class="ship-from"> <span class="ship-from-label">Ship From:</span>100 King St. W, Suite 5700, Toronto, ON M5X 1C9 </div>
  
  <!-- Ship To -->
  <div class="ship-to-section">
    <div class="ship-to-label">
      <div>SHIP</div>
      <div>TO</div>
    </div>
    <div class="ship-to-address">
      <?= $shipToAddress ?>
    </div>
  </div>
  
  <!-- Ship Via -->
  <div class="ship-via"> <span class="ship-via-label">Ship Via:</span>Print Stuff Local Delivery </div>
  
  <!-- Gap -->
  <div class="spacer-gap"></div>
  
  <!-- Order Number -->
  <div class="order-section">
    <div class="order-number">ORDER #:
      <?= htmlspecialchars($order['referenceCode']) ?>
    </div>
  </div>
  
  <!-- Job Name -->
  <div class="job-name-row"> <span class="job-name-label">Job Name:</span>
    <?= htmlspecialchars($order['customerInfo']['name']) ?>
  </div>
  
  <!-- Due Date & Time -->
  <div class="date-row">
    <div class="date-cell date-cell-due"> <span class="date-label">Due:</span>
      <?= $dueDate ?>
    </div>
    <div class="date-cell date-cell-time"> <span class="date-label">Time:</span>
      <?= $dueTime ?>
    </div>
  </div>
  <!-- Gap -->
  <div class="spacer-gap"></div>
  
  <!-- Items Table -->
  <div class="items-section">
    <div class="items-header">
      <div class="col-qty">QTY</div>
      <div class="col-type">ITEM TYPE</div>
      <div class="col-description">DESCRIPTION</div>
    </div>
    <div class="items-body">
      <div class="item-row">
        <div class="col-qty">1</div>
        <div class="col-type">Poster</div>
        <div class="col-description">
          <?= $order['dimensions']['width'] ?>
          " x
          <?= $order['dimensions']['height'] ?>
          ,
          <?= ucfirst($order['material']) ?>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Bottom Section: Barcode and Box Count side by side -->
  <div class="bottom-section">
    <div class="barcode-container">
      <div class="barcode-header">TRACKING CODE</div>
      <div class="barcode-content">
        <svg id="barcode"></svg>
        <div class="barcode-text" id="barcodeText">
          <?= $trackingNumber ?>
        </div>
      </div>
    </div>
    <div class="box-count-container">
      <div class="box-count-header">BOX COUNT</div>
      <div class="box-count-numbers">
        <div class="count-large">01</div>
        <div class="count-of">OF</div>
        <div class="count-large">01</div>
      </div>
    </div>
  </div>
  
  <!-- Footer -->
  <div class="footer"> ORDERS@PRINTSTUFF.CA &nbsp;|&nbsp; 437.882.8822 &nbsp;|&nbsp; WWW.PRINTSTUFF.CA </div>
</div>

<!-- SHIPPING LABEL BARCODE GENERATION - KEEPING INLINE (WORKING, DON'T BREAK) --> 
<script>
window.onload = function() {
    // Use the tracking number generated by PHP
    const trackingNumber = '<?= $trackingNumber ?>';
    
    try {
        JsBarcode("#barcode", trackingNumber, {
            format: "CODE128",
            width: 1.5,
            height: 20,
            displayValue: false,
            margin: 0,
            background: "white",
            lineColor: "black"
        });
        
        document.getElementById('barcodeText').textContent = trackingNumber;
        console.log('Shipping label barcode generated:', trackingNumber);
    } catch (error) {
        console.error('Error generating barcode:', error);
        document.getElementById('barcodeText').textContent = trackingNumber;
    }
    
    // Auto-print
    setTimeout(function() {
        window.print();
        setTimeout(() => window.close(), 1000);
    }, 200);
};
</script>
</body>
</html>
<?php
}

// BULK SHIPPING LABELS - Uses EXACT same layout as single label
function generateBulkShippingLabels( $orders, $statuses ) {
  // This function simply includes each label's content with page breaks
  // It uses the exact same CSS and HTML as generateShippingLabel
  
  // First, output the document header with all the label CSS
  ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Shipping Labels - <?= count($orders) ?> Orders</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.5/JsBarcode.all.min.js"></script>
<style>
/* EXACT COPY of shipping label styles */
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Montserrat', sans-serif; }
@page { size: 4in 6in; margin: 2mm; }
@media screen {
    body { background: #f1f5f9; padding: 20px; }
    .label-wrapper { background: white; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin: 20px auto; }
    .page-break { height: 40px; background: transparent; }
    .no-print { display: block; }
}
@media print {
    body { background: white; padding: 0; margin: 0; }
    .page-break { page-break-after: always; height: 0; }
    .no-print { display: none !important; }
    .label-wrapper { box-shadow: none; margin: 0; }
    * { -webkit-print-color-adjust: exact !important; color-adjust: exact !important; print-color-adjust: exact !important; }
}
.label-wrapper { width: 4in; height: 6in; background: white; color: black; line-height: 1.2; overflow: hidden; position: relative; }
.label-container { width: 100%; height: 100%; display: flex; flex-direction: column; position: relative; box-sizing: border-box; justify-content: space-between; }
.header { background: white; height: 0.90in; padding: 8px; text-align: center; display: flex; flex-direction: column; justify-content: center; align-items: center; box-sizing: border-box; }
.logo { max-width: 375px; max-height: 90px; margin-bottom: 4px; }
.ship-from { background: white; height: 0.30in; padding: 0 8px; border-width: 2px 2px 2px 2px; border-color: black; border-style: solid; font-size: 8pt; display: flex; align-items: center; justify-content: flex-start; box-sizing: border-box; white-space: nowrap; overflow: hidden; text-align: left; }
.ship-from-label { font-weight: bold; margin-right: 8px; }
.ship-to-section { display: flex; background: white; border-width: 0px 2px 2px 2px; border-color: black; border-style: solid; height: 1.20in; box-sizing: border-box; }
.ship-to-label { background: black; color: white; width: 1in; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; font-weight: bold; font-size: 24pt; line-height: 1; box-sizing: border-box; }
.ship-to-address { flex: 1; padding: 8px; font-size: 9pt; line-height: 1.3; display: flex; flex-direction: column; justify-content: center; box-sizing: border-box; }
.ship-to-address strong { font-weight: bold; display: block; margin-bottom: 0px; font-size: 10pt; }
.ship-via { background: white; height: 0.30in; padding: 0 8px; border-width: 0px 2px 2px 2px; border-color: black; border-style: solid; font-size: 8pt; display: flex; align-items: center; justify-content: flex-start; box-sizing: border-box; text-align: left; }
.ship-via-label { font-weight: bold; margin-right: 8px; }
.spacer-gap { height: 0.14in; background: white; }
.order-section { background: black; color: white; height: 0.30in; text-align: center; border-bottom: 1px solid black; display: flex; align-items: center; justify-content: center; box-sizing: border-box; }
.order-number { font-size: 15pt; font-weight: bold; letter-spacing: 1px; }
.job-name-row { background: white; height: 0.30in; border-bottom: 1px solid black; border-left: 2px solid black; border-right: 2px solid black; font-size: 10pt; display: flex; align-items: center; justify-content: center; box-sizing: border-box; }
.job-name-label { font-weight: bold; margin-right: 8px; }
.date-row { display: flex; height: 0.30in; border-bottom: 2px solid black; border-left: 2px solid black; border-right: 2px solid black; box-sizing: border-box; }
.date-cell { border-right: 1px solid black; font-size: 9pt; display: flex; align-items: center; justify-content: center; box-sizing: border-box; text-align: center; }
.date-cell-due { flex: 7; }
.date-cell-time { flex: 3; }
.date-cell:last-child { border-right: none; }
.date-label { font-weight: bold; margin-right: 8px; }
.items-section { flex: 1; background: white; position: relative; border-bottom: 2px solid black; border-left: 2px solid black; border-right: 2px solid black; display: flex; flex-direction: column; }
.items-header { display: flex; background: black; color: white; border-bottom: 1px solid black; font-weight: bold; font-size: 8pt; text-transform: uppercase; min-height: 20px; }
.col-qty { width: 40px; padding: 4px; border-right: 1px solid white; text-align: center; display: flex; align-items: center; justify-content: center; }
.col-type { width: 80px; padding: 4px; border-right: 1px solid white; text-align: center; display: flex; align-items: center; justify-content: center; }
.col-description { flex: 1; padding: 4px; text-align: center; display: flex; align-items: center; justify-content: center; }
.items-body { font-size: 8pt; flex: 1; }
.item-row { display: flex; border-bottom: 1px solid #ddd; min-height: 20px; }
.item-row .col-qty, .item-row .col-type, .item-row .col-description { display: flex; align-items: center; justify-content: center; text-align: center; padding: 4px; }
.item-row .col-qty { border-right: 1px solid black; }
.item-row .col-type { border-right: 1px solid black; }
.bottom-section { position: absolute; bottom: 25px; left: 8px; right: 8px; height: 0.60in; display: flex; gap: 4px; align-items: stretch; }
.barcode-container { flex: 1; border: 1px solid black; background: white; display: flex; flex-direction: column; align-items: center; justify-content: center; font-family: 'Montserrat', sans-serif; padding: 2px; }
.barcode-header { font-size: 6pt; font-weight: bold; background: black; color: white; padding: 1px 3px; width: 100%; text-align: center; box-sizing: border-box; margin-bottom: 1px; }
.barcode-content { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100%; }
.barcode-svg { height: 20px; margin-bottom: 1px; }
.barcode-text { font-size: 6pt; font-family: 'Montserrat', sans-serif; font-weight: bold; color: black; text-align: center; }
.box-count-container { width: 0.75in; border: 1px solid black; background: white; display: flex; flex-direction: column; align-items: center; justify-content: center; font-family: 'Montserrat', sans-serif; flex-shrink: 0; }
.box-count-header { font-size: 6pt; font-weight: bold; background: black; color: white; padding: 1px 3px; width: 100%; text-align: center; box-sizing: border-box; }
.box-count-numbers { flex: 1; display: flex; align-items: center; justify-content: center; gap: 4px; width: 100%; font-family: 'Montserrat', sans-serif; padding: 1px 0; }
.count-large { font-size: 12pt; font-weight: bold; line-height: 1; font-family: 'Montserrat', sans-serif; }
.count-of { font-size: 7pt; font-weight: bold; font-family: 'Montserrat', sans-serif; text-transform: uppercase; line-height: 1; }
.footer { background: black; color: white; padding: 4px 8px; text-align: center; font-size: 6.5pt; font-family: 'Montserrat', sans-serif; font-weight: bold; letter-spacing: 1px; position: absolute; bottom: 0; left: 0; right: 0; }
.print-btn { position: fixed; top: 20px; right: 20px; padding: 12px 24px; background: linear-gradient(135deg, #7c3aed 0%, #6366f1 100%); color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; z-index: 1000; }
</style>
</head>
<body>
<button class="print-btn no-print" onclick="window.print()"><?= ICON_PRINTER ?> Print All Labels (<?= count($orders) ?>)</button>

<?php 
foreach ($orders as $index => $order): 
    $trackingNumber = generateMTCCTrackingNumber($order);
    
    // Determine shipping address - EXACT same logic as generateShippingLabel
    $shipToAddress = '';
    if ($order['deliveryOption'] === 'office' && isset($order['deliveryAddress'])) {
        $addr = $order['deliveryAddress'];
        $shipToAddress = '<strong>' . htmlspecialchars($order['customerInfo']['name']) . "</strong><br>";
        if (!empty($addr['company'])) {
            $shipToAddress .= htmlspecialchars($addr['company']) . "<br>";
        }
        $shipToAddress .= "Attn: " . htmlspecialchars($addr['attn']) . "<br>";
        $shipToAddress .= htmlspecialchars($addr['address']);
        if (!empty($addr['unit'])) {
            $shipToAddress .= " " . htmlspecialchars($addr['unit']);
        }
        $shipToAddress .= "<br>" . htmlspecialchars($addr['city']) . ", " . htmlspecialchars($addr['province']) . " " . htmlspecialchars($addr['postal']);
    } else {
        $shipToAddress = '<strong>' . htmlspecialchars($order['customerInfo']['name']) . "</strong><br>";
        $shipToAddress .= "Metro Toronto Convention Centre<br>";
        $shipToAddress .= "Exhibitor Services - Business Centre<br>";
        $shipToAddress .= "300 Level - Outside of Hall C<br>";
        $shipToAddress .= "255 Front Street West<br>";
        $shipToAddress .= "Toronto, ON M5V 2W6";
    }
    
    $dueDate = date('l, F j, Y', strtotime($order['selectedDate']));
    $timeLabelsLabel = ['9am' => '9:00am', '12pm' => '12:00pm', '3pm' => '3:00pm', '6pm' => '6:00pm'];
    $dueTime = 'Anytime';
    if ( isset( $order['deliveryTime'] ) && $order['deliveryTime'] !== 'anytime' ) {
      $dueTime = 'by ' . ( $timeLabelsLabel[$order['deliveryTime']] ?? $order['deliveryTime'] );
    }
?>
<div class="label-wrapper">
<div class="label-container">
  <div class="header"><img src="logo.png" alt="PrintStuff.ca" class="logo" onerror="this.style.display='none'"></div>
  <div class="ship-from"><span class="ship-from-label">Ship From:</span>100 King St. W, Suite 5700, Toronto, ON M5X 1C9</div>
  <div class="ship-to-section">
    <div class="ship-to-label"><div>SHIP</div><div>TO</div></div>
    <div class="ship-to-address"><?= $shipToAddress ?></div>
  </div>
  <div class="ship-via"><span class="ship-via-label">Ship Via:</span>Print Stuff Local Delivery</div>
  <div class="spacer-gap"></div>
  <div class="order-section"><div class="order-number">ORDER #: <?= htmlspecialchars($order['referenceCode']) ?></div></div>
  <div class="job-name-row"><span class="job-name-label">Job Name:</span><?= htmlspecialchars($order['customerInfo']['name']) ?></div>
  <div class="date-row">
    <div class="date-cell date-cell-due"><span class="date-label">Due:</span><?= $dueDate ?></div>
    <div class="date-cell date-cell-time"><span class="date-label">Time:</span><?= $dueTime ?></div>
  </div>
  <div class="spacer-gap"></div>
  <div class="items-section">
    <div class="items-header">
      <div class="col-qty">QTY</div>
      <div class="col-type">ITEM TYPE</div>
      <div class="col-description">DESCRIPTION</div>
    </div>
    <div class="items-body">
      <div class="item-row">
        <div class="col-qty">1</div>
        <div class="col-type">Poster</div>
        <div class="col-description"><?= $order['dimensions']['width'] ?>" x <?= $order['dimensions']['height'] ?>", <?= ucfirst($order['material'] ?? 'poster') ?></div>
      </div>
    </div>
  </div>
  <div class="bottom-section">
    <div class="barcode-container">
      <div class="barcode-header">TRACKING CODE</div>
      <div class="barcode-content">
        <svg class="barcode-svg" id="barcode_<?= $index ?>"></svg>
        <div class="barcode-text"><?= $trackingNumber ?></div>
      </div>
    </div>
    <div class="box-count-container">
      <div class="box-count-header">BOX COUNT</div>
      <div class="box-count-numbers">
        <div class="count-large">01</div>
        <div class="count-of">OF</div>
        <div class="count-large">01</div>
      </div>
    </div>
  </div>
  <div class="footer">ORDERS@PRINTSTUFF.CA &nbsp;|&nbsp; 437.882.8822 &nbsp;|&nbsp; WWW.PRINTSTUFF.CA</div>
</div>
</div>
<?php if ($index < count($orders) - 1): ?><div class="page-break"></div><?php endif; ?>
<?php endforeach; ?>

<script>
window.onload = function() {
<?php foreach ($orders as $index => $order): 
    $trackingNumber = generateMTCCTrackingNumber($order);
?>
    try {
        JsBarcode("#barcode_<?= $index ?>", "<?= $trackingNumber ?>", {
            format: "CODE128", width: 1.5, height: 20, displayValue: false, margin: 0, background: "white", lineColor: "black"
        });
    } catch(e) { console.error('Barcode error:', e); }
<?php endforeach; ?>
};
</script>
</body>
</html>
<?php
}

// BULK ORDER PRINT - Opens each order detail in iframes for exact layout match
function generateBulkOrderPrint( $orders, $statuses ) {
    // Status configuration - uses centralized status-config.php
    $statusLabelsMap = getStatusLabelsForRole('admin');
    $statusColorsMap = getStatusColors();
    $statusConfig = [];
    foreach ($statusLabelsMap as $code => $label) {
        $statusConfig[$code] = ['label' => $label, 'color' => $statusColorsMap[$code] ?? '#6b7280', 'class' => $code];
    }
    
    $statusIcons = [
        'unpaid' => ICON_HOURGLASS, 'paid' => ICON_MONEY_BAG, 'file_issue' => ICON_EYE,
        'printing' => ICON_PRINTER,
        'preflight' => ICON_EYE,
        'ready' => ICON_PACKAGE,
        'dispatched' => ICON_TRUCK,
        'shipped' => ICON_TRUCK, 'delivered' => ICON_PACKAGE,
        'pickedup' => ICON_CHECK_GREEN, 'unclaimed' => ICON_MAILBOX, 'missing' => ICON_WARNING,
        'cancelled' => ICON_CROSS, 'refunded' => ICON_SIREN
    ];
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Print Orders - <?= count($orders) ?> Orders</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.5/JsBarcode.all.min.js"></script>

<!-- EXACT same CSS files as displayOrderView -->
<link rel="stylesheet" href="css/admin-base.css">
<link rel="stylesheet" href="css/admin-components.css">
<link rel="stylesheet" href="css/admin-layout.css">
<link rel="stylesheet" href="css/admin-tables.css">
<link rel="stylesheet" href="css/admin-responsive.css">
<link rel="stylesheet" href="css/admin-print.css" media="print">

<!-- Online Indicator Styles -->
<style>
<?= getOnlineIndicatorCSS() ?>
</style>

<style>
/* Screen preview styles */
@media screen {
    body { background: #f1f5f9 !important; }
    .order-page-wrapper {
        background: white;
        max-width: 1200px;
        margin: 20px auto;
        padding: 20px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        border-radius: 8px;
    }
    .page-break {
        height: 40px;
        background: linear-gradient(to right, transparent, #e2e8f0 10%, #e2e8f0 90%, transparent);
        margin: 20px 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .page-break::before {
        content: '&laquo;&laquo; Page Break &laquo;&laquo;';
        color: #94a3b8;
        font-size: 11px;
        font-weight: 500;
        letter-spacing: 1px;
        background: #f1f5f9;
        padding: 0 16px;
    }
}

/* Print button */
.print-btn-fixed {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 12px 24px;
    background: linear-gradient(135deg, #7c3aed 0%, #6366f1 100%);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    z-index: 1000;
    font-family: 'Montserrat', sans-serif;
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
}
.print-btn-fixed:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(124, 58, 237, 0.4);
}

/* Hide buttons and actions in print */
@media print {
    .print-btn-fixed,
    .header-actions,
    .dashboard-actions,
    .action-buttons-container,
    .btn, button:not(.print-btn-fixed),
    .barcode-actions {
        display: none !important;
    }
    .page-break {
        height: 0;
        margin: 0;
        background: none;
        border: none;
        page-break-after: always;
    }
    .page-break::before {
        display: none;
    }
    .order-page-wrapper {
        box-shadow: none;
        margin: 0;
        padding: 0;
        max-width: none;
    }
    * {
        -webkit-print-color-adjust: exact !important;
        color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
}
</style>
</head>
<body>
<button class="print-btn-fixed" onclick="window.print()"><?= ICON_PRINTER ?> Print All Orders (<?= count($orders) ?>)</button>

<?php foreach ($orders as $index => $order): 
    $currentStatus = isset($statuses[$order['referenceCode']]) ? $statuses[$order['referenceCode']] : 'unpaid';
    $statusInfo = $statusConfig[$currentStatus] ?? $statusConfig['unpaid'];
    $trackingNumber = generateMTCCTrackingNumber($order);
    
    // Phone display - EXACT match
    $phoneDisplay = htmlspecialchars($order['customerInfo']['phone'] ?? 'Not provided');
    if (isset($order['customerInfo']['countryCode']) && !empty($order['customerInfo']['countryCode']) && $order['customerInfo']['countryCode'] !== '+1') {
        $phoneDisplay .= ' (' . htmlspecialchars($order['customerInfo']['countryCode']) . ')';
    }
    
    // Priority class - EXACT match to displayOrderView
    $priorityClass = 'priority-tier-standard';
    $tier = strtolower($order['pricing']['tier'] ?? 'standard');
    if (strpos($tier, 'last minute') !== false) $priorityClass = 'priority-tier-lastminute';
    elseif (strpos($tier, 'early') !== false) $priorityClass = 'priority-tier-early';
    elseif (strpos($tier, 'rush') !== false) $priorityClass = 'priority-tier-rush';
    elseif (strpos($tier, 'urgent') !== false) $priorityClass = 'priority-tier-urgent';
    elseif (strpos($tier, 'critical') !== false) $priorityClass = 'priority-tier-critical';
    
    // Delivery time display - EXACT match
    $deliveryTimeDisplay = '';
    if (isset($order['deliveryTime']) && $order['deliveryTime'] !== 'anytime') {
        $timeLabels = ['9am' => '9:00am', '12pm' => '12:00pm', '3pm' => '3:00pm', '6pm' => '6:00pm'];
        $deliveryTimeDisplay = ' at ' . ($timeLabels[$order['deliveryTime']] ?? $order['deliveryTime']);
    } else {
        $deliveryTimeDisplay = ' at anytime';
    }
?>
<div class="order-page-wrapper">
<div class="container">

<!-- LOGO HEADER - Exact match to displayOrderView -->
<div>
    <img src="mtcc-ps-logo.png" alt="Logo" style="height: 60px; width: auto; margin:0 auto; display:block; margin-bottom: 1rem;" onerror="this.style.display='none'">
</div>

<div class="dashboard-header">
    <div class="dashboard-content">
        <div class="dashboard-main-title">Order Details</div>
        <div class="dashboard-divider"></div>
        <div class="dashboard-welcome">
            <h1>Order Reference #: <?= htmlspecialchars($order['referenceCode']) ?></h1>
            <div class="dashboard-subtitle">Today is <?= date('l, j M Y') ?></div>
        </div>
    </div>
</div>

<!-- ORDER HEADER CARD - Exact match -->
<div class="header status-<?= $currentStatus ?>">
  <div class="header-top-row">
    <div style="display: flex; align-items: center; gap: var(--space-lg);">
      <div class="submitted-info"><?= ICON_CALENDAR ?>  Submitted: <strong><?= date('M j, Y \a\t g:i A', strtotime($order['submittedAt'])) ?></strong></div>
      <div style="width: 1px; height: 20px; background: #e5e7eb;"></div>
      <div style="display: flex; align-items: center; gap: var(--space-sm);">
        <span style="font-size: 0.8rem; color: var(--subtext); font-weight: 500;">Priority Tier:</span>
        <span class="priority-tier-badge <?= $priorityClass ?>"><?= htmlspecialchars($order['pricing']['tier'] ?? 'Standard') ?></span>
      </div>
    </div>
  </div>
  <div class="header-main-row">
    <div class="order-number-section">
      <div class="order-section-header">Order Number</div>
      <div class="order-number"><?= htmlspecialchars($order['referenceCode']) ?></div>
    </div>
    <div class="header-divider"></div>
    <div class="due-date-section">
      <div class="order-section-header">Due Date</div>
      <div class="due-date-info">
        <div class="due-date-main"><?= date('l, F j, Y', strtotime($order['selectedDate'])) . $deliveryTimeDisplay ?></div>
      </div>
    </div>
    <div class="status-section">
      <div class="order-section-header">Current Status</div>
      <span class="status-badge-large status-<?= $currentStatus ?>">
        <?= $statusIcons[$currentStatus] ?? ICON_MEMO ?>
        <?= $statusInfo['label'] ?>
      </span>
    </div>
  </div>
</div>

<!-- VIEW MODE INFO GRID - Exact match to displayOrderView -->
<div class="info-grid"> 
  <!-- Left Card: Customer & Delivery Info -->
  <div class="card card-compact">
    <div class="section-header"><span class="card-icon"><?= ICON_USER ?></span> Customer & Delivery Information</div>
    
    <!-- Customer Details -->
    <div class="subsection-header">Customer Details</div>
    <div class="grid-2">
      <div class="detail-item">
        <div class="detail-label">Name</div>
        <div class="detail-value"><?= htmlspecialchars($order['customerInfo']['name']) ?></div>
      </div>
      <?php if (isset($order['customerInfo']['company']) && !empty($order['customerInfo']['company'])): ?>
      <div class="detail-item">
        <div class="detail-label">Company/Organization</div>
        <div class="detail-value"><?= htmlspecialchars($order['customerInfo']['company']) ?></div>
      </div>
      <?php endif; ?>
    </div>
    <div class="grid-2">
      <div class="detail-item">
        <div class="detail-label">Email</div>
        <div class="detail-value"><?= htmlspecialchars($order['customerInfo']['email']) ?></div>
      </div>
      <div class="detail-item">
        <div class="detail-label">Phone</div>
        <div class="detail-value"><?= $phoneDisplay ?></div>
      </div>
    </div>
    <?php if (!empty($order['customerInfo']['additionalNotes'])): ?>
    <div class="detail-item">
      <div class="detail-label">Notes</div>
      <div class="detail-value" style="background: #f8fafc; padding: var(--space-sm); border-radius: var(--radius); font-style: italic;">
        <?= nl2br(htmlspecialchars($order['customerInfo']['additionalNotes'])) ?>
      </div>
    </div>
    <?php endif; ?>
    
    <!-- Delivery Information -->
    <div class="subsection-header" style="margin-top: var(--space-lg);">Delivery Information</div>
    <div class="detail-item">
      <div class="detail-label">Method</div>
      <div class="detail-value">
        <?php if (($order['deliveryOption'] ?? 'mtcc') === 'mtcc'): ?>
        <?= ICON_FLAG ?> MTCC Delivery (Free)
        <?php else: ?>
        <?= ICON_TRUCK ?> Address Delivery (+$10.00)
        <?php endif; ?>
      </div>
    </div>
    <div class="detail-item">
      <div class="detail-label">Address</div>
      <div class="detail-value">
        <?php if (($order['deliveryOption'] ?? 'mtcc') === 'office' && isset($order['deliveryAddress'])): ?>
        <div class="address-display">
          <?php if (!empty($order['deliveryAddress']['company'])): ?>
          <div class="address-line"><strong><?= htmlspecialchars($order['deliveryAddress']['company']) ?></strong></div>
          <?php endif; ?>
          <div class="address-line">Attn: <?= htmlspecialchars($order['deliveryAddress']['attn']) ?></div>
          <div class="address-line"><?= htmlspecialchars($order['deliveryAddress']['address']) ?><?= !empty($order['deliveryAddress']['unit']) ? ' ' . htmlspecialchars($order['deliveryAddress']['unit']) : '' ?></div>
          <div class="address-line"><?= htmlspecialchars($order['deliveryAddress']['city']) ?>, <?= htmlspecialchars($order['deliveryAddress']['province']) ?> <?= htmlspecialchars($order['deliveryAddress']['postal']) ?></div>
        </div>
        <?php else: ?>
        <div class="address-display">
          <div class="address-line"><strong>Metro Toronto Convention Centre</strong></div>
          <div class="address-line">Exhibitor Services Office</div>
          <div class="address-line">North Building, Level 300</div>
          <div class="address-line">255 Front Street West,</div>
          <div class="address-line">Toronto, ON M5V 2W6</div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  
  <!-- Right Card: Poster, File & Pricing -->
  <div class="card card-compact">
    <div class="section-header"><span class="card-icon"><?= ICON_MEMO ?></span> Poster Details & Pricing</div>
    
    <!-- Poster Specifications -->
    <div class="subsection-header">Poster Specifications</div>
    <div class="poster-specs-grid">
      <div class="spec-item">
        <div class="spec-label">Dimensions</div>
        <div class="spec-value"><?= $order['dimensions']['width'] ?>"  x  <?= $order['dimensions']['height'] ?>"</div>
      </div>
      <div class="spec-item">
        <div class="spec-label">Material</div>
        <div class="spec-value"><?= ucfirst($order['material'] ?? 'Poster Paper') ?></div>
      </div>
    </div>
    
    <!-- Uploaded File -->
    <?php if (isset($order['uploadedFile'])): ?>
    <div class="subsection-header" style="margin-top: var(--space-lg);">Uploaded Artwork</div>
    <div style="display: flex; align-items: center; justify-content: space-between; padding: 15px; background: #f8fafc; border-radius: 8px; border: 1px solid #e5e7eb;">
      <div>
        <div style="font-weight: 600; color: #374151; margin-bottom: 4px;">
          <?= htmlspecialchars(getDisplayFileName($order['referenceCode'] ?? '', $order['uploadedFile']['originalName'])) ?>
        </div>
        <div style="font-size: 0.9rem; color: #6b7280;">
          <?php
          $fileExt = strtolower(pathinfo($order['uploadedFile']['originalName'], PATHINFO_EXTENSION));
          echo formatFileSize($order['uploadedFile']['size']) . ' <?= SYMBOL_BULLET ?> ' . strtoupper($fileExt);
          ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
    
    <!-- Pricing Breakdown -->
    <div class="subsection-header" style="margin-top: var(--space-lg);">Pricing Breakdown</div>
    <div class="pricing-condensed">
      <div class="pricing-items">
        <div class="pricing-item">
          <div class="pricing-label">Base</div>
          <div class="pricing-value">$<?= number_format($order['pricing']['basePrice'] ?? $order['pricing']['base'] ?? 0, 2) ?></div>
        </div>
        <div class="pricing-item">
          <div class="pricing-label">Delivery</div>
          <div class="pricing-value">$<?= number_format($order['pricing']['deliveryFee'] ?? $order['pricing']['delivery'] ?? 0, 2) ?></div>
        </div>
        <?php if (isset($order['pricing']['conversionFee']) && $order['pricing']['conversionFee'] > 0): ?>
        <div class="pricing-item">
          <div class="pricing-label">File Fee</div>
          <div class="pricing-value">$<?= number_format($order['pricing']['conversionFee'], 2) ?></div>
        </div>
        <?php endif; ?>
        <div class="pricing-item">
          <div class="pricing-label">Tax (13%)</div>
          <div class="pricing-value">$<?= number_format($order['pricing']['tax'] ?? 0, 2) ?></div>
        </div>
        <div class="pricing-item pricing-item-total">
          <div class="pricing-label">Total</div>
          <div class="pricing-value">$<?= number_format($order['pricing']['total'] ?? 0, 2) ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- BARCODE CARD - Exact match to displayOrderView -->
<div class="barcode-card">
  <div class="section-header"><span class="card-icon"><?= ICON_PACKAGE ?></span> Tracking Information</div>
  <div class="barcode-visual-section">
    <div class="barcode-header">Tracking Code</div>
    <div class="barcode-display">
      <svg id="orderBarcode_<?= $index ?>"></svg>
      <div class="barcode-number"><?= $trackingNumber ?></div>
    </div>
  </div>
</div>

</div>
</div>
<?php if ($index < count($orders) - 1): ?><div class="page-break"></div><?php endif; ?>
<?php endforeach; ?>

<script>
window.onload = function() {
<?php foreach ($orders as $index => $order): 
    $trackingNumber = generateMTCCTrackingNumber($order);
?>
    try {
        JsBarcode("#orderBarcode_<?= $index ?>", "<?= $trackingNumber ?>", {
            format: "CODE128",
            width: 2,
            height: 60,
            displayValue: false,
            margin: 5,
            background: "white",
            lineColor: "black"
        });
    } catch(e) { console.error('Barcode error for order <?= $index ?>:', e); }
<?php endforeach; ?>
};
</script>
</body>
</html>
<?php
}


// Order view function - ENHANCED IMPLEMENTATION WITH COLLABORATIVE NOTES
function displayOrderView( $order ) {
  // Permission check - use global permission variables
  $canEditOrders = hasPermission('orders_edit');
  $canDeleteOrders = hasPermission('orders_delete');
$canViewVendor = in_array($_SESSION['admin_role'] ?? '', ['god_mode', 'super_admin']);
  
  // Load statuses for this order
  $statusFile = 'data/statuses.json';
  $statuses = [];
  if ( file_exists( $statusFile ) ) {
    $statuses = json_decode( file_get_contents( $statusFile ), true ) ? : [];
  }
  $currentStatus = isset( $statuses[ $order[ 'referenceCode' ] ] ) ? $statuses[ $order[ 'referenceCode' ] ] : 'unpaid';

  // Check if we're in edit mode (only allow if user has edit permission)
  $isEditMode = isset( $_GET[ 'edit' ] ) && $_GET[ 'edit' ] == '1' && $canEditOrders;
  $showUpdatedMessage = isset( $_GET[ 'updated' ] ) && $_GET[ 'updated' ] == '1';

  // Check for error messages
  $showErrorMessage = isset( $_GET[ 'error' ] );
  $errorMessage = '';

  if ( $showErrorMessage ) {
    switch ( $_GET[ 'error' ] ) {
      case 'order_not_found':
        $errorMessage = 'Order not found. It may have been deleted or moved.';
        break;
      case 'save_failed':
        $errorMessage = 'Failed to save order: ' . ( isset( $_GET[ 'message' ] ) ? htmlspecialchars( $_GET[ 'message' ] ) : 'Unknown error' );
        break;
      default:
        $errorMessage = 'An error occurred while processing your request.';
    }
  }

  // Status configuration - uses centralized status-config.php
  $statusLabelsMap = getStatusLabelsForRole('admin');
  $statusColorsMap = getStatusColors();
  $statusConfig = [];
  foreach ($statusLabelsMap as $code => $label) {
    $statusConfig[$code] = [ 'label' => $label, 'color' => $statusColorsMap[$code] ?? '#6b7280', 'class' => $code ];
  }

  // Generate the tracking number once in PHP
  $trackingNumber = generateMTCCTrackingNumber( $order );
  ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>
<?= $isEditMode ? 'Edit' : 'Order Details' ?>
-
<?= htmlspecialchars($order['referenceCode']) ?>
</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.5/JsBarcode.all.min.js"></script> 

<!--<link rel="stylesheet" href="admin-orders.css">--> 

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


	<style>
.notification-slider {
    position: fixed;
    top: 20px;
    right: -400px;
    width: 350px;
    z-index: 10000;
    transition: right 0.3s ease-in-out;
    font-family: 'Montserrat', Arial, sans-serif;
}

.notification-slider.show {
    right: 20px;
}

.notification-content {
    background-color: #ffffff;
    border-radius: 12px;
    padding: 16px 20px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    border-left: 4px solid #10b981;
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
}

.notification-slider.notification-error .notification-content {
    border-left-color: #ef4444;
}

.notification-icon {
    font-size: 18px;
    flex-shrink: 0;
}

.notification-message {
    flex: 1;
    color: #374151;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.4;
}

.notification-close {
    background: none;
    border: none;
    font-size: 18px;
    color: #9CA3AF;
    cursor: pointer;
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: background-color 0.2s ease;
    flex-shrink: 0;
}

.notification-close:hover {
    background-color: #f3f4f6;
    color: #6b7280;
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@media (max-width: 480px) {
    .notification-slider {
        width: calc(100vw - 40px);
        right: -100vw;
    }
    
    .notification-slider.show {
        right: 20px;
    }
}

/* COGS column removed */
</style>
<!-- Icon Library for JavaScript -->
<?php outputIconsScript(); ?>
</head>
<body<?= $isEditMode ? ' class="edit-mode"' : '' ?>>
<?php require_once __DIR__ . '/admin-sidebar.php'; renderSidebar('orders'); ?>
<script src="js/admin-sidebar.js"></script>
<script>
// Pass PHP data to JavaScript modules
window.adminUtilities.orderReferenceCode = '<?= htmlspecialchars($order['referenceCode']) ?>';
window.adminUtilities.orderDueDate = '<?= htmlspecialchars($order['selectedDate']) ?>';
window.adminUtilities.currentStatus = '<?= $currentStatus ?>';
window.orderReferenceCode = '<?= htmlspecialchars($order['referenceCode']) ?>'; // Legacy support
window.orderDueDate = '<?= htmlspecialchars($order['selectedDate']) ?>'; // Legacy support
</script>
<div class="container">
    <!-- Print-only header with logo (visible only when printing) -->
    <div class="print-only-header">
      <img src="/mtcc-ps-logo.png" alt="MTCC Print Services" class="print-logo" onerror="this.style.display='none'">
    </div>

    <!-- Top Logo Bar -->
    <div class="top-logo-bar" style="padding: 12px 0; margin: 0;">
      <div class="logo-left">
        <a href="admin-orders.php">
          <img src="/mtcc-ps-logo.png" alt="MTCC Print Services" class="top-logo" onerror="this.style.display='none'">
        </a>
      </div>
      <div class="logo-right">
            <?= renderAdminNav('orders') ?>
          </div>
    </div>

    <!-- Page Header - Purple for View, Grey for Edit -->
    <?php if ($isEditMode): ?>
    <div class="page-header" style="margin: 0;">
      <div class="page-header-left">
        <h1 class="page-title">
          <a href="admin-orders.php" class="page-title-back" title="Back to Orders"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6-6 6 6 6"/><path d="M3 12h14"/><path d="M21 19V5"/></svg></a>
          Order Details: Edit Mode
        </h1>
        <div class="page-welcome">
          <span class="welcome-text">Editing order <?= htmlspecialchars($order['referenceCode']) ?> <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-0.15em;"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/></svg></span>
          <span class="welcome-date">Today is <?= date('l, F j, Y') ?></span>
        </div>
      </div>
      <div class="page-header-right">
      </div>
    </div>
    <?php else: ?>
    <div class="page-header" style="margin: 0;">
      <div class="page-header-left">
        <h1 class="page-title">
          <a href="admin-orders.php" class="page-title-back" title="Back to Orders"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6-6 6 6 6"/><path d="M3 12h14"/><path d="M21 19V5"/></svg></a>
          Order Details
        </h1>
        <div class="page-welcome">
          <span class="welcome-text">Order <?= htmlspecialchars($order['referenceCode']) ?></span>
          <span class="welcome-date">Today is <?= date('l, F j, Y') ?></span>
        </div>
      </div>
      <div class="page-header-right">
        <button onclick="printOrderDetails()" class="top-nav-btn"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"/><rect x="6" y="14" width="12" height="8" rx="1"/></svg> Print</button>
        <button onclick="printShippingLabel()" class="top-nav-btn"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/></svg> Print Label</button>
        <button onclick="resendConfirmationEmail('<?= htmlspecialchars($order['referenceCode']) ?>')"
              class="top-nav-btn"
              title="Send to: <?= htmlspecialchars($order['customerInfo']['email']) ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7"/><rect x="2" y="4" width="20" height="16" rx="2"/></svg> Send Details</button>
        <?php if ($canEditOrders): ?><a href="?view=<?= urlencode($order['referenceCode']) ?>&edit=1" class="top-nav-btn"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/></svg> Edit</a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  <!-- Order Status Card -->
  <div class="header status-<?= $currentStatus ?>">
    <div class="header-top-row">
      <div class="submitted-row">
        <span class="submitted-label">Submitted:</span>
        <strong class="submitted-date"><?= date('l, F j, Y \a\t g:i A', strtotime($order['submittedAt'])) ?></strong>
      </div>
      <div class="header-top-divider"></div>
      <div class="priority-tier-row">
        <span class="priority-tier-label">Priority Tier:</span>
        <?php
        // Determine priority class based on tier
        $priorityClass = 'priority-tier-standard';
        $tier = strtolower($order['pricing']['tier']);
        if (strpos($tier, 'last minute') !== false) {
          $priorityClass = 'priority-tier-lastminute';
        } elseif (strpos($tier, 'early') !== false) {
          $priorityClass = 'priority-tier-early';
        } elseif (strpos($tier, 'rush') !== false) {
          $priorityClass = 'priority-tier-rush';
        } elseif (strpos($tier, 'urgent') !== false) {
          $priorityClass = 'priority-tier-urgent';
        } elseif (strpos($tier, 'critical') !== false) {
          $priorityClass = 'priority-tier-critical';
        }
        ?>
        <span class="priority-tier-badge <?= $priorityClass ?>" id="priority_tier_badge"><?= htmlspecialchars($order['pricing']['tier']) ?></span>
      </div>
      <div class="header-top-right">
        <span class="current-status-label">Status:</span>
        <?php
        $statusIcons = [
          'unpaid' => ICON_HOURGLASS,
          'paid' => ICON_MONEY_BAG,
          'file_issue' => ICON_EYE,
          'printing' => ICON_PRINTER,
          'preflight' => ICON_EYE,
          'ready' => ICON_PACKAGE,
          'dispatched' => ICON_TRUCK,
          'shipped' => ICON_TRUCK,
          'delivered' => ICON_PACKAGE,
          'pickedup' => ICON_CHECK_GREEN,
          'unclaimed' => ICON_MAILBOX,
          'missing' => ICON_WARNING,
          'cancelled' => ICON_CROSS,
          'refunded' => ICON_SIREN
        ];
        ?>
        <?php if ($isEditMode): ?>
        <select name="status" class="header-input status-<?= $currentStatus ?>" required>
          <?php
          $statusOptionsWithIcons = [
            'unpaid' => ICON_HOURGLASS . ' Unpaid',
            'paid' => ICON_MONEY_BAG . ' Paid',
            'file_issue' => ICON_EYE . ' File Issue',
            'printing' => ICON_PRINTER . ' Printing',
            'preflight' => ICON_EYE . ' Sent to Vendor',
            'ready' => ICON_PACKAGE . ' Ready to Ship',
            'dispatched' => ICON_TRUCK . ' Courier Assigned',
            'shipped' => ICON_TRUCK . ' Shipped',
            'delivered' => ICON_PACKAGE . ' Delivered',
            'pickedup' => ICON_CHECK_GREEN . ' Picked Up',
            'unclaimed' => ICON_MAILBOX . ' Unclaimed',
            'missing' => ICON_WARNING . ' Missing',
            'cancelled' => ICON_CROSS . ' Cancelled'
          ];
          foreach ( $statusOptionsWithIcons as $key => $label ): ?>
          <option value="<?= $key ?>" <?= $currentStatus === $key ? 'selected' : '' ?>>
          <?= $label ?>
          </option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <span class="status-badge-large status-<?= $currentStatus ?>">
        <?= $statusIcons[$currentStatus] ?? ICON_MEMO ?>
        <?= $statusConfig[$currentStatus]['label'] ?>
        </span>
        <?php endif; ?>
      </div>
    </div>
    <div class="header-main-row">
      <div class="order-number-section">
        <div class="order-section-header">Order Number</div>
        <?php if ($isEditMode): ?>
        <input type="text" name="new_reference_code" value="<?= htmlspecialchars($order['referenceCode']) ?>"
                                   class="header-input" required>
        <?php else: ?>
        <div class="order-number">
          <?= htmlspecialchars($order['referenceCode']) ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="header-divider"></div>
      <div class="due-date-section">
        <div class="order-section-header">Due Date</div>
        <?php if ($isEditMode): ?>
        <div class="edit-date-time-row">
          <div class="edit-date-wrapper" onclick="document.getElementById('due_date_input').focus(); if(document.getElementById('due_date_input').showPicker) document.getElementById('due_date_input').showPicker();">
            <input type="date" id="due_date_input" name="delivery_date" value="<?= htmlspecialchars($order['selectedDate']) ?>" class="edit-date-hidden" required>
            <div id="due_date_display" class="header-input edit-date-display">
              <span class="edit-input-icon"><?= ICON_CALENDAR ?> </span>
              <span id="due_date_text" class="edit-input-text"><?= date('l, F j, Y', strtotime($order['selectedDate'])) ?></span>
              <span class="edit-dropdown-arrow"><?= SYMBOL_CARET_DOWN ?></span>
            </div>
          </div>
          <div class="edit-time-wrapper">
            <span class="edit-time-icon"><?= ICON_CLOCK ?></span>
            <select id="delivery_time_input" name="delivery_time" class="header-input edit-time-select">
              <option value="anytime" <?= (!isset($order['deliveryTime']) || $order['deliveryTime'] === 'anytime') ? 'selected' : '' ?>>Anytime</option>
              <option value="9am" <?= (isset($order['deliveryTime']) && $order['deliveryTime'] === '9am') ? 'selected' : '' ?>>By 9:00am</option>
              <option value="12pm" <?= (isset($order['deliveryTime']) && $order['deliveryTime'] === '12pm') ? 'selected' : '' ?>>By 12:00pm</option>
              <option value="3pm" <?= (isset($order['deliveryTime']) && $order['deliveryTime'] === '3pm') ? 'selected' : '' ?>>By 3:00pm</option>
              <option value="6pm" <?= (isset($order['deliveryTime']) && $order['deliveryTime'] === '6pm') ? 'selected' : '' ?>>By 6:00pm</option>
            </select>
            <span class="edit-select-arrow"><?= SYMBOL_CARET_DOWN ?></span>
          </div>
        </div>
        <?php else: ?>
        <div class="due-date-info">
          <div class="due-date-main">
            <?php
            $deliveryTimeDisplay = '';
            if (isset($order['deliveryTime']) && $order['deliveryTime'] !== 'anytime') {
              $timeLabels = ['9am' => '9:00am', '12pm' => '12:00pm', '3pm' => '3:00pm', '6pm' => '6:00pm'];
              $deliveryTimeDisplay = ' at ' . ($timeLabels[$order['deliveryTime']] ?? $order['deliveryTime']);
            } else {
              $deliveryTimeDisplay = ' at anytime';
            }
            echo date('l, F j, Y', strtotime($order['selectedDate'])) . $deliveryTimeDisplay;
            ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <div class="header-tracking-section">
        <div class="order-section-header">Tracking Code</div>
        <div class="barcode-number" id="orderBarcodeText"><?= $trackingNumber ?></div>
      </div>
      <div id="orderBarcode" class="header-barcode-svg">Generating barcode...</div>
    </div>
    
    <!-- Hidden input for priority tier in edit mode -->
    <?php if ($isEditMode): ?>
    <input type="hidden" id="priority_tier_hidden" name="priority_tier" value="<?= htmlspecialchars($order['pricing']['tier']) ?>">
    <?php endif; ?>
  </div>
  <?php if ($showUpdatedMessage): ?>
  <div class="success-message"> <?= ICON_CHECK_GREEN ?> Order updated successfully! </div>
  <?php endif; ?>
  <?php if ($showErrorMessage): ?>
  <div class="error-message"> <?= ICON_CROSS ?>
    <?= $errorMessage ?>
  </div>
  <?php endif; ?>
  <?php if ($isEditMode): ?>
  <!-- EDIT MODE FORM -->
  <form method="POST" action="admin-orders.php" enctype="multipart/form-data">
    <input type="hidden" name="save_order" value="1">
    <input type="hidden" name="reference_code" value="<?= htmlspecialchars($order['referenceCode']) ?>">
    
    <!-- HIDDEN INPUTS TO CAPTURE HEADER VALUES -->
    <input type="hidden" id="hidden_new_reference_code" name="new_reference_code" value="<?= htmlspecialchars($order['referenceCode']) ?>">
    <input type="hidden" id="hidden_delivery_date" name="delivery_date" value="<?= htmlspecialchars($order['selectedDate']) ?>">
    <input type="hidden" id="hidden_status" name="status" value="<?= $currentStatus ?>">
    <input type="hidden" id="hidden_priority_tier" name="priority_tier" value="<?= htmlspecialchars($order['pricing']['tier']) ?>">
    <div class="info-grid"> 
      <!-- Left Card: Customer & Delivery Information -->
      <div class="card card-compact">
        <div class="section-header"> <span class="card-icon"><?= ICON_USER ?></span> Customer & Delivery Information </div>
        
        <!-- Customer Information -->
        <div class="subsection-header">Customer Information</div>
        <div class="grid-2">
          <div class="field">
            <label for="customer_name">Name</label>
            <input type="text" id="customer_name" name="customer_name" value="<?= htmlspecialchars($order['customerInfo']['name']) ?>" class="field-control" required>
          </div>
          <div class="field">
            <label for="customer_company">Company/Organization</label>
            <input type="text" id="customer_company" name="customer_company" value="<?= htmlspecialchars($order['customerInfo']['company'] ?? '') ?>" class="field-control">
          </div>
        </div>
        <div class="grid-2">
          <div class="field">
            <label for="customer_email">Email</label>
            <input type="email" id="customer_email" name="customer_email" value="<?= htmlspecialchars($order['customerInfo']['email']) ?>" class="field-control" required>
          </div>
          <div class="field">
            <label for="customer_phone">Phone</label>
            <input type="text" id="customer_phone" name="customer_phone" value="<?= htmlspecialchars($order['customerInfo']['phone']) ?>" class="field-control" required>
            <?php if (isset($order['customerInfo']['countryCode']) && !empty($order['customerInfo']['countryCode'])): ?>
            <small style="color: #6b7280;">Current country code:
            <?= htmlspecialchars($order['customerInfo']['countryCode']) ?>
            </small>
            <input type="hidden" name="country_code" value="<?= htmlspecialchars($order['customerInfo']['countryCode']) ?>">
            <?php endif; ?>
          </div>
        </div>
        
        <!-- Delivery Details -->
        <div class="subsection-header" style="margin-top: var(--space-lg);">Delivery Details</div>
        <div class="field">
          <label for="delivery_option">Delivery Method</label>
          <select id="delivery_option" name="delivery_option" class="field-control" required onchange="toggleDeliveryFields(this.value)">
            <option value="mtcc" <?= $order['deliveryOption'] === 'mtcc' ? 'selected' : '' ?>>MTCC Delivery</option>
            <option value="office" <?= $order['deliveryOption'] === 'office' ? 'selected' : '' ?>>Address Delivery</option>
          </select>
        </div>
        <div id="delivery_address_section" style="<?= $order['deliveryOption'] !== 'office' ? 'display: none;' : '' ?>">
          <div style="margin-top: var(--space-md); padding-top: var(--space-md); border-top: 1px solid #f1f5f9;">
            <div class="grid-2">
              <div class="field">
                <label for="delivery_attn">Attention To</label>
                <input type="text" id="delivery_attn" name="delivery_attn" value="<?= htmlspecialchars($order['deliveryAddress']['attn'] ?? '') ?>" class="field-control">
              </div>
              <div class="field">
                <label for="delivery_company">Company</label>
                <input type="text" id="delivery_company" name="delivery_company" value="<?= htmlspecialchars($order['deliveryAddress']['company'] ?? '') ?>" class="field-control">
              </div>
            </div>
            <div class="grid-2">
              <div class="field">
                <label for="delivery_address">Address</label>
                <input type="text" id="delivery_address" name="delivery_address" value="<?= htmlspecialchars($order['deliveryAddress']['address'] ?? '') ?>" class="field-control">
              </div>
              <div class="field">
                <label for="delivery_unit">Unit</label>
                <input type="text" id="delivery_unit" name="delivery_unit" value="<?= htmlspecialchars($order['deliveryAddress']['unit'] ?? '') ?>" class="field-control">
              </div>
            </div>
            <div class="grid-3">
              <div class="field">
                <label for="delivery_city">City</label>
                <input type="text" id="delivery_city" name="delivery_city" value="<?= htmlspecialchars($order['deliveryAddress']['city'] ?? '') ?>" class="field-control">
              </div>
              <div class="field">
                <label for="delivery_province">Province</label>
                <input type="text" id="delivery_province" name="delivery_province" value="<?= htmlspecialchars($order['deliveryAddress']['province'] ?? '') ?>" class="field-control">
              </div>
              <div class="field">
                <label for="delivery_postal">Postal Code</label>
                <input type="text" id="delivery_postal" name="delivery_postal" value="<?= htmlspecialchars($order['deliveryAddress']['postal'] ?? '') ?>" class="field-control">
              </div>
            </div>
          </div>
        </div>
        <div class="field" style="margin-top: var(--space-lg);">
          <label for="additional_notes">Notes</label>
          <textarea id="additional_notes" name="additional_notes" class="field-control" rows="2" placeholder="Any special instructions..."><?= htmlspecialchars($order['customerInfo']['additionalNotes'] ?? '') ?>
</textarea>
        </div>
      </div>
      
      <!-- Right Card: Poster & Pricing -->
      <div class="card card-compact">
        <div class="section-header"> <span class="card-icon"><?= ICON_MEMO ?></span> Poster Details & Pricing </div>
        
        <!-- Poster Specifications -->
        <div class="subsection-header">Poster Specifications</div>
        <div class="grid-3">
          <div class="field">
            <label for="width">Width (in)</label>
            <input type="number" id="width" name="width" value="<?= $order['dimensions']['width'] ?>" class="field-control" required min="12" step="0.1">
          </div>
          <div class="field">
            <label for="height">Height (in)</label>
            <input type="number" id="height" name="height" value="<?= $order['dimensions']['height'] ?>" class="field-control" required min="12" step="0.1">
          </div>
          <div class="field">
            <label for="material">Material</label>
            <select id="material" name="material" class="field-control" required>
              <option value="poster" <?= $order['material'] === 'poster' ? 'selected' : '' ?>>Poster Paper</option>
              <option value="fabric" <?= $order['material'] === 'fabric' ? 'selected' : '' ?>>Fabric</option>
            </select>
          </div>
        </div>
        
        <!-- File Management -->
        <div class="subsection-header" style="margin-top: var(--space-lg);">File Management</div>
        <div class="upload-zone" id="uploadZone">
          <?php if (isset($order['uploadedFile'])): ?>
          <div style="display: flex; align-items: center; gap: 1rem; padding-bottom: 1rem; border-bottom: 1px solid #e5e7eb; margin-bottom: 1rem;"> <span style="color: var(--green); font-size: 1.25rem;"><?= ICON_CHECK_GREEN ?></span>
            <div style="flex: 1;">
              <div style="font-weight: 600; color: var(--text); word-break: break-all; line-height: 1.3;">
                <?= htmlspecialchars(getDisplayFileName($order['referenceCode'] ?? '', $order['uploadedFile']['originalName'])) ?>
                <span style="font-weight: 400; color: var(--subtext); font-size: 0.9rem;"> <?= SYMBOL_BULLET ?>
                <?= formatFileSize($order['uploadedFile']['size']) ?>
                <?= SYMBOL_BULLET ?>
                <?= strtoupper(pathinfo($order['uploadedFile']['originalName'], PATHINFO_EXTENSION)) ?>
                </span> </div>
            </div>
            <button type="button" onclick="removeExistingFile()" class="btn btn-danger">Remove</button>
          </div>
          <div style="font-size: 0.85rem; color: var(--text); font-weight: 500; text-align: center; padding: var(--space-md); background: var(--bg); border-radius: var(--radius); border: 1px solid #e5e7eb; line-height: 1.4;"> <span style="color: var(--primary); font-weight: 600;"><?= ICON_MEMO ?> Current File:</span> Click "Remove" above and drag a new file here to replace the current artwork. </div>
          <?php else: ?>
          <div class="upload-content" id="uploadContent">
            <div class="upload-icon"><?= ICON_FOLDER ?></div>
            <div class="upload-text">
              <p><strong>Click to upload</strong> or drag and drop your design file</p>
              <p class="upload-note">PDF, AI, EPS, PSD, PNG, JPG, TIFF, WebP, GIF, BMP, SVG, PPTX, INDD (Max file size: 100MB)</p>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <input type="file" id="fileInput" name="new_file" accept=".pdf,.ai,.eps,.psd,.png,.jpg,.jpeg,.tiff,.tif,.webp,.gif,.bmp,.svg,.pptx,.indd" style="display: none;">
        <div style="font-size: 0.7rem; color: var(--subtext); margin-top: 0.3rem;"> Supported formats: PDF, AI, EPS, PSD, PNG, JPG, TIFF, WebP, GIF, BMP, SVG, PPTX, InDesign </div>
        
        <!-- Pricing Information -->
<div class="subsection-header" style="margin-top: var(--space-lg);">Pricing Information <span style="font-size: 0.75rem; color: #6b7280; font-weight: normal;">(edit values to recalculate)</span></div>
<div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 1rem;">
  <div class="field">
    <label for="base_price">Base Price</label>
    <div style="display: flex; align-items: center;">
      <span style="background: #e5e7eb; padding: 8px 12px; border-radius: 6px 0 0 6px; border: 1px solid #d1d5db; border-right: none; color: #374151; font-weight: 600;">$</span>
      <input type="number" step="0.01" id="base_price" name="base_price" value="<?= number_format($order['pricing']['basePrice'] ?? 0, 2, '.', '') ?>" class="field-control" required min="0" style="border-radius: 0 6px 6px 0; border-left: none;">
    </div>
  </div>
  <div class="field">
    <label for="delivery_fee">Delivery Fee</label>
    <div style="display: flex; align-items: center;">
      <span style="background: #e5e7eb; padding: 8px 12px; border-radius: 6px 0 0 6px; border: 1px solid #d1d5db; border-right: none; color: #374151; font-weight: 600;">$</span>
      <input type="number" step="0.01" id="delivery_fee" name="delivery_fee" value="<?= number_format($order['pricing']['deliveryFee'] ?? 0, 2, '.', '') ?>" class="field-control" required min="0" style="border-radius: 0 6px 6px 0; border-left: none;">
    </div>
  </div>
  <div class="field">
    <label for="conversion_fee">Conversion Fee</label>
    <div style="display: flex; align-items: center;">
      <span style="background: #e5e7eb; padding: 8px 12px; border-radius: 6px 0 0 6px; border: 1px solid #d1d5db; border-right: none; color: #374151; font-weight: 600;">$</span>
      <input type="number" step="0.01" id="conversion_fee" name="conversion_fee" value="<?= number_format($order['pricing']['conversionFee'] ?? 0, 2, '.', '') ?>" class="field-control" required min="0" style="border-radius: 0 6px 6px 0; border-left: none;">
    </div>
  </div>
  <div class="field">
    <label for="tax">Tax (13%)</label>
    <div style="display: flex; align-items: center;">
      <span style="background: #f3f4f6; padding: 8px 12px; border-radius: 6px 0 0 6px; border: 1px solid #d1d5db; border-right: none; color: #9ca3af; font-weight: 600;">$</span>
      <input type="number" step="0.01" id="tax" name="tax" value="<?= number_format($order['pricing']['tax'] ?? 0, 2, '.', '') ?>" class="field-control" required min="0" style="border-radius: 0 6px 6px 0; border-left: none; background: #f8f9fa; color: #6b7280;" readonly>
    </div>
  </div>
  <div class="field">
    <label for="total" style="color: var(--primary); font-weight: 600;">Total</label>
    <div style="display: flex; align-items: center;">
      <span style="background: var(--primary); padding: 8px 12px; border-radius: 6px 0 0 6px; border: 1px solid var(--primary); border-right: none; color: white; font-weight: 700;">$</span>
      <input type="number" step="0.01" id="total" name="total" value="<?= number_format($order['pricing']['total'] ?? 0, 2, '.', '') ?>" class="field-control" required min="0" style="border-radius: 0 6px 6px 0; border-left: none; font-weight: 700;  background: #ecfdf5; color: var(--primary); border-color: var(--primary);" readonly>
    </div>
  </div>
</div>
      </div>
    </div>
    
    <!-- Centered Action Buttons -->
    <div class="action-buttons-container">
      <button type="submit" class="action-btn-large"><?= ICON_SAVE ?> Save Changes</button>
      <a href="?view=<?= urlencode($order['referenceCode']) ?>" class="action-btn-large cancel"><?= ICON_CROSS ?> Cancel</a> </div>
  </form>
  <?php else: ?>
  <!-- VIEW MODE -->
  <div class="info-grid"> 
    <!-- Left Card: Customer & Delivery Info -->
    <div class="card card-compact">
      <div class="section-header"> <span class="card-icon"><?= ICON_USER ?></span> Customer & Delivery Information </div>
      
      <!-- Customer Details -->
      <div class="subsection-header">Customer Details</div>
      <div class="grid-2">
        <div class="detail-item">
          <div class="detail-label">Name</div>
          <div class="detail-value">
            <?= htmlspecialchars($order['customerInfo']['name']) ?>
          </div>
        </div>
        <?php if (isset($order['customerInfo']['company']) && !empty($order['customerInfo']['company'])): ?>
        <div class="detail-item">
          <div class="detail-label">Company/Organization</div>
          <div class="detail-value">
            <?= htmlspecialchars($order['customerInfo']['company']) ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <div class="grid-2">
        <div class="detail-item">
          <div class="detail-label">Email</div>
          <div class="detail-value">
            <?= htmlspecialchars($order['customerInfo']['email']) ?>
          </div>
        </div>
        <div class="detail-item">
          <div class="detail-label">Phone</div>
          <div class="detail-value">
            <?php
            $phoneDisplay = htmlspecialchars( $order[ 'customerInfo' ][ 'phone' ] );
            if ( isset( $order[ 'customerInfo' ][ 'countryCode' ] ) &&
              !empty( $order[ 'customerInfo' ][ 'countryCode' ] ) &&
              $order[ 'customerInfo' ][ 'countryCode' ] !== '+1' ) {
              $phoneDisplay .= ' (' . htmlspecialchars( $order[ 'customerInfo' ][ 'countryCode' ] ) . ')';
            }
            echo $phoneDisplay;
            ?>
          </div>
        </div>
      </div>
      <?php if (!empty($order['customerInfo']['additionalNotes'])): ?>
      <div class="detail-item">
        <div class="detail-label">Notes</div>
        <div class="detail-value" style="background: #f8fafc; padding: var(--space-sm); border-radius: var(--radius); font-style: italic;">
          <?= nl2br(htmlspecialchars($order['customerInfo']['additionalNotes'])) ?>
        </div>
      </div>
      <?php endif; ?>
      
      <!-- Delivery Information -->
      <div class="subsection-header" style="margin-top: var(--space-lg);">Delivery Information</div>
      <div class="detail-item">
        <div class="detail-label">Method</div>
        <div class="detail-value">
          <?php if ($order['deliveryOption'] === 'mtcc'): ?>
          <?= ICON_FLAG ?> MTCC Delivery (Free)
          <?php else: ?>
          <?= ICON_TRUCK ?> Address Delivery (+$10.00)
          <?php endif; ?>
        </div>
      </div>
      <div class="detail-item">
        <div class="detail-label">Address</div>
        <div class="detail-value">
          <?php if ($order['deliveryOption'] === 'office' && isset($order['deliveryAddress'])): ?>
          <div class="address-display">
            <?php if (!empty($order['deliveryAddress']['company'])): ?>
            <div class="address-line"><strong>
              <?= htmlspecialchars($order['deliveryAddress']['company']) ?>
              </strong></div>
            <?php endif; ?>
            <div class="address-line">Attn:
              <?= htmlspecialchars($order['deliveryAddress']['attn']) ?>
            </div>
            <div class="address-line">
              <?= htmlspecialchars($order['deliveryAddress']['address']) ?>
              <?= !empty($order['deliveryAddress']['unit']) ? ' ' . htmlspecialchars($order['deliveryAddress']['unit']) : '' ?>
            </div>
            <div class="address-line">
              <?= htmlspecialchars($order['deliveryAddress']['city']) ?>
              ,
              <?= htmlspecialchars($order['deliveryAddress']['province']) ?>
              <?= htmlspecialchars($order['deliveryAddress']['postal']) ?>
              <?php if (isset($order['deliveryAddress']['instructions']) && !empty($order['deliveryAddress']['instructions'])): ?>
              <div class="address-line" style="margin-top: 8px; font-style: italic; color: #6b7280;"> <strong>Instructions:</strong>
                <?= htmlspecialchars($order['deliveryAddress']['instructions']) ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php else: ?>
          <div class="address-display">
            <div class="address-line"><strong>Metro Toronto Convention Centre</strong></div>
            <div class="address-line">Exhibitor Services Office</div>
            <div class="address-line">North Building, Level 300</div>
            <div class="address-line">255 Front Street West,</div>
            <div class="address-line">Toronto, ON M5V 2W6</div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    
    <!-- Right Card: Poster, File & Pricing -->
    <div class="card card-compact">
      <div class="section-header"> <span class="card-icon"><?= ICON_MEMO ?></span> Poster Details & Pricing </div>
      
      <!-- Poster Specifications -->
      <div class="subsection-header">Poster Specifications</div>
      <div class="poster-specs-grid">
        <div class="spec-item">
          <div class="spec-label">Dimensions</div>
          <div class="spec-value">
            <?= $order['dimensions']['width'] ?>
            "  x 
            <?= $order['dimensions']['height'] ?>
            "</div>
        </div>
        <div class="spec-item">
          <div class="spec-label">Material</div>
          <div class="spec-value">
            <?= ucfirst($order['material']) ?>
          </div>
        </div>
      </div>
      
      <!-- Uploaded File -->
      <?php if (isset($order['uploadedFile'])): ?>
<div class="subsection-header" style="margin-top: var(--space-lg);">Uploaded Artwork</div>
<div style="display: flex; align-items: center; justify-content: space-between; padding: 15px; background: #f8fafc; border-radius: 8px; border: 1px solid #e5e7eb;">
  <div>
    <div style="font-weight: 600; color: #374151; margin-bottom: 4px;">
      <?= htmlspecialchars(getDisplayFileName($order['referenceCode'] ?? '', $order['uploadedFile']['originalName'])) ?>
    </div>
    <div style="font-size: 0.9rem; color: #6b7280;">
      <?php
      $fileExt = strtolower(pathinfo($order['uploadedFile']['originalName'], PATHINFO_EXTENSION));
      echo formatFileSize($order['uploadedFile']['size']) . ' <?= SYMBOL_BULLET ?> ' . strtoupper($fileExt);
      ?>
    </div>
  </div>
  <a href="?download=<?= urlencode($order['referenceCode']) ?>" class="btn btn-light"><?= ICON_DOWNLOAD ?> Download</a>
</div>
<?php endif; ?>
      
      <!-- Pricing Breakdown -->
      <div class="subsection-header" style="margin-top: var(--space-lg);">Pricing Breakdown</div>
      <div class="pricing-condensed">
        <div class="pricing-items">
          <div class="pricing-item">
            <div class="pricing-label">Base</div>
            <div class="pricing-value">$<?= number_format($order['pricing']['basePrice'], 2) ?></div>
          </div>
          <div class="pricing-item">
            <div class="pricing-label">Delivery</div>
            <div class="pricing-value">$<?= number_format($order['pricing']['deliveryFee'], 2) ?></div>
          </div>
          <?php if (isset($order['pricing']['conversionFee']) && $order['pricing']['conversionFee'] > 0): ?>
          <div class="pricing-item">
            <div class="pricing-label">File Fee</div>
            <div class="pricing-value">$<?= number_format($order['pricing']['conversionFee'], 2) ?></div>
          </div>
          <?php endif; ?>
          <div class="pricing-item">
            <div class="pricing-label">Tax (13%)</div>
            <div class="pricing-value">$<?= number_format($order['pricing']['tax'], 2) ?></div>
          </div>
          <div class="pricing-item pricing-item-total">
            <div class="pricing-label">Total</div>
            <div class="pricing-value">$<?= number_format($order['pricing']['total'], 2) ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <!-- PAYMENT LINK CARD - Show for unpaid orders -->
  <?php 
  $unpaidStatuses = ['unpaid', 'file_issue'];
  $hasValidTotal = isset($order['pricing']['total']) && $order['pricing']['total'] > 0;
  $customerEmail = $order['customerInfo']['email'] ?? $order['email'] ?? '';
  $hasActivePaymentLink = isset($order['paymentLink']['paymentLinkId']) && 
                          (!isset($order['paymentLink']['active']) || $order['paymentLink']['active'] !== false);
  
  if (in_array($currentStatus, $unpaidStatuses) && $hasValidTotal): 
  ?>
  <div class="card card-compact" style="background: linear-gradient(135deg, #faf5ff 0%, #f5f3ff 100%); border: 2px solid #7c3aed;">
    <div class="section-header" style="color: #7c3aed;">
      <span class="card-icon">&#128179;</span> Payment Options
    </div>
    <div style="padding: 0 var(--space-md) var(--space-md);">
      <p style="color: #6b7280; font-size: 0.9rem; margin-bottom: 15px;">
        This order is awaiting payment. Send a Stripe payment link or mark as paid manually:
      </p>
      
      <!-- Stripe Payment Link Options -->
      <div style="margin-bottom: 16px;">
        <div style="font-size: 0.75rem; font-weight: 600; color: #7c3aed; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Stripe Payment</div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
          <button class="btn" style="background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); color: white; border: none;" onclick="sendPaymentLink('<?= htmlspecialchars($order['referenceCode']) ?>', true)">
            &#128231; Send Payment Link
          </button>
          <button class="btn btn-light" style="border: 2px solid #7c3aed; color: #7c3aed;" onclick="sendPaymentLink('<?= htmlspecialchars($order['referenceCode']) ?>', false)" title="Generate link without emailing customer">
            &#128279; Copy Link Only
          </button>
          <?php if ($hasActivePaymentLink): ?>
          <button class="btn btn-light" style="border: 2px solid #f59e0b; color: #f59e0b;" onclick="showDeactivateLinkModal('<?= htmlspecialchars($order['referenceCode']) ?>')" title="Deactivate existing payment link">
            &#10006; Deactivate Link
          </button>
          <?php endif; ?>
        </div>
      </div>
      
      <!-- Manual Payment Options -->
      <div style="border-top: 1px solid #e5e7eb; padding-top: 16px;">
        <div style="font-size: 0.75rem; font-weight: 600; color: #059669; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Manual Payment</div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
          <button class="btn" style="background: #059669; color: white; border: none;" onclick="showMarkPaidModal('<?= htmlspecialchars($order['referenceCode']) ?>', <?= $order['pricing']['total'] ?>)">
            &#128176; Mark as Paid
          </button>
        </div>
        <div style="font-size: 0.75rem; color: #6b7280; margin-top: 8px;">
          Use this for cash, e-transfer, or other non-Stripe payments
        </div>
      </div>
      
      <div style="font-size: 0.8rem; color: #6b7280; padding: 10px; background: white; border-radius: 6px; border: 1px solid #e5e7eb; margin-top: 16px;">
        <strong>Amount:</strong> $<?= number_format($order['pricing']['total'], 2) ?> CAD &bull; 
        <strong>Email:</strong> <?= htmlspecialchars($customerEmail) ?>
      </div>
      
      <?php if (isset($order['paymentLink'])): ?>
      <div style="margin-top: 12px; padding: 10px; background: <?= $hasActivePaymentLink ? '#fefce8' : '#f3f4f6' ?>; border-radius: 6px; font-size: 0.8rem; color: <?= $hasActivePaymentLink ? '#92400e' : '#6b7280' ?>; border: 1px solid <?= $hasActivePaymentLink ? '#fcd34d' : '#e5e7eb' ?>;">
        <?php if ($hasActivePaymentLink): ?>
        &#9888; Active payment link generated: <?= htmlspecialchars($order['paymentLink']['createdAt'] ?? 'unknown') ?>
        <?php if (isset($order['paymentLink']['expiresAt'])): ?>
        <br>Expires: <?= htmlspecialchars($order['paymentLink']['expiresAt']) ?>
        <?php endif; ?>
        <?php else: ?>
        &#10004; Payment link deactivated: <?= htmlspecialchars($order['paymentLink']['deactivatedAt'] ?? 'unknown') ?>
        <?php if (isset($order['paymentLink']['deactivationReason'])): ?>
        <br>Reason: <?= htmlspecialchars($order['paymentLink']['deactivationReason']) ?>
        <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php elseif ($currentStatus === 'paid' || in_array($currentStatus, ['printing', 'shipped', 'delivered', 'pickedup'])): ?>
  <!-- Payment Confirmed + Refund Options -->
  <div class="card card-compact" style="background: #ecfdf5; border: 2px solid #10b981;">
    <div style="padding: var(--space-md); text-align: center;">
      <span style="font-size: 2rem;">&#10004;</span>
      <div style="color: #059669; font-weight: 600; font-size: 1.1rem; margin-top: 5px;">Payment Received</div>
      <?php if (isset($order['paidAt'])): ?>
      <div style="font-size: 0.8rem; color: #6b7280; margin-top: 5px;">
        Paid on <?= htmlspecialchars($order['paidAt']) ?>
        <?php if (isset($order['paymentMethod'])): ?>
        via <strong><?= ucfirst(htmlspecialchars($order['paymentMethod'])) ?></strong>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if (isset($order['stripePaymentIntent'])): ?>
      <div style="font-size: 0.75rem; color: #9ca3af; margin-top: 3px;">
        Stripe: <?= htmlspecialchars(substr($order['stripePaymentIntent'], 0, 20)) ?>...
      </div>
      <?php endif; ?>
      <?php if (isset($order['paidBy'])): ?>
      <div style="font-size: 0.75rem; color: #9ca3af; margin-top: 3px;">
        Marked by: <?= htmlspecialchars($order['paidBy']) ?>
      </div>
      <?php endif; ?>
    </div>
    
    <!-- Refund Button -->
    <div style="padding: 0 var(--space-md) var(--space-md); border-top: 1px solid #d1fae5;">
      <div style="display: flex; justify-content: center; padding-top: var(--space-md);">
        <button class="btn" style="background: #dc2626; color: white; border: none; font-size: 0.85rem;" onclick="showRefundModal('<?= htmlspecialchars($order['referenceCode']) ?>', <?= $order['pricing']['total'] ?>, '<?= isset($order['stripePaymentIntent']) ? 'stripe' : 'manual' ?>')">
          &#8634; Process Refund
        </button>
      </div>
    </div>
  </div>
  <?php elseif ($currentStatus === 'refunded'): ?>
  <!-- Refunded Badge -->
  <div class="card card-compact" style="background: #fef2f2; border: 2px solid #dc2626;">
    <div style="padding: var(--space-md); text-align: center;">
      <span style="font-size: 2rem;">&#8634;</span>
      <div style="color: #dc2626; font-weight: 600; font-size: 1.1rem; margin-top: 5px;">Order Refunded</div>
      <?php if (isset($order['refund'])): ?>
      <div style="font-size: 0.85rem; color: #6b7280; margin-top: 8px;">
        <strong>Amount:</strong> $<?= number_format($order['refund']['refundAmount'] ?? 0, 2) ?> 
        (<?= ucfirst($order['refund']['refundType'] ?? 'full') ?> refund)
      </div>
      <div style="font-size: 0.8rem; color: #6b7280; margin-top: 4px;">
        <strong>Reason:</strong> <?= htmlspecialchars($order['refund']['refundReasonLabel'] ?? $order['refund']['refundReason'] ?? 'N/A') ?>
      </div>
      <div style="font-size: 0.75rem; color: #9ca3af; margin-top: 4px;">
        Refunded on <?= htmlspecialchars($order['refund']['refundedAt'] ?? 'N/A') ?>
        by <?= htmlspecialchars($order['refund']['refundedBy'] ?? 'Admin') ?>
      </div>
      <?php if (!empty($order['refund']['stripeRefundId'])): ?>
      <div style="font-size: 0.75rem; color: #9ca3af; margin-top: 3px;">
        Stripe Refund: <?= htmlspecialchars($order['refund']['stripeRefundId']) ?>
      </div>
      <?php endif; ?>
      <?php if (!empty($order['refund']['notes'])): ?>
      <div style="font-size: 0.8rem; color: #6b7280; margin-top: 8px; padding: 8px; background: white; border-radius: 6px; text-align: left;">
        <strong>Notes:</strong> <?= htmlspecialchars($order['refund']['notes']) ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  
  <!-- BARCODE CARD removed — barcode now lives in header card -->


  <!-- Enhanced Internal Notes Card with Collaborative System -->
  <div class="card card-compact" id="internalNotesCard">
    <div class="section-header section-header-with-button">
      <div class="header-left"> <span class="card-icon"><?= ICON_MEMO ?></span> Internal Notes & Communication </div>
      <div class="header-right">
        <div class="header-divider-vertical"></div>
        <button class="add-note-btn" id="addNoteBtn"> <?= ICON_PLUS ?> Add Note </button>
      </div>
    </div>
    
    <!-- Add Note Form (Hidden by default) -->
    <div class="add-note-form" id="addNoteForm">
      <div class="form-row">
        <div class="field">
          <label for="noteUsername">Your Name</label>
          <input type="text" id="noteUsername" class="field-control" placeholder="e.g. John Admin" required>
        </div>
        <div class="field">
          <label for="noteContent">Note Content</label>
          <textarea id="noteContent" class="field-control" rows="3" placeholder="Enter your note here..." required></textarea>
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn-small btn-light" id="saveNoteBtn"><?= ICON_SAVE ?> Save Note</button>
        <button type="button" class="btn-small btn-none" id="cancelNoteBtn"><?= ICON_CROSS ?> Cancel</button>
      </div>
      <div id="noteFormMessage"></div>
    </div>
    
    <!-- Notes Timeline -->
    <div class="notes-timeline" id="notesTimeline">
      <?php
      $hasNotes = isset( $order[ 'internalNotes' ] ) && !empty( $order[ 'internalNotes' ] );

      if ( $hasNotes ):
        // Sort notes by timestamp (newest first)
        $notes = $order[ 'internalNotes' ];
      usort( $notes, function ( $a, $b ) {
        return strtotime( $b[ 'timestamp' ] ) - strtotime( $a[ 'timestamp' ] );
      } );

      foreach ( $notes as $note ): ?>
      <div class="note-item" data-note-id="<?= htmlspecialchars($note['id']) ?>">
        <div class="note-content-wrapper">
          <div class="note-main-line"> <span class="note-author">
            <?= htmlspecialchars($note['username']) ?>
            </span>
            <div class="note-divider"></div>
            <div class="note-content">
              <?= nl2br(htmlspecialchars($note['content'])) ?>
            </div>
            <div class="note-timestamp">
              <?= date('M j, Y \a\t g:i A', strtotime($note['timestamp'])) ?>
              <?php if (isset($note['editedAt'])): ?>
              <em>(edited
              <?= date('M j, Y \a\t g:i A', strtotime($note['editedAt'])) ?>
              )</em>
              <?php endif; ?>
            </div>
          </div>
          
          <!-- Edit Note Form (Hidden by default) -->
          <div class="edit-note-form" id="editForm_<?= htmlspecialchars($note['id']) ?>">
            <div class="form-row-edit">
              <div class="field">
                <label for="editUsername_<?= htmlspecialchars($note['id']) ?>">Name</label>
                <input type="text" id="editUsername_<?= htmlspecialchars($note['id']) ?>" class="field-control" value="<?= htmlspecialchars($note['username']) ?>" required>
              </div>
              <div class="field">
                <label for="editContent_<?= htmlspecialchars($note['id']) ?>">Note</label>
                <textarea id="editContent_<?= htmlspecialchars($note['id']) ?>" class="field-control" rows="2" required><?= htmlspecialchars($note['content']) ?>
</textarea>
              </div>
            </div>
            <div class="form-actions">
              <button type="button" class="btn-small btn-medium" onclick="saveEditedNote('<?= htmlspecialchars($note['id']) ?>')"><?= ICON_SAVE ?> Save</button>
              <button type="button" class="btn-small btn-none" onclick="cancelEditNote('<?= htmlspecialchars($note['id']) ?>')"><?= ICON_CROSS ?> Cancel</button>
            </div>
          </div>
        </div>
        <div class="note-actions">
          <button class="note-action-btn edit-btn" onclick="editNote('<?= htmlspecialchars($note['id']) ?>')" title="Edit note"> <?= ICON_PENCIL ?> </button>
          <button class="note-action-btn remove-btn" onclick="removeNote('<?= htmlspecialchars($note['id']) ?>')" title="Remove note"> <?= ICON_CROSS ?> </button>
        </div>
      </div>
      <?php
      endforeach;
      else :?>
      <div class="no-notes" id="noNotesMessage"> <?= ICON_MEMO ?> No internal notes yet. Click "Add Note +" above to start the conversation. </div>
      <?php endif; ?>
    </div>
  </div>
  
  <!-- Order History Timeline Card -->
  <div class="card card-compact order-history-card">
    <div class="section-header">
      <div class="header-left"> <span class="card-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg></span> Order History </div>
    </div>
    <div class="order-history-timeline" id="orderHistoryTimeline">
      <?php
      $orderHistory = getOrderHistory($order['referenceCode']);
      
      // Add order creation as first entry if no history exists or always show it
      $creationEntry = [
          'id' => 'created',
          'timestamp' => $order['submittedAt'],
          'action' => 'order_created',
          'details' => 'Order was submitted by ' . htmlspecialchars($order['customerInfo']['name']),
          'user' => 'Customer'
      ];
      
      // Check if creation entry already exists
      $hasCreation = false;
      foreach ($orderHistory as $entry) {
          if ($entry['action'] === 'order_created') {
              $hasCreation = true;
              break;
          }
      }
      
      if (!$hasCreation) {
          $orderHistory[] = $creationEntry;
      }
      
      // Sort by timestamp descending
      usort($orderHistory, function($a, $b) {
          return strtotime($b['timestamp']) - strtotime($a['timestamp']);
      });
      
      // Action icons — Lucide SVGs inlined for consistency
      $lucidePackage = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><polyline points="3.29 7 12 12 20.71 7"/><path d="m7.5 4.27 9 5.15"/></svg>';
      $lucideStatus = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/></svg>';
      $lucidePencil = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/></svg>';
      $lucideNote = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 9a2.4 2.4 0 0 0-.706-1.706l-3.588-3.588A2.4 2.4 0 0 0 15 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2z"/><path d="M15 3v5a1 1 0 0 0 1 1h5"/></svg>';
      $lucideMail = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7"/><rect x="2" y="4" width="20" height="16" rx="2"/></svg>';
      $lucideFolder = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 14 1.5-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.54 6a2 2 0 0 1-1.95 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H18a2 2 0 0 1 2 2v2"/></svg>';
      $lucideFile = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>';

      $actionConfig = [
          'order_created' => ['icon' => $lucidePackage, 'label' => 'Order Created', 'color' => '#10b981'],
          'status_change' => ['icon' => $lucideStatus, 'label' => 'Status Changed', 'color' => '#6366f1'],
          'edit' => ['icon' => $lucidePencil, 'label' => 'Order Edited', 'color' => '#f59e0b'],
          'note_added' => ['icon' => $lucideNote, 'label' => 'Note Added', 'color' => '#3b82f6'],
          'note_edited' => ['icon' => $lucidePencil, 'label' => 'Note Edited', 'color' => '#8b5cf6'],
          'note_removed' => ['icon' => $lucidePencil, 'label' => 'Note Removed', 'color' => '#ef4444'],
          'email_sent' => ['icon' => $lucideMail, 'label' => 'Email Sent', 'color' => '#06b6d4'],
          'file_uploaded' => ['icon' => $lucideFolder, 'label' => 'File Uploaded', 'color' => '#84cc16']
      ];

      if (!empty($orderHistory)):
      foreach ($orderHistory as $entry):
          $config = $actionConfig[$entry['action']] ?? ['icon' => $lucideNote, 'label' => ucfirst(str_replace('_', ' ', $entry['action'])), 'color' => '#6b7280'];
      ?>
      <div class="history-item">
        <div class="history-icon" style="background-color: <?= $config['color'] ?>20; color: <?= $config['color'] ?>;">
          <?= $config['icon'] ?>
        </div>
        <div class="history-content">
          <div class="history-action"><?= $config['label'] ?></div>
          <div class="history-details"><?= htmlspecialchars($entry['details']) ?></div>
          <div class="history-meta">
            <span class="history-user"><?= htmlspecialchars($entry['user']) ?></span>
            <span class="history-time"><?= date('M j, Y \a\t g:i A', strtotime($entry['timestamp'])) ?></span>
          </div>
        </div>
      </div>
      <?php 
      endforeach;
      else:
      ?>
      <div class="no-history"><?= $lucideFile ?> No history recorded yet.</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Include external JavaScript file --> 
<script src="js/order-detail.js"></script> 

<!-- Minimal inline initialization --> 
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (document.querySelector('.order-number') || document.querySelector('.due-date-section')) {
        var orderReference = '<?php echo htmlspecialchars($order["referenceCode"]); ?>';
        var trackingNumber = '<?php echo $trackingNumber; ?>';
        
        initializeOrderDetail(orderReference, trackingNumber);
        
        if (typeof initializeCountdown === 'function') {
            initializeCountdown();
        }
        
        if (typeof syncHeaderInputs === 'function') {
            syncHeaderInputs();
        }
        
        if (typeof updatePriorityTier === 'function') {
            updatePriorityTier();
        }
        
        if (typeof updateStatusColor === 'function') {
            updateStatusColor();
        }
        
        if (typeof setInitialStatusBorder === 'function') {
            setInitialStatusBorder();
        }
    }
});
</script>

<!-- Dynamic Pricing Calculator for Edit Mode -->
<script>
// Auto-calculate tax and total when pricing fields change
function initializePricingCalculator() {
    const basePrice = document.getElementById('base_price');
    const deliveryFee = document.getElementById('delivery_fee');
    const conversionFee = document.getElementById('conversion_fee');
    const taxField = document.getElementById('tax');
    const totalField = document.getElementById('total');
    
    // Only run if all fields exist (edit mode)
    if (!basePrice || !deliveryFee || !conversionFee || !taxField || !totalField) {
        return;
    }
    
    function recalculatePricing() {
        const base = parseFloat(basePrice.value) || 0;
        const delivery = parseFloat(deliveryFee.value) || 0;
        const conversion = parseFloat(conversionFee.value) || 0;
        
        const subtotal = base + delivery + conversion;
        const tax = subtotal * 0.13; // 13% HST
        const total = subtotal + tax;
        
        taxField.value = tax.toFixed(2);
        totalField.value = total.toFixed(2);
        
        // Flash the total field to show it updated
        totalField.style.transition = 'background-color 0.3s';
        totalField.style.backgroundColor = '#bbf7d0';
        setTimeout(() => {
            totalField.style.backgroundColor = '';
        }, 300);
    }
    
    // Listen for changes on editable fields
    basePrice.addEventListener('input', recalculatePricing);
    deliveryFee.addEventListener('input', recalculatePricing);
    conversionFee.addEventListener('input', recalculatePricing);
    
    // Also recalculate on blur to ensure proper formatting
    [basePrice, deliveryFee, conversionFee].forEach(field => {
        field.addEventListener('blur', function() {
            if (this.value === '' || isNaN(parseFloat(this.value))) {
                this.value = '0.00';
            } else {
                this.value = parseFloat(this.value).toFixed(2);
            }
            recalculatePricing();
        });
    });
    
    console.log('Pricing calculator initialized');
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', initializePricingCalculator);
</script>

<!-- Payment Link Functions for Order Detail View -->
<script>
// Send Payment Link to Customer
async function sendPaymentLink(referenceCode, sendEmail = true) {
    if (!referenceCode) {
        if (typeof showNotification === 'function') {
            showNotification('No order selected', 'error');
        } else {
            alert('No order selected');
        }
        return;
    }
    
    // Confirm action
    const action = sendEmail ? 'send a payment link email to the customer' : 'generate a payment link';
    if (!confirm(`Are you sure you want to ${action} for order ${referenceCode}?`)) {
        return;
    }
    
    // Show loading state
    const btns = document.querySelectorAll(`[onclick*="sendPaymentLink"]`);
    btns.forEach(btn => {
        btn.disabled = true;
        btn.style.opacity = '0.6';
    });
    
    try {
        const response = await fetch('send-payment-link.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                referenceCode: referenceCode,
                sendEmail: sendEmail
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Show the payment link modal
            showPaymentLinkModal(referenceCode, result.paymentUrl, result.emailSent, result.expiresAt);
        } else {
            alert('Error: ' + (result.error || 'Failed to create payment link'));
        }
        
    } catch (error) {
        console.error('Payment link error:', error);
        alert('Error creating payment link: ' + error.message);
    } finally {
        // Restore button state
        btns.forEach(btn => {
            btn.disabled = false;
            btn.style.opacity = '1';
        });
    }
}

// Show Payment Link Modal
function showPaymentLinkModal(referenceCode, paymentUrl, emailSent, expiresAt) {
    // Remove existing modal
    const existing = document.getElementById('paymentLinkModal');
    if (existing) existing.remove();
    
    const emailStatus = emailSent 
        ? '<div style="background: #ecfdf5; color: #059669; padding: 10px; border-radius: 6px; margin-bottom: 15px;"><?= ICON_CHECK_MARK ?> Payment link emailed to customer</div>'
        : '<div style="background: #fef3c7; color: #92400e; padding: 10px; border-radius: 6px; margin-bottom: 15px;"><?= ICON_WARNING ?> Email not sent - share link manually</div>';
    
    const modal = document.createElement('div');
    modal.id = 'paymentLinkModal';
    modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;';
    modal.innerHTML = `
        <div style="background:white;border-radius:12px;padding:30px;max-width:500px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                <h3 style="margin:0;color:#7c3aed;font-size:1.3rem;"><?= ICON_LINK ?> Payment Link Created</h3>
                <button onclick="document.getElementById('paymentLinkModal').remove()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#9ca3af;"> x </button>
            </div>
            ${emailStatus}
            <div style="margin-bottom:15px;">
                <label style="display:block;font-size:0.85rem;color:#6b7280;margin-bottom:6px;">Payment Link:</label>
                <div style="display:flex;gap:8px;">
                    <input type="text" id="paymentLinkUrl" value="${paymentUrl}" readonly style="flex:1;padding:10px;border:1px solid #e5e7eb;border-radius:6px;font-size:0.85rem;background:#f9fafb;">
                    <button onclick="copyPaymentLink()" style="padding:10px 16px;background:#7c3aed;color:white;border:none;border-radius:6px;cursor:pointer;font-weight:600;"><?= ICON_MEMO ?><?= ICON_COPY ?> Copy</button>
                </div>
            </div>
            <div style="font-size:0.8rem;color:#9ca3af;margin-bottom:20px;"><?= ICON_HOURGLASS ?> Link expires: ${expiresAt || '24 hours'}</div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button onclick="window.open('${paymentUrl}','_blank')" style="padding:10px 20px;background:#f3f4f6;color:#374151;border:none;border-radius:6px;cursor:pointer;"><?= ICON_LINK ?> Open Link</button>
                <button onclick="document.getElementById('paymentLinkModal').remove()" style="padding:10px 20px;background:#10b981;color:white;border:none;border-radius:6px;cursor:pointer;font-weight:600;">Done</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.remove(); });
}

function copyPaymentLink() {
    const input = document.getElementById('paymentLinkUrl');
    if (input) {
        input.select();
        document.execCommand('copy');
        alert('Payment link copied to clipboard!');
    }
}
</script>

<!-- ==================== PAYMENT ACTIONS MODALS ==================== -->

<!-- Mark as Paid Modal -->
<div id="markPaidModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
  <div style="background: white; border-radius: 12px; padding: 0; max-width: 450px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
    <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
      <h3 style="margin: 0; color: #059669; font-size: 1.2rem;">&#128176; Mark Order as Paid</h3>
      <button onclick="closeMarkPaidModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #9ca3af; line-height: 1;">&times;</button>
    </div>
    <div style="padding: 20px;">
      <div style="margin-bottom: 16px;">
        <div style="background: #ecfdf5; padding: 12px; border-radius: 8px; margin-bottom: 16px;">
          <div style="font-size: 0.85rem; color: #6b7280;">Order Reference</div>
          <div style="font-size: 1.1rem; font-weight: 600; color: #059669;" id="markPaidRefCode">-</div>
          <div style="font-size: 1rem; color: #374151; margin-top: 4px;">Amount: <strong id="markPaidAmount">$0.00</strong></div>
        </div>
      </div>
      
      <div style="margin-bottom: 16px;">
        <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #374151;">Payment Method *</label>
        <select id="markPaidMethod" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.95rem;">
          <option value="">Select payment method...</option>
          <option value="cash"><?= ICON_DOLLAR ?> Cash</option>
          <option value="etransfer"><?= ICON_GEAR ?> E-Transfer</option>
          <option value="other"><?= ICON_MEMO ?> Other</option>
        </select>
      </div>
      
      <div style="margin-bottom: 16px;">
        <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #374151;">Notes (Optional)</label>
        <textarea id="markPaidNotes" rows="2" placeholder="e.g. Cash received at booth" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.95rem; resize: vertical; box-sizing: border-box;"></textarea>
      </div>
      
      <div style="background: #fefce8; padding: 10px; border-radius: 6px; font-size: 0.8rem; color: #92400e; margin-bottom: 16px;">
        <?= ICON_WARNING ?> This will mark the order as paid and deactivate any existing payment links.
      </div>
    </div>
    <div style="padding: 16px 20px; background: #f9fafb; border-top: 1px solid #e5e7eb; display: flex; gap: 10px; justify-content: flex-end; border-radius: 0 0 12px 12px;">
      <button onclick="closeMarkPaidModal()" style="padding: 10px 20px; background: #f3f4f6; color: #374151; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">Cancel</button>
      <button onclick="submitMarkPaid()" id="markPaidSubmitBtn" style="padding: 10px 20px; background: #059669; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">&#10004; Confirm Payment</button>
    </div>
  </div>
</div>

<!-- Process Refund Modal -->
<div id="refundModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
  <div style="background: white; border-radius: 12px; padding: 0; max-width: 500px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.3); max-height: 90vh; overflow-y: auto;">
    <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: white; border-radius: 12px 12px 0 0;">
      <h3 style="margin: 0; color: #dc2626; font-size: 1.2rem;">&#8634; Process Refund</h3>
      <button onclick="closeRefundModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #9ca3af; line-height: 1;">&times;</button>
    </div>
    <div style="padding: 20px;">
      <div style="margin-bottom: 16px;">
        <div style="background: #fef2f2; padding: 12px; border-radius: 8px; margin-bottom: 16px;">
          <div style="font-size: 0.85rem; color: #6b7280;">Order Reference</div>
          <div style="font-size: 1.1rem; font-weight: 600; color: #dc2626;" id="refundRefCode">-</div>
          <div style="font-size: 1rem; color: #374151; margin-top: 4px;">Order Total: <strong id="refundOrderTotal">$0.00</strong></div>
          <div style="font-size: 0.8rem; color: #6b7280; margin-top: 4px;" id="refundPaymentType">Payment via Stripe</div>
        </div>
      </div>
      
      <div style="margin-bottom: 16px;">
        <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #374151;">Refund Type *</label>
        <div style="display: flex; gap: 10px;">
          <label style="flex: 1; padding: 12px; border: 2px solid #d1d5db; border-radius: 8px; cursor: pointer; text-align: center; transition: all 0.2s;" id="refundTypeFull">
            <input type="radio" name="refundType" value="full" checked style="display: none;">
            <div style="font-weight: 600; color: #374151;">Full Refund</div>
            <div style="font-size: 0.8rem; color: #6b7280;" id="refundFullAmount">$0.00</div>
          </label>
          <label style="flex: 1; padding: 12px; border: 2px solid #d1d5db; border-radius: 8px; cursor: pointer; text-align: center; transition: all 0.2s;" id="refundTypePartial">
            <input type="radio" name="refundType" value="partial" style="display: none;">
            <div style="font-weight: 600; color: #374151;">Partial Refund</div>
            <div style="font-size: 0.8rem; color: #6b7280;">Custom amount</div>
          </label>
        </div>
      </div>
      
      <div style="margin-bottom: 16px; display: none;" id="partialAmountGroup">
        <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #374151;">Refund Amount *</label>
        <div style="position: relative;">
          <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #6b7280; font-weight: 600;">$</span>
          <input type="number" id="refundAmount" step="0.01" min="0.01" placeholder="0.00" style="width: 100%; padding: 10px 10px 10px 28px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.95rem; box-sizing: border-box;">
        </div>
        <div style="font-size: 0.75rem; color: #6b7280; margin-top: 4px;">Maximum: <span id="refundMaxAmount">$0.00</span></div>
      </div>
      
      <div style="margin-bottom: 16px;">
        <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #374151;">Refund Reason *</label>
        <select id="refundReason" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.95rem;">
          <option value="">Select a reason...</option>
          <option value="customer_request">Customer Request</option>
          <option value="duplicate_order">Duplicate Order</option>
          <option value="print_quality">Print Quality Issue</option>
          <option value="file_issue">File Issue</option>
          <option value="late_delivery">Late Delivery</option>
          <option value="damaged">Damaged</option>
          <option value="other">Other</option>
        </select>
      </div>
      
      <div style="margin-bottom: 16px;">
        <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #374151;">Notes (Optional)</label>
        <textarea id="refundNotes" rows="2" placeholder="Additional details about this refund..." style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.95rem; resize: vertical; box-sizing: border-box;"></textarea>
      </div>
      
      <div style="background: #fef2f2; padding: 10px; border-radius: 6px; font-size: 0.8rem; color: #991b1b; margin-bottom: 16px;" id="refundStripeWarning">
        <?= ICON_WARNING ?> This will process a refund through Stripe. The customer will receive the funds within 5-10 business days.
      </div>
      <div style="background: #fefce8; padding: 10px; border-radius: 6px; font-size: 0.8rem; color: #92400e; margin-bottom: 16px; display: none;" id="refundManualWarning">
        <?= ICON_WARNING ?> This order was paid manually (not via Stripe). Please process the refund through the original payment method.
      </div>
    </div>
    <div style="padding: 16px 20px; background: #f9fafb; border-top: 1px solid #e5e7eb; display: flex; gap: 10px; justify-content: flex-end; border-radius: 0 0 12px 12px; position: sticky; bottom: 0;">
      <button onclick="closeRefundModal()" style="padding: 10px 20px; background: #f3f4f6; color: #374151; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">Cancel</button>
      <button onclick="submitRefund()" id="refundSubmitBtn" style="padding: 10px 20px; background: #dc2626; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">&#8634; Process Refund</button>
    </div>
  </div>
</div>

<!-- Deactivate Payment Link Modal -->
<div id="deactivateLinkModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
  <div style="background: white; border-radius: 12px; padding: 0; max-width: 400px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
    <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
      <h3 style="margin: 0; color: #f59e0b; font-size: 1.2rem;">&#10006; Deactivate Payment Link</h3>
      <button onclick="closeDeactivateLinkModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #9ca3af; line-height: 1;">&times;</button>
    </div>
    <div style="padding: 20px;">
      <p style="color: #6b7280; margin-bottom: 16px;">Are you sure you want to deactivate the payment link for order <strong id="deactivateRefCode">-</strong>?</p>
      <p style="color: #6b7280; font-size: 0.85rem;">The customer will no longer be able to use this link to pay. You can generate a new link if needed.</p>
    </div>
    <div style="padding: 16px 20px; background: #f9fafb; border-top: 1px solid #e5e7eb; display: flex; gap: 10px; justify-content: flex-end; border-radius: 0 0 12px 12px;">
      <button onclick="closeDeactivateLinkModal()" style="padding: 10px 20px; background: #f3f4f6; color: #374151; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">Cancel</button>
      <button onclick="submitDeactivateLink()" id="deactivateLinkSubmitBtn" style="padding: 10px 20px; background: #f59e0b; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">Deactivate Link</button>
    </div>
  </div>
</div>

<!-- Payment Actions JavaScript -->
<script>
// ==================== MARK AS PAID ====================
let markPaidCurrentRef = '';
let markPaidCurrentAmount = 0;

function showMarkPaidModal(referenceCode, amount) {
    markPaidCurrentRef = referenceCode;
    markPaidCurrentAmount = amount;
    
    document.getElementById('markPaidRefCode').textContent = referenceCode;
    document.getElementById('markPaidAmount').textContent = '$' + amount.toFixed(2);
    document.getElementById('markPaidMethod').value = '';
    document.getElementById('markPaidNotes').value = '';
    
    document.getElementById('markPaidModal').style.display = 'flex';
}

function closeMarkPaidModal() {
    document.getElementById('markPaidModal').style.display = 'none';
}

async function submitMarkPaid() {
    const method = document.getElementById('markPaidMethod').value;
    const notes = document.getElementById('markPaidNotes').value.trim();
    
    if (!method) {
        alert('Please select a payment method');
        return;
    }
    
    const btn = document.getElementById('markPaidSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Processing...';
    
    try {
        const response = await fetch('payment-actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'mark_paid',
                referenceCode: markPaidCurrentRef,
                paymentMethod: method,
                notes: notes
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            closeMarkPaidModal();
            if (typeof showNotification === 'function') {
                showNotification('Order marked as paid successfully!', 'success');
            } else {
                alert('Order marked as paid successfully!');
            }
            // Reload the page to reflect changes
            setTimeout(() => location.reload(), 1000);
        } else {
            alert('Error: ' + (result.error || 'Failed to mark order as paid'));
        }
    } catch (error) {
        console.error('Mark paid error:', error);
        alert('Error: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '&#10004; Confirm Payment';
    }
}

// ==================== PROCESS REFUND ====================
let refundCurrentRef = '';
let refundCurrentAmount = 0;
let refundIsStripe = true;

function showRefundModal(referenceCode, amount, paymentType) {
    refundCurrentRef = referenceCode;
    refundCurrentAmount = amount;
    refundIsStripe = (paymentType === 'stripe');
    
    document.getElementById('refundRefCode').textContent = referenceCode;
    document.getElementById('refundOrderTotal').textContent = '$' + amount.toFixed(2);
    document.getElementById('refundFullAmount').textContent = '$' + amount.toFixed(2);
    document.getElementById('refundMaxAmount').textContent = '$' + amount.toFixed(2);
    document.getElementById('refundAmount').max = amount;
    document.getElementById('refundPaymentType').textContent = refundIsStripe ? 'Payment via Stripe' : 'Payment via Cash/E-transfer';
    
    // Show/hide appropriate warnings
    document.getElementById('refundStripeWarning').style.display = refundIsStripe ? 'block' : 'none';
    document.getElementById('refundManualWarning').style.display = refundIsStripe ? 'none' : 'block';
    
    // Reset form
    document.querySelector('input[name="refundType"][value="full"]').checked = true;
    updateRefundTypeUI();
    document.getElementById('refundReason').value = '';
    document.getElementById('refundNotes').value = '';
    document.getElementById('refundAmount').value = '';
    
    document.getElementById('refundModal').style.display = 'flex';
}

function closeRefundModal() {
    document.getElementById('refundModal').style.display = 'none';
}

function updateRefundTypeUI() {
    const fullSelected = document.querySelector('input[name="refundType"][value="full"]').checked;
    
    document.getElementById('refundTypeFull').style.borderColor = fullSelected ? '#dc2626' : '#d1d5db';
    document.getElementById('refundTypeFull').style.background = fullSelected ? '#fef2f2' : 'white';
    document.getElementById('refundTypePartial').style.borderColor = fullSelected ? '#d1d5db' : '#dc2626';
    document.getElementById('refundTypePartial').style.background = fullSelected ? 'white' : '#fef2f2';
    
    document.getElementById('partialAmountGroup').style.display = fullSelected ? 'none' : 'block';
}

// Add event listeners for refund type toggle
document.addEventListener('DOMContentLoaded', function() {
    const refundTypeInputs = document.querySelectorAll('input[name="refundType"]');
    refundTypeInputs.forEach(input => {
        input.addEventListener('change', updateRefundTypeUI);
    });
    
    // Also handle clicking on the labels
    document.getElementById('refundTypeFull')?.addEventListener('click', function() {
        document.querySelector('input[name="refundType"][value="full"]').checked = true;
        updateRefundTypeUI();
    });
    document.getElementById('refundTypePartial')?.addEventListener('click', function() {
        document.querySelector('input[name="refundType"][value="partial"]').checked = true;
        updateRefundTypeUI();
    });
});

async function submitRefund() {
    const refundType = document.querySelector('input[name="refundType"]:checked').value;
    const reason = document.getElementById('refundReason').value;
    const notes = document.getElementById('refundNotes').value.trim();
    let amount = refundCurrentAmount;
    
    if (refundType === 'partial') {
        amount = parseFloat(document.getElementById('refundAmount').value);
        if (!amount || amount <= 0) {
            alert('Please enter a valid refund amount');
            return;
        }
        if (amount > refundCurrentAmount) {
            alert('Refund amount cannot exceed order total');
            return;
        }
    }
    
    if (!reason) {
        alert('Please select a refund reason');
        return;
    }
    
    // Confirmation
    const confirmMsg = refundType === 'full' 
        ? `Are you sure you want to process a FULL refund of $${refundCurrentAmount.toFixed(2)} for order ${refundCurrentRef}?`
        : `Are you sure you want to process a PARTIAL refund of $${amount.toFixed(2)} for order ${refundCurrentRef}?`;
    
    if (!confirm(confirmMsg)) {
        return;
    }
    
    const btn = document.getElementById('refundSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Processing...';
    
    try {
        const response = await fetch('payment-actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'process_refund',
                referenceCode: refundCurrentRef,
                refundType: refundType,
                refundAmount: amount,
                reason: reason,
                notes: notes
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            closeRefundModal();
            if (typeof showNotification === 'function') {
                showNotification('Refund processed successfully!', 'success');
            } else {
                alert('Refund processed successfully!');
            }
            // Reload the page to reflect changes
            setTimeout(() => location.reload(), 1000);
        } else {
            alert('Error: ' + (result.error || 'Failed to process refund'));
        }
    } catch (error) {
        console.error('Refund error:', error);
        alert('Error: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '&#8634; Process Refund';
    }
}

// ==================== DEACTIVATE PAYMENT LINK ====================
let deactivateCurrentRef = '';

function showDeactivateLinkModal(referenceCode) {
    deactivateCurrentRef = referenceCode;
    document.getElementById('deactivateRefCode').textContent = referenceCode;
    document.getElementById('deactivateLinkModal').style.display = 'flex';
}

function closeDeactivateLinkModal() {
    document.getElementById('deactivateLinkModal').style.display = 'none';
}

async function submitDeactivateLink() {
    const btn = document.getElementById('deactivateLinkSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Deactivating...';
    
    try {
        const response = await fetch('payment-actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'deactivate_link',
                referenceCode: deactivateCurrentRef,
                processedBy: localStorage.getItem('adminName') || 'Admin'
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            closeDeactivateLinkModal();
            if (typeof showNotification === 'function') {
                showNotification('Payment link deactivated', 'success');
            } else {
                alert('Payment link deactivated');
            }
            // Reload the page to reflect changes
            setTimeout(() => location.reload(), 1000);
        } else {
            alert('Error: ' + (result.error || 'Failed to deactivate payment link'));
        }
    } catch (error) {
        console.error('Deactivate link error:', error);
        alert('Error: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Deactivate Link';
    }
}

// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeMarkPaidModal();
        closeRefundModal();
        closeDeactivateLinkModal();
    }
});

// Close modals on backdrop click
['markPaidModal', 'refundModal', 'deactivateLinkModal'].forEach(modalId => {
    document.getElementById(modalId)?.addEventListener('click', function(e) {
        if (e.target === this) {
            this.style.display = 'none';
        }
    });
});
</script>
</body>
</html>
<?php
}
