<?php
/**
 * Privacy Policy
 * Location: /privacy.php (server: /privacy via .htaccess)
 */
$pageTitle = 'Privacy Policy';
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
      <p><strong>Print Stuff</strong> ("we," "us," "our") operates the poster printing service at <strong>mtcc.print-stuff.ca</strong>. This Privacy Policy explains how we collect, use, and protect your personal information when you use our services.</p>

      <h2>2. Information We Collect</h2>
      <p>When you place an order, we collect the following information:</p>
      <ul>
        <li><strong>Contact details:</strong> Full name, email address, phone number, company/organization (optional)</li>
        <li><strong>Order details:</strong> Poster dimensions, material, delivery date, delivery preference, event selection</li>
        <li><strong>Uploaded files:</strong> Your print file (PDF, PNG, PPTX, etc.)</li>
        <li><strong>Delivery address:</strong> Only if you select address delivery</li>
        <li><strong>Payment information:</strong> Processed securely by Stripe &mdash; we never see or store your credit card number</li>
      </ul>

      <h2>3. How We Use Your Information</h2>
      <p>Your information is used solely for:</p>
      <ul>
        <li>Processing and fulfilling your poster order</li>
        <li>Communicating about your order status (confirmation, production updates, delivery notifications)</li>
        <li>Contacting you if there is an issue with your file or order</li>
        <li>Improving our services and understanding customer needs</li>
        <li>Sending promotional updates about upcoming events and offers (only if you opted in)</li>
      </ul>

      <h2>4. Payment Processing</h2>
      <p>All payments are processed by <strong><a href="https://stripe.com/privacy" target="_blank">Stripe</a></strong>, a PCI-DSS Level 1 certified payment processor. When you pay:</p>
      <ul>
        <li>Your card details go directly to Stripe's servers &mdash; they never pass through our systems</li>
        <li>We only receive confirmation that payment was successful, the transaction amount, and a reference ID</li>
        <li>For more about how Stripe handles your data, see <a href="https://stripe.com/privacy" target="_blank">Stripe's Privacy Policy</a></li>
      </ul>

      <h2>5. Data Storage and Security</h2>
      <ul>
        <li>Order data and uploaded files are stored on our secure hosted server in Canada</li>
        <li>We use SSL encryption for all data transmitted between your browser and our server</li>
        <li>Access to customer data is restricted to authorized team members who need it to fulfill orders</li>
        <li>Uploaded files are stored securely and used only for printing your order</li>
      </ul>

      <h2>6. Data Retention</h2>
      <ul>
        <li><strong>Order records:</strong> Retained for accounting and customer service purposes</li>
        <li><strong>Uploaded files:</strong> Retained for 90 days after order completion, then deleted</li>
        <li><strong>Payment records:</strong> Managed by Stripe according to their retention policies</li>
      </ul>
      <p>You may request deletion of your data at any time by contacting us.</p>

      <h2>7. Third-Party Services</h2>
      <p>We use the following third-party services that may process your data:</p>
      <ul>
        <li><strong>Stripe</strong> &mdash; Payment processing (<a href="https://stripe.com/privacy" target="_blank">Privacy Policy</a>)</li>
        <li><strong>Tawk.to</strong> &mdash; Live chat support (<a href="https://www.tawk.to/privacy-policy/" target="_blank">Privacy Policy</a>)</li>
        <li><strong>Google</strong> &mdash; Maps and routing for delivery logistics</li>
      </ul>
      <p>We do not sell, rent, or trade your personal information to any third party.</p>

      <h2>8. Cookies</h2>
      <p>Our website uses minimal cookies:</p>
      <ul>
        <li><strong>Session cookies:</strong> Required for order processing and form functionality</li>
        <li><strong>Local storage:</strong> Used to save your form progress so you can resume if you accidentally close the page (expires after 24 hours)</li>
        <li><strong>Third-party cookies:</strong> Stripe and Tawk.to may set cookies for their functionality</li>
      </ul>

      <h2>9. Marketing Communications</h2>
      <p>If you opt in to marketing communications during checkout, we may send you updates about upcoming events and special offers. You can unsubscribe at any time by contacting us or clicking the unsubscribe link in any marketing email. We comply with Canada's Anti-Spam Legislation (CASL).</p>

      <h2>10. Your Rights</h2>
      <p>You have the right to:</p>
      <ul>
        <li>Request access to the personal information we hold about you</li>
        <li>Request correction of inaccurate information</li>
        <li>Request deletion of your personal information</li>
        <li>Withdraw consent for marketing communications</li>
      </ul>
      <p>To exercise any of these rights, contact us at the details below.</p>

      <h2>11. Changes to This Policy</h2>
      <p>We may update this policy from time to time. The "Last updated" date at the top indicates the most recent revision.</p>

      <h2>12. Contact Us</h2>
      <p>For privacy-related questions or requests:</p>
      <ul>
        <li><strong>Email:</strong> <a href="mailto:orders@printstuff.ca">orders@printstuff.ca</a></li>
        <li><strong>Phone:</strong> <a href="tel:4378828822">(437) 882-8822</a></li>
        <li><strong>Website:</strong> <a href="https://print-stuff.ca">print-stuff.ca</a></li>
      </ul>

      <a href="/" class="back-link">&larr; Return to Order Form</a>
    </div>
    <div class="footer">
      &copy; <?= date('Y') ?> Print Stuff &middot; Big or small, we print it all.
    </div>
  </div>
</body>
</html>
