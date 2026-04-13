<?php
require_once __DIR__ . '/includes/icons.php';
$referenceCode = $_GET['ref'] ?? 'Unknown';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=5.0">
    <title>Order Submitted - <?= htmlspecialchars($referenceCode) ?> - Print Stuff</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #faf8ff 0%, #ede9fe 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .header-logos { text-align: center; margin-bottom: 20px; }
        .header-logos img { max-width: 360px; width: 90%; height: auto; }
        .card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(124, 58, 237, 0.15);
            max-width: 480px;
            width: 100%;
            overflow: hidden;
            position: relative;
        }
        .card::before {
            content: '';
            position: absolute;
            width: 180px; height: 180px;
            top: -60px; right: -60px;
            background: linear-gradient(135deg, rgba(124,58,237,0.06) 0%, transparent 100%);
            border-radius: 50%;
            pointer-events: none;
        }
        .card-header-band {
            background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
            padding: 28px 32px;
            text-align: center;
            border-radius: 16px 16px 0 0;
            position: relative;
        }
        .card-header-band::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, rgba(255,255,255,0.3), transparent);
        }
        .header-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            backdrop-filter: blur(4px);
        }
        .card-body { padding: 32px 32px 28px; text-align: center; }
        h1 {
            color: #7c3aed;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 8px;
            animation: fadeUp 0.6s ease-out;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .subtitle {
            color: #374151;
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .subtitle strong { color: #7c3aed; }
        .order-ref-card {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 14px 24px;
            border-radius: 12px;
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 24px;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 16px rgba(16, 185, 129, 0.3);
        }
        .next-steps {
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            text-align: left;
        }
        .next-steps-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 14px;
        }
        .step {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            background: white;
            border-radius: 8px;
            border-left: 3px solid #7c3aed;
            margin-bottom: 8px;
            font-size: 0.88rem;
            color: #374151;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
        }
        .step:last-of-type { margin-bottom: 0; }
        .step-dot {
            width: 22px; height: 22px;
            border-radius: 50%;
            background: #7c3aed;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .step-note {
            font-size: 0.75rem;
            color: #7c3aed;
            margin-top: 4px;
        }
        .steps-closing {
            text-align: center;
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid #e5e7eb;
            color: #7c3aed;
            font-weight: 700;
            font-size: 0.9rem;
        }
        .contact {
            color: #6b7280;
            font-size: 0.82rem;
            margin-bottom: 20px;
        }
        .contact-heading { font-weight: 600; margin-bottom: 4px; }
        .contact a { color: #7c3aed; text-decoration: none; font-weight: 600; }
        .contact a:hover { text-decoration: underline; }
        .contact-divider {
            display: inline-block;
            width: 1px; height: 12px;
            background: #d1d5db;
            vertical-align: middle;
            margin: 0 6px;
        }
        .contact-ref {
            font-size: 0.78rem;
            color: #6b7280;
            margin-top: 6px;
        }
        .btn {
            display: inline-block;
            padding: 14px 32px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.95rem;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            background: #7c3aed;
            color: white;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.25);
        }
        .btn:hover {
            background: #5b21b6;
            transform: translateY(-2px);
            box-shadow: rgba(124, 58, 237, 0.4) 0px 8px 24px;
        }
        .card-footer {
            background: linear-gradient(135deg, #fafbfc 0%, #ffffff 100%);
            border-top: 1px solid #f3f4f6;
            padding: 16px 32px;
            text-align: center;
            color: #9ca3af;
            font-size: 0.75rem;
        }
        @media (max-width: 600px) {
            .card-header-band { padding: 14px 24px; }
            .card-body { padding: 24px 22px 20px; }
            h1 { font-size: 1.5rem; }
            .order-ref-card { font-size: 1.1rem; padding: 12px 20px; }
            .step { font-size: 0.82rem; padding: 8px 10px; }
            .card-footer { padding: 14px 22px; }
            .header-logos img { max-width: 220px; }
        }
    </style>
</head>
<body>
    <div class="header-logos">
        <img src="mtcc-ps-logo.png" alt="MTCC + Print Stuff">
    </div>

    <div class="card">
        <div class="card-header-band">
            <div class="header-badge"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Order Received</div>
        </div>

        <div class="card-body">
            <h1>Order Submitted!</h1>

            <div class="order-ref-card">
                Order #<?= htmlspecialchars($referenceCode) ?>
            </div>

            <p class="subtitle">
                Your poster order has been received and our team has been notified.<br>
                <strong>We'll get back to you shortly with next steps.</strong>
            </p>

            <div class="next-steps">
                <div class="next-steps-title">What happens now</div>

                <div class="step">
                    <div class="step-dot">1</div>
                    <div>
                        <span>You'll receive an email confirmation within a few minutes</span>
                    </div>
                </div>

                <div class="step">
                    <div class="step-dot">2</div>
                    <div>
                        <span>We review your file for print quality</span>
                    </div>
                </div>

                <div class="step">
                    <div class="step-dot">3</div>
                    <div>
                        <span>We'll send you a payment link within 18 minutes</span>
                        <div class="step-note">Payment is required to lock in your price and start production</div>
                    </div>
                </div>

                <div class="steps-closing">
                    Check your inbox &mdash; we'll be in touch soon.
                </div>
            </div>

            <div class="contact">
                <div class="contact-heading">Questions? We're here.</div>
                <a href="mailto:orders@printstuff.ca">orders@printstuff.ca</a>
                <span class="contact-divider"></span>
                <a href="tel:4378828822">(437) 882-8822</a>
                <span class="contact-divider"></span>
                <a href="javascript:void(0)" onclick="if(window.Tawk_API)Tawk_API.maximize();">Live Chat</a>
                <div class="contact-ref">Reference: <strong><?= htmlspecialchars($referenceCode) ?></strong></div>
            </div>

            <a href="/" class="btn">Submit Another Order</a>
        </div>

        <div class="card-footer">
            &copy; <?= date('Y') ?> Print Stuff &middot; Big or small, we print it all.
        </div>
    </div>

    <!--Start of Tawk.to Script-->
    <script type="text/javascript">
    var Tawk_API=Tawk_API||{}, Tawk_LoadStart=new Date();
    (function(){
    var s1=document.createElement("script"),s0=document.getElementsByTagName("script")[0];
    s1.async=true;
    s1.src='https://embed.tawk.to/69bcadcf600a121c36fa7a4b/1jk4gdsmg';
    s1.charset='UTF-8';
    s1.setAttribute('crossorigin','*');
    s0.parentNode.insertBefore(s1,s0);
    })();
    </script>
    <!--End of Tawk.to Script-->
</body>
</html>
