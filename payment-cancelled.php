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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Cancelled - Print Stuff</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Montserrat', sans-serif;
            background: linear-gradient(135deg, #faf8ff 0%, #ede9fe 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            background: white;
            border-radius: 16px;
            padding: 50px 40px;
            box-shadow: 0 8px 32px rgba(124, 58, 237, 0.15);
            text-align: center;
            max-width: 500px;
            width: 100%;
            border: 2px solid #f59e0b;
        }
        
        .icon {
            font-size: 5rem;
            margin-bottom: 20px;
        }
        
        h1 {
            color: #f59e0b;
            margin-bottom: 10px;
            font-size: 2rem;
            font-weight: 700;
        }
        
        .message {
            color: #374151;
            font-size: 1.1rem;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        
        .info-box {
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 12px;
            padding: 20px;
            margin: 30px 0;
            text-align: left;
        }
        
        .info-box h3 {
            color: #b45309;
            margin: 0 0 10px 0;
            font-size: 1rem;
        }
        
        .info-box p {
            color: #374151;
            margin: 0;
            font-size: 0.95rem;
            line-height: 1.5;
        }
        
        .btn {
            display: inline-block;
            padding: 14px 28px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
            margin: 5px;
        }
        
        .btn-primary {
            background: #7c3aed;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5b21b6;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .contact {
            margin-top: 30px;
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        .contact a {
            color: #7c3aed;
            text-decoration: none;
        }
        
        @media (max-width: 600px) {
            .container {
                padding: 30px 25px;
            }
            
            h1 {
                font-size: 1.75rem;
            }
            
            .btn {
                display: block;
                width: 100%;
                margin: 10px 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon"><?= ICON_CART ?></div>
        <h1>Payment Cancelled</h1>
        
        <p class="message">
            No worries! Your order wasn't charged and you can try again whenever you're ready.
        </p>
        
        <div class="info-box">
            <h3><?= ICON_BULB ?> Your information is safe</h3>
            <p>
                Your order details were not saved since payment wasn't completed. 
                You'll need to fill out the form again to place a new order.
            </p>
        </div>
        
        <div style="margin-top: 30px;">
            <a href="/" class="btn btn-primary"><?= SYMBOL_ARROW_LEFT ?> Return to Order Form</a>
        </div>
        
        <div class="contact">
            <p>Need help? Contact us at <a href="mailto:orders@printstuff.ca">orders@printstuff.ca</a></p>
            <p>or call <a href="tel:4378828822">(437) 882-8822</a></p>
        </div>
    </div>
</body>
</html>
