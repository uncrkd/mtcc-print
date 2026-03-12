<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

try {
    // Get JSON input
    $input = file_get_contents('php://input');
    if (empty($input)) {
        throw new Exception('No data received');
    }
    
    $eventsData = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data: ' . json_last_error_msg());
    }
    
    // Validate required structure
    if (!isset($eventsData['active']) || !is_array($eventsData['active'])) {
        throw new Exception('Invalid data structure: missing active events array');
    }
    
    if (!isset($eventsData['archived']) || !is_array($eventsData['archived'])) {
        throw new Exception('Invalid data structure: missing archived events array');
    }
    
    // Validate each event has required fields
    $requiredFields = ['id', 'acronym', 'name', 'dates', 'endDate', 'fullName'];
    
    foreach (['active', 'archived'] as $section) {
        foreach ($eventsData[$section] as $index => $event) {
            foreach ($requiredFields as $field) {
                if (!isset($event[$field]) || empty(trim($event[$field]))) {
                    throw new Exception("Missing required field '$field' in $section event at index $index");
                }
            }
            
            // Validate date format
            $date = DateTime::createFromFormat('Y-m-d', $event['endDate']);
            if (!$date || $date->format('Y-m-d') !== $event['endDate']) {
                throw new Exception("Invalid end date format in $section event at index $index. Expected YYYY-MM-DD format.");
            }
            
            // Ensure orderCount exists and is numeric
            if (!isset($event['orderCount'])) {
                $eventsData[$section][$index]['orderCount'] = 0;
            } else {
                $eventsData[$section][$index]['orderCount'] = (int)$event['orderCount'];
            }
        }
    }
    
    // Check for duplicate acronyms
    $allEvents = array_merge($eventsData['active'], $eventsData['archived']);
    $acronyms = array_column($allEvents, 'acronym');
    if (count($acronyms) !== count(array_unique($acronyms))) {
        throw new Exception('Duplicate event acronyms found. Each event must have a unique acronym.');
    }
    
    // Check for duplicate IDs
    $ids = array_column($allEvents, 'id');
    if (count($ids) !== count(array_unique($ids))) {
        throw new Exception('Duplicate event IDs found. Each event must have a unique ID.');
    }
    
    // Update metadata
    if (!isset($eventsData['metadata'])) {
        $eventsData['metadata'] = [];
    }
    
    $eventsData['metadata']['lastUpdated'] = date('Y-m-d H:i:s');
    $eventsData['metadata']['version'] = '1.0';
    
    // Create admin directory if it doesn't exist
    $adminDir = 'admin';
    if (!is_dir($adminDir)) {
        if (!mkdir($adminDir, 0755, true)) {
            throw new Exception('Failed to create admin directory');
        }
    }
    
    // Create backup of existing file
    $eventsFile = 'events.json';
    if (file_exists($eventsFile)) {
        $backupFile = 'events-backup-' . date('Y-m-d-H-i-s') . '.json';
        if (!copy($eventsFile, $backupFile)) {
            error_log('Warning: Failed to create backup file');
        }
        
        // Clean up old backups (keep only last 10)
        $backupPattern = 'events-backup-*.json';
        $backupFiles = glob($backupPattern);
        if (count($backupFiles) > 10) {
            usort($backupFiles, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            $filesToDelete = array_slice($backupFiles, 0, count($backupFiles) - 10);
            foreach ($filesToDelete as $file) {
                unlink($file);
            }
        }
    }
    
    // Save events data
    $jsonData = json_encode($eventsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($jsonData === false) {
        throw new Exception('Failed to encode events data as JSON');
    }
    
    if (file_put_contents($eventsFile, $jsonData) === false) {
        throw new Exception('Failed to write events data to file');
    }
    
    // Log the save operation
    error_log('Events data saved successfully. Active: ' . count($eventsData['active']) . ', Archived: ' . count($eventsData['archived']));
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Events saved successfully',
        'data' => [
            'activeCount' => count($eventsData['active']),
            'archivedCount' => count($eventsData['archived']),
            'lastUpdated' => $eventsData['metadata']['lastUpdated']
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Error saving events: ' . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>