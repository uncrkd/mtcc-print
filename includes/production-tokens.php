<?php
/**
 * Production Tokens - Vendor Portal Token Management
 * MTCC Print Services
 *
 * Location: /includes/production-tokens.php
 * Extracted from: admin/production.php
 */

function loadTokens($tokensFile) {
    if (!file_exists($tokensFile)) {
        $defaultData = [
            'tokens' => [],
            'metadata' => [
                'created_at' => date('c'),
                'updated_at' => date('c'),
                'version' => '1.0'
            ]
        ];
        file_put_contents($tokensFile, json_encode($defaultData, JSON_PRETTY_PRINT), LOCK_EX);
        return $defaultData;
    }
    return json_decode(file_get_contents($tokensFile), true) ?: ['tokens' => []];
}

function saveTokens($data, $tokensFile) {
    $data['metadata']['updated_at'] = date('c');
    return file_put_contents($tokensFile, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

function generateVendorToken($referenceCode, $vendorId, $tokensFile) {
    $token = bin2hex(random_bytes(32)); // 64 char token

    $tokens = loadTokens($tokensFile);

    // Revoke any existing tokens for this order
    foreach ($tokens['tokens'] as $existingToken => &$tokenData) {
        if ($tokenData['reference_code'] === $referenceCode && empty($tokenData['revoked'])) {
            $tokenData['revoked'] = true;
            $tokenData['revoked_at'] = date('c');
            $tokenData['revoked_reason'] = 'Replaced by new token';
        }
    }

    // Create new token
    $tokens['tokens'][$token] = [
        'reference_code' => $referenceCode,
        'vendor_id' => $vendorId,
        'created_at' => date('c'),
        'created_by' => getCurrentAdminName() ?? 'System',
        'revoked' => false,
        'confirmed_at' => null,
        'downloads' => []
    ];

    saveTokens($tokens, $tokensFile);

    return $token;
}

function revokeToken($token, $tokensFile, $reason = 'Manual revocation') {
    $tokens = loadTokens($tokensFile);

    if (isset($tokens['tokens'][$token])) {
        $tokens['tokens'][$token]['revoked'] = true;
        $tokens['tokens'][$token]['revoked_at'] = date('c');
        $tokens['tokens'][$token]['revoked_reason'] = $reason;
        saveTokens($tokens, $tokensFile);
        return true;
    }

    return false;
}

function getActiveTokenForOrder($referenceCode, $tokensFile) {
    $tokens = loadTokens($tokensFile);

    foreach ($tokens['tokens'] as $token => $tokenData) {
        if ($tokenData['reference_code'] === $referenceCode && empty($tokenData['revoked'])) {
            // Check if not expired (7 days)
            $createdAt = strtotime($tokenData['created_at']);
            $expiresAt = $createdAt + (7 * 24 * 60 * 60);
            if (time() < $expiresAt) {
                return $token;
            }
        }
    }

    return null;
}
