<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Error handling function
function sendError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// Function to parse CSV and convert to pricing format
function parsePricingCSV($filename) {
    if (!file_exists($filename)) {
        sendError("Pricing file not found: $filename", 404);
    }
    
    $handle = fopen($filename, 'r');
    if (!$handle) {
        sendError("Cannot read pricing file: $filename", 500);
    }
    
    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        sendError("Invalid CSV format in: $filename", 400);
    }
    
    // Clean headers (remove BOM, trim whitespace, convert to lowercase)
    $headers = array_map(function($header) {
        return strtolower(trim(str_replace("\xEF\xBB\xBF", '', $header)));
    }, $headers);
    
    // Validate required columns exist
    $required_columns = ['min', 'max', 'early', 'standard', '3days', '2days', 'nextday', 'sameday'];
    foreach ($required_columns as $required) {
        if (!in_array($required, $headers)) {
            fclose($handle);
            sendError("Missing required column '$required' in: $filename", 400);
        }
    }
    
    $pricing_data = [];
    
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) !== count($headers)) {
            continue; // Skip malformed rows
        }
        
        $row_data = [];
        for ($i = 0; $i < count($headers); $i++) {
            $column = $headers[$i];
            $value = trim($row[$i]);
            
            // Convert to appropriate data type
            if (in_array($column, ['min', 'max'])) {
                $row_data[$column] = (int)$value;
            } else {
                $row_data[$column] = is_numeric($value) ? (float)$value : $value;
            }
        }
        
        // Only add row if it has required fields
        if (isset($row_data['min']) && isset($row_data['max'])) {
            $pricing_data[] = $row_data;
        }
    }
    
    fclose($handle);
    return $pricing_data;
}

try {
    // Get material type from query parameter
    $material = isset($_GET['material']) ? $_GET['material'] : 'both';
    
    $response = ['success' => true, 'data' => []];
    
    if ($material === 'poster' || $material === 'both') {
        $response['data']['poster'] = parsePricingCSV('Poster Paper Pricing.csv');
    }
    
    if ($material === 'fabric' || $material === 'both') {
        $response['data']['fabric'] = parsePricingCSV('Fabric Pricing.csv');
    }
    
    // Add cache headers (cache for 5 minutes)
    header('Cache-Control: public, max-age=300');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', max(
        file_exists('Poster Paper Pricing.csv') ? filemtime('Poster Paper Pricing.csv') : 0,
        file_exists('Fabric Pricing.csv') ? filemtime('Fabric Pricing.csv') : 0
    )) . ' GMT');
    
    echo json_encode($response);
    
} catch (Exception $e) {
    sendError('Server error: ' . $e->getMessage(), 500);
}
?>