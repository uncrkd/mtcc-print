<?php
/**
 * Vendor Portal Login
 * PIN-based authentication matching the courier dispatch pattern
 * 
 * Location: /fulfillment/index.php
 */

require_once 'vendor-auth.php';

// If already logged in, redirect to dashboard
if (isVendorLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

// Handle login form submission
$error = null;
$loggedOut = isset($_GET['logged_out']);
$expired = isset($_GET['expired']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pin = trim($_POST['pin'] ?? '');
    
    if (empty($email) || empty($pin)) {
        $error = 'Please enter both email and PIN.';
    } else {
        $result = authenticateVendor($email, $pin);
        if ($result['success']) {
            header('Location: dashboard.php');
            exit;
        } else {
            $error = $result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Portal - Print Stuff</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 50%, #ddd6fe 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            width: 100%;
            max-width: 420px;
        }
        
        .logo-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo-header img {
            height: 50px;
            margin-bottom: 12px;
        }
        
        .logo-header h1 {
            font-size: 1.5rem;
            color: #7c3aed;
            font-weight: 700;
        }
        
        .logo-header p {
            color: #6b7280;
            font-size: 0.9rem;
            margin-top: 6px;
        }
        
        .login-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(124, 58, 237, 0.15);
            overflow: hidden;
        }
        
        .card-top {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            padding: 28px 30px;
            text-align: center;
            color: white;
        }
        
        .card-top h2 {
            font-size: 1.15rem;
            font-weight: 600;
        }
        
        .card-top p {
            font-size: 0.85rem;
            opacity: 0.8;
            margin-top: 6px;
        }
        
        .card-body {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-family: 'Montserrat', sans-serif;
            font-size: 1rem;
            color: #1e293b;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
        }
        
        .form-group input:focus {
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }
        
        .form-group input::placeholder {
            color: #9ca3af;
        }
        
        .pin-input {
            text-align: center;
            font-size: 1.5rem !important;
            font-weight: 700 !important;
            letter-spacing: 8px;
        }
        
        .btn-login {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-family: 'Montserrat', sans-serif;
            font-size: 1.05rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(124, 58, 237, 0.3);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .error-msg {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .success-msg {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .footer {
            text-align: center;
            margin-top: 24px;
            color: #6b7280;
            font-size: 0.8rem;
        }
        
        .footer a {
            color: #7c3aed;
            text-decoration: none;
        }
        
        .footer a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 480px) {
            .card-body { padding: 24px 20px; }
            .card-top { padding: 24px 20px; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        
        <div class="logo-header">
            <img src="../mtcc-ps-logo.png" alt="Print Stuff" onerror="this.style.display='none'">
            <h1>Vendor Portal</h1>
            <p>Print Stuff &bull; Metro Toronto Convention Centre</p>
        </div>
        
        <div class="login-card">
            <div class="card-top">
                <h2>&#128272; Vendor Sign In</h2>
                <p>Enter your email and PIN to access your orders</p>
            </div>
            
            <div class="card-body">
                <?php if ($error): ?>
                <div class="error-msg"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                
                <?php if ($loggedOut): ?>
                <div class="success-msg">You have been signed out.</div>
                <?php endif; ?>
                
                <?php if ($expired): ?>
                <div class="error-msg">Your session has expired. Please sign in again.</div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" placeholder="vendor@example.com" 
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
                    </div>
                    
                    <div class="form-group">
                        <label for="pin">6-Digit PIN</label>
                        <input type="password" id="pin" name="pin" placeholder="&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;" 
                               class="pin-input" maxlength="6" inputmode="numeric" pattern="[0-9]*" required>
                    </div>
                    
                    <button type="submit" class="btn-login">Sign In</button>
                </form>
            </div>
        </div>
        
        <div class="footer">
            <p>Need access? Contact <a href="mailto:orders@printstuff.ca">orders@printstuff.ca</a></p>
            <p style="margin-top: 6px;">&copy; <?= date('Y') ?> Print Stuff</p>
        </div>
        
    </div>
    
    <script>
        // Auto-focus PIN field after email is entered
        document.getElementById('email').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('pin').focus();
            }
        });
        
        // PIN: numbers only
        document.getElementById('pin').addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    </script>
</body>
</html>
