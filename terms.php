<?php
/**
 * Terms of Service
 * Location: /terms.php (server: /terms via .htaccess)
 */
$pageTitle = 'Terms of Service';
$lastUpdated = 'April 13, 2026';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=5.0">
  <title><?= $pageTitle ?> - Print Stuff</title>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, sans-serif;
      background: linear-gradient(135deg, #faf8ff 0%, #ede9fe 100%);
      min-height: 100vh;
      padding: 20px;
      color: #374151;
      line-height: 1.7;
    }
    .container {
      max-width: 720px;
      margin: 0 auto;
      background: white;
      border-radius: 16px;
      box-shadow: 0 8px 32px rgba(124, 58, 237, 0.15);
      overflow: hidden;
    }
    .header {
      background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
      padding: 32px 40px;
      color: white;
    }
    .header h1 { font-size: 1.75rem; font-weight: 700; margin-bottom: 4px; }
    .header p { font-size: 0.85rem; opacity: 0.85; }
    .content { padding: 36px 40px 40px; }
    h2 {
      color: #7c3aed;
      font-size: 1.1rem;
      font-weight: 700;
      margin: 28px 0 10px;
      padding-bottom: 6px;
      border-bottom: 1px solid #f3f4f6;
    }
    h2:first-of-type { margin-top: 0; }
    p { margin-bottom: 12px; font-size: 0.9rem; }
    ul { margin: 0 0 12px 20px; font-size: 0.9rem; }
    li { margin-bottom: 6px; }
    strong { color: #1e1b2e; }
    a { color: #7c3aed; text-decoration: none; }
    a:hover { text-decoration: underline; }
    .back-link {
      display: inline-block;
      margin: 20px 0;
      padding: 10px 24px;
      background: #7c3aed;
      color: white;
      border-radius: 10px;
      font-weight: 600;
      font-size: 0.9rem;
      text-decoration: none;
      transition: all 0.2s;
    }
    .back-link:hover { background: #5b21b6; transform: translateY(-1px); text-decoration: none; }
    .footer { padding: 16px 40px; background: #fafbfc; border-top: 1px solid #f3f4f6; text-align: center; color: #9ca3af; font-size: 0.75rem; }
    @media (max-width: 600px) {
      .header { padding: 24px; }
      .content { padding: 24px; }
      .footer { padding: 14px 24px; }
      h1 { font-size: 1.4rem; }
    }
  </style>
</head>
<body>
  <div style="text-align: center; margin-bottom: 20px;">
    <img src="mtcc-ps-logo.png" alt="MTCC + Print Stuff" style="max-width: 280px; width: 80%; height: auto;">
  </div>
  <div class="container">
    <div class="header">
      <h1><?= $pageTitle ?></h1>
      <p>Last updated: <?= $lastUpdated ?></p>
    </div>
    <div class="content">

      <h2>1. Overview</h2>
      <p>These Terms of Service govern your use of the poster printing and delivery services provided by <strong>Print Stuff</strong> ("we," "us," "our") through the website <strong>mtcc.print-stuff.ca</strong>. By placing an order, you agree to these terms.</p>

      <h2>2. Orders and Pricing</h2>
      <ul>
        <li>All prices are in <strong>Canadian Dollars (CAD)</strong> and are subject to <strong>13% HST</strong> (Harmonized Sales Tax).</li>
        <li>Pricing is determined by the turnaround time selected at the time of order placement. Turnaround tiers include Best Value, Standard, Rush, Express, Priority, and Same-Day.</li>
        <li>The price displayed at checkout is the final price. We do not add hidden fees or surcharges.</li>
        <li>We accept all common file formats (PDF, AI, EPS, PSD, PNG, JPG, TIFF, SVG, PPTX, and more) at no additional charge.</li>
      </ul>

      <h2>3. Payment</h2>
      <ul>
        <li>Payment is processed securely through <strong>Stripe</strong>. We do not store your credit card information.</li>
        <li>Full payment is required before production begins.</li>
        <li>For admin-created orders, a payment link will be sent to your email. Payment must be completed promptly to guarantee the quoted price.</li>
      </ul>

      <h2>4. File Requirements</h2>
      <ul>
        <li>You are responsible for ensuring your uploaded file is the correct, final version.</li>
        <li>Our team reviews files for print quality and compatibility. If there is an issue, we will contact you before production.</li>
        <li>Once your order moves to production, file changes cannot be accommodated.</li>
      </ul>

      <h2>5. Production and Delivery</h2>
      <ul>
        <li>Production timelines are based on business days. We do not print on weekends or statutory holidays.</li>
        <li><strong>MTCC Pickup:</strong> Orders are available for pickup at the Metro Toronto Convention Centre Business Centre during business hours (Mon&ndash;Fri, 8 AM&ndash;4 PM).</li>
        <li><strong>Address Delivery:</strong> A flat $10.00 CAD delivery fee applies. Delivery is within the Greater Toronto Area.</li>
        <li>We make every effort to meet your selected delivery date. In the rare event of a delay, we will notify you promptly.</li>
      </ul>

      <h2>6. Cancellations and Refunds</h2>
      <ul>
        <li><strong>Before production:</strong> You may cancel your order for a full refund by contacting us at <a href="mailto:orders@printstuff.ca">orders@printstuff.ca</a>.</li>
        <li><strong>During or after production:</strong> Cancellations are not possible once printing has begun. Refunds are handled on a case-by-case basis.</li>
        <li><strong>Quality issues:</strong> If your poster has a print quality defect caused by us, we will reprint it at no charge or issue a full refund.</li>
      </ul>

      <h2>7. Intellectual Property</h2>
      <p>You represent that you own or have the right to print the content in your submitted file. We do not claim ownership of your uploaded files. Files are stored securely and used solely for order fulfillment.</p>

      <h2>8. Limitation of Liability</h2>
      <p>Our total liability for any claim related to our services is limited to the amount you paid for the specific order in question. We are not liable for indirect, incidental, or consequential damages, including but not limited to lost profits, missed presentations, or event-related costs.</p>

      <h2>9. Changes to These Terms</h2>
      <p>We may update these terms from time to time. The "Last updated" date at the top of this page indicates the most recent revision. Continued use of our services after changes constitutes acceptance of the updated terms.</p>

      <h2>10. Contact Us</h2>
      <p>If you have questions about these terms, contact us:</p>
      <ul>
        <li><strong>Email:</strong> <a href="mailto:orders@printstuff.ca">orders@printstuff.ca</a></li>
        <li><strong>Phone:</strong> <a href="tel:4378828822">(437) 882-8822</a></li>
      </ul>

      <a href="/" class="back-link">&larr; Return to Order Form</a>
    </div>
    <div class="footer">
      &copy; <?= date('Y') ?> Print Stuff &middot; Big or small, we print it all.
    </div>
  </div>
</body>
</html>
