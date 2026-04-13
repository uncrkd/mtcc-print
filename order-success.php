<?php
require_once __DIR__ . '/includes/icons.php';
$referenceCode = $_GET['ref'] ?? 'Unknown';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Submitted Successfully - <?= htmlspecialchars($referenceCode) ?></title>
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
        
        .success-container {
            background: white;
            border-radius: 16px;
            padding: 50px 40px;
            box-shadow: 0 8px 32px rgba(124, 58, 237, 0.15);
            text-align: center;
            max-width: 600px;
            width: 100%;
            border: 2px solid #7c3aed;
        }
        
        .success-icon {
            font-size: 5rem;
            margin-bottom: 20px;
            animation: bounce 1s ease-in-out;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-20px); }
            60% { transform: translateY(-10px); }
        }
        
        h1 {
            color: #7c3aed;
            margin-bottom: 10px;
            font-size: 2.5rem;
            font-weight: 700;
        }
        
        .reference-code {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 15px 25px;
            border-radius: 12px;
            font-size: 1.5rem;
            font-weight: 700;
            margin: 30px 0;
            letter-spacing: 1px;
            box-shadow: rgba(16, 185, 129, 0.3) 0px 8px 24px;
        }
        
        .success-message {
            color: #374151;
            font-size: 1.1rem;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        
        .next-steps {
            background: #f8fafc;
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
            text-align: left;
        }
        
        .next-steps h3 {
            color: #7c3aed;
            margin-bottom: 15px;
            font-size: 1.2rem;
        }
        
        .step {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 15px;
            padding: 10px;
            background: white;
            border-radius: 8px;
            border-left: 4px solid #7c3aed;
        }
        
        .step-number {
            background: #7c3aed;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 600;
            flex-shrink: 0;
        }
        
        .step-text {
            color: #374151;
            font-size: 0.95rem;
            line-height: 1.4;
        }
        
        .contact-info {
            background: linear-gradient(135deg, #ede9fe 0%, #f0f9ff 100%);
            border: 2px solid #7c3aed;
            border-radius: 12px;
            padding: 20px;
            margin: 30px 0;
        }
        
        .contact-info h3 {
            color: #7c3aed;
            margin-bottom: 10px;
        }
        
        .contact-info p {
            color: #374151;
            margin: 5px 0;
            font-size: 0.95rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
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
            transform: translateY(-1px);
        }
        
        .footer {
            margin-top: 40px;
            color: #6b7280;
            font-size: 0.85rem;
        }
        
        @media (max-width: 600px) {
            .success-container {
                padding: 30px 25px;
            }
            
            h1 {
                font-size: 2rem;
            }
            
            .reference-code {
                font-size: 1.2rem;
                padding: 12px 20px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-icon"><?= ICON_PARTY ?></div>
        <h1>Order Submitted!</h1>
        
        <div class="reference-code">
            Order #<?= htmlspecialchars($referenceCode) ?>
        </div>
        
        <p class="success-message">
            Your poster order has been successfully submitted and our team has been notified. 
            We'll review your request and get back to you quickly!
        </p>
        
        <div class="next-steps">
            <h3><?= ICON_CLIPBOARD ?> What Happens Next:</h3>
            
            <div class="step">
                <div class="step-number">1</div>
                <div class="step-text">
                    <strong>Immediate confirmation:</strong> You should receive an email confirmation within a few minutes
                </div>
            </div>
            
            <div class="step">
                <div class="step-number">2</div>
                <div class="step-text">
                    <strong>File review:</strong> Our team will check your artwork file for print quality and compatibility
                </div>
            </div>
            
            <div class="step">
                <div class="step-number">3</div>
                <div class="step-text">
                    <strong>Quick response:</strong> We'll email you within 18 minutes with a payment link and next steps. <br/><span style="font-size: 0.8rem; color: #7c3aed;"><strong>Note: </strong> Payments are required as soon as possible to guarantee your price. Late payments may result in higher pricing.</span>
                </div>
            </div>
            
            <div class="step">
                <div class="step-number">4</div>
                <div class="step-text">
                    <strong>Production & delivery:</strong> Once we receive your payment, we'll send your poster to print and deliver according to your selected timeline. <br/><span style="font-size: 0.8rem; color: #7c3aed;"><strong>Note: </strong> We cannot send your order to print without payment.</span>
                </div>
            </div>
        </div>
        
        <div class="contact-info">
            <h3><?= ICON_PHONE ?> Need to make changes or have questions?</h3>
            <p><strong>Email:</strong> orders@printstuff.ca</p>
            <p><strong>Phone:</strong> (437) 882-8822</p>
            <p><strong>Reference your order number:</strong> <?= htmlspecialchars($referenceCode) ?></p>
        </div>
        
        <div class="action-buttons">
            <a href="/" class="btn btn-primary"><?= ICON_MEMO ?> Submit Another Order</a>
            <a href="mailto:orders@printstuff.com?subject=Order #: <?= urlencode($referenceCode) ?>" class="btn btn-secondary"><?= ICON_ENVELOPE ?> Contact Us</a>
        </div>
        
        <div class="footer">
            <p>Thank you for choosing Print Stuff for your poster printing needs!</p>
            <p>&copy; <?= date('Y') ?> Print Stuff - Professional Poster Printing</p>
        </div>
    </div>
</body>
</html>
