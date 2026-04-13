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
      <p>These Terms of Service govern your use of the poster printing and delivery services provided by <strong>Print Stuff</strong> ("we," "us," "our") through the website <strong>mtcc.print-stuff.ca</strong>. By placing an order, you agree to these terms. You must be at least <strong>18 years of age</strong> to place an order.</p>

      <h2>2. Relationship with MTCC</h2>
      <p>Print Stuff is an <strong>independent printing service provider</strong>. We are not owned, operated, or endorsed by the Metro Toronto Convention Centre (MTCC). MTCC is not responsible for the quality, delivery, or fulfillment of orders placed through our service. Pickup arrangements at MTCC are coordinated by Print Stuff and are subject to MTCC facility hours and availability.</p>

      <h2>3. Orders and Pricing</h2>
      <ul>
        <li>All prices are in <strong>Canadian Dollars (CAD)</strong> and are subject to <strong>13% HST</strong> (Harmonized Sales Tax).</li>
        <li>Pricing is determined by the turnaround time selected at the time of order placement. Turnaround tiers include Best Value, Standard, Rush, Express, Priority, and Same-Day.</li>
        <li>The price displayed at checkout is the final price. We do not add hidden fees or surcharges.</li>
        <li>We accept all common file formats (PDF, AI, EPS, PSD, PNG, JPG, TIFF, SVG, PPTX, and more) at no additional charge.</li>
        <li>We reserve the right to refuse any order at our sole discretion.</li>
      </ul>

      <h2>4. Payment</h2>
      <ul>
        <li>Payment is processed securely through <strong>Stripe</strong>. We do not store your credit card information.</li>
        <li>Full payment is required before production begins.</li>
        <li>For admin-created orders, a payment link will be sent to your email. Payment must be completed promptly to guarantee the quoted price.</li>
      </ul>

      <h2>5. Content Restrictions</h2>
      <p>You agree not to submit content that:</p>
      <ul>
        <li>Infringes on any third party's copyright, trademark, or other intellectual property rights</li>
        <li>Is unlawful, defamatory, obscene, threatening, or otherwise objectionable</li>
        <li>Contains material that promotes illegal activity</li>
        <li>Impersonates any person or entity</li>
      </ul>
      <p>We reserve the right to refuse to print any content that, in our sole judgment, violates these restrictions. No refund will be issued for orders refused on these grounds.</p>

      <h2>6. File Requirements and Customer Responsibility</h2>
      <ul>
        <li>You are responsible for ensuring your uploaded file is the correct, final version.</li>
        <li>You are responsible for verifying the accuracy of all content, including text, spelling, dimensions, and layout.</li>
        <li>Our team reviews files for print quality and compatibility. If there is a technical issue, we will contact you before production.</li>
        <li>Once your order moves to production, file changes cannot be accommodated.</li>
        <li>We are not responsible for errors in customer-supplied files, including but not limited to typos, incorrect dimensions, low resolution, or missing fonts.</li>
      </ul>

      <h2>7. Color Accuracy</h2>
      <p>Colors displayed on your screen may differ from the final printed product. Monitors use RGB color, while printing uses CMYK. We make every effort to produce accurate color reproduction, but <strong>we cannot guarantee an exact match</strong> between on-screen colors and printed output. If color precision is critical, please contact us to discuss a proof or color-matching process before placing your order.</p>

      <h2>8. Production and Delivery</h2>
      <ul>
        <li>Production timelines are based on business days. We do not print on weekends or statutory holidays.</li>
        <li><strong>MTCC Pickup:</strong> Orders are available for pickup at the Metro Toronto Convention Centre Business Centre (available in both North and South buildings) during MTCC business hours (Mon&ndash;Fri, 8 AM&ndash;4 PM).</li>
        <li><strong>Address Delivery:</strong> A flat $10.00 CAD delivery fee applies. Delivery is within the Greater Toronto Area.</li>
        <li>We make every effort to meet your selected delivery date. In the rare event of a delay, we will notify you promptly.</li>
      </ul>

      <h2>9. MTCC Facility Access and Pickup</h2>
      <ul>
        <li>Pickup at MTCC is subject to the facility's operating hours, building access policies, and any restrictions imposed by the convention centre. Print Stuff does not control MTCC's building access, hours, or security protocols.</li>
        <li>If MTCC closes unexpectedly, changes its hours, or restricts access for any reason (including emergencies, maintenance, or event-specific security), we will make reasonable efforts to coordinate an alternative pickup arrangement or delivery.</li>
        <li><strong>Pickup authorization:</strong> To collect an order, a valid <strong>order reference number</strong> and the <strong>name on the order</strong> must be provided. If someone other than the original customer will be picking up, the customer must notify us in advance with the authorized person's name by emailing <a href="mailto:orders@printstuff.ca">orders@printstuff.ca</a> or noting it in their order.</li>
        <li>Print Stuff is not liable for orders picked up by unauthorized individuals who provide a valid order reference number and name.</li>
      </ul>

      <h2>10. Cancellations and Refunds</h2>
      <ul>
        <li><strong>Before production:</strong> You may cancel your order for a full refund by contacting us at <a href="mailto:orders@printstuff.ca">orders@printstuff.ca</a>.</li>
        <li><strong>During or after production:</strong> Cancellations are not possible once printing has begun. Refunds are handled on a case-by-case basis.</li>
        <li><strong>Quality issues:</strong> If your poster has a print quality defect caused by us, we will reprint it at no charge or issue a full refund.</li>
      </ul>

      <h2>11. Event Cancellation or Venue Changes</h2>
      <ul>
        <li>If a convention or event is cancelled, relocated, or significantly rescheduled <strong>before your order enters production</strong>, you may cancel for a full refund.</li>
        <li>If your order is <strong>already in production or completed</strong> when an event is cancelled, we will issue a <strong>store credit</strong> for the full order value, valid for 12 months and applicable to any future order.</li>
        <li>Print Stuff is not responsible for event cancellations, venue changes, or scheduling modifications made by event organizers or MTCC. These circumstances are beyond our control.</li>
      </ul>

      <h2>12. Intellectual Property</h2>
      <p>You represent and warrant that you own or have the right to print the content in your submitted file. By uploading a file, you grant Print Stuff a limited, non-exclusive license to reproduce and print your content solely for the purpose of fulfilling your order. We do not claim ownership of your uploaded files.</p>

      <h2>13. Indemnification</h2>
      <p>You agree to indemnify, defend, and hold harmless Print Stuff, its owners, employees, and partners from any claims, damages, losses, liabilities, costs, or expenses (including legal fees) arising out of or related to: (a) your breach of these Terms; (b) your uploaded content infringing the rights of any third party; or (c) your use of our services.</p>

      <h2>14. Limitation of Liability</h2>
      <p>Our total liability for any claim related to our services is limited to the amount you paid for the specific order in question. We are not liable for indirect, incidental, or consequential damages, including but not limited to lost profits, missed presentations, event-related costs, or travel expenses.</p>

      <h2>15. Governing Law and Dispute Resolution</h2>
      <p>These Terms are governed by the laws of the <strong>Province of Ontario</strong> and the federal laws of Canada applicable therein. Any disputes arising from these Terms or our services shall be resolved in the courts of Ontario, Canada. Before pursuing legal action, both parties agree to attempt to resolve disputes through good-faith communication.</p>

      <h2>16. Force Majeure</h2>
      <p>Print Stuff is not liable for delays or failure to perform our obligations caused by circumstances beyond our reasonable control, including but not limited to: natural disasters, extreme weather, power outages, equipment failure, supplier delays, public health emergencies, government actions, or disruptions at MTCC or other delivery locations.</p>

      <h2>17. Changes to These Terms</h2>
      <p>We may update these terms from time to time. The "Last updated" date at the top of this page indicates the most recent revision. Continued use of our services after changes constitutes acceptance of the updated terms.</p>

      <h2>18. Contact Us</h2>
      <p>If you have questions about these terms, contact us:</p>
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
