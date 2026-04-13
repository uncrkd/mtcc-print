<?php
require_once __DIR__ . '/includes/icons.php';
/**
 * Payment Cancelled Handler
 * Shows when user cancels the Stripe checkout
 */

$tempRef = $_GET['ref'] ?? null;

// Clean up the pending order from session
if ($tempRef) {
    session_start();
    unset($_SESSION['pending_order_' . $tempRef]);
    unset($_SESSION['stripe_session_' . $tempRef]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=5.0">
    <title>Payment Cancelled - Print Stuff</title>
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
        .header-logos img { max-width: 280px; width: 85%; height: auto; }
        .card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(124, 58, 237, 0.15);
            max-width: 480px;
            width: 100%;
            overflow: hidden;
            position: relative;
        }
        .card-header-band {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            padding: 16px 32px;
            text-align: center;
            border-radius: 16px 16px 0 0;
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
            color: #d97706;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 12px;
        }
        .subtitle {
            color: #374151;
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .info-box {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border: 1px solid #fcd34d;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 24px;
            text-align: left;
        }
        .info-box-title {
            color: #92400e;
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .info-box p {
            color: #374151;
            font-size: 0.85rem;
            line-height: 1.5;
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
            <div class="header-badge">&#10005; Payment Cancelled</div>
        </div>

        <div class="card-body">
            <h1>No Worries!</h1>

            <p class="subtitle">
                Your order wasn't charged and nothing was saved.<br>
                <strong>You can try again whenever you're ready.</strong>
            </p>

            <div class="info-box">
                <div class="info-box-title"><?= ICON_BULB ?> Your information is safe</div>
                <p>Since payment wasn't completed, no order was created and no data was stored. You'll need to fill out the form again to place a new order.</p>
            </div>

            <div class="contact">
                <div class="contact-heading">Need help? We're here.</div>
                <a href="mailto:orders@printstuff.ca">orders@printstuff.ca</a>
                <span class="contact-divider"></span>
                <a href="tel:4378828822">(437) 882-8822</a>
                <span class="contact-divider"></span>
                <a href="javascript:void(0)" onclick="if(window.Tawk_API)Tawk_API.maximize();">Live Chat</a>
            </div>

            <a href="/" class="btn">&larr; Return to Order Form</a>
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
