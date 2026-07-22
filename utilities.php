<?php
/**
 * Chemical Room Security System - Utility Functions
 * 
 * This file contains helper functions for validation, sanitization, and response handling.
 */

/**
 * Set CORS headers to allow frontend requests
 */
function setCORSHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json; charset=utf-8');
}

/**
 * Handle preflight OPTIONS requests
 */
function handlePreflight() {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

/**
 * Validate that the request method matches expected method(s)
 * 
 * @param string|array $expectedMethods Expected HTTP method(s)
 * @return bool True if method matches, false otherwise
 */
function validateRequestMethod($expectedMethods) {
    if (is_string($expectedMethods)) {
        $expectedMethods = [$expectedMethods];
    }
    
    return in_array($_SERVER['REQUEST_METHOD'], $expectedMethods, true);
}

/**
 * Get JSON input from request body
 * 
 * @return array Decoded JSON data or empty array if invalid
 */
function getJSONInput() {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    return is_array($data) ? $data : [];
}

/**
 * Validate required fields in data array
 * 
 * @param array $data Data to validate
 * @param array $requiredFields Required field names
 * @return array Array with 'valid' boolean and 'message' string
 */
function validateRequiredFields($data, $requiredFields) {
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            return [
                'valid' => false,
                'message' => "Missing required field: {$field}"
            ];
        }
    }
    
    return ['valid' => true, 'message' => ''];
}

/**
 * Sanitize string input
 * 
 * @param string $input String to sanitize
 * @return string Sanitized string
 */
function sanitizeInput($input) {
    return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
}

/**
 * Send JSON success response
 * 
 * @param mixed $data Data to include in response
 * @param string $message Optional success message
 * @param int $statusCode HTTP status code
 */
function sendSuccessResponse($data = null, $message = 'Success', $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

/**
 * Send JSON error response
 * 
 * @param string $message Error message
 * @param int $statusCode HTTP status code
 * @param mixed $data Optional additional data
 */
function sendErrorResponse($message = 'An error occurred', $statusCode = 400, $data = null) {
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

/**
 * Format datetime for display
 * 
 * @param string $datetime DateTime string from database
 * @return string Formatted datetime string
 */
function formatDateTime($datetime) {
    $date = new DateTime($datetime);
    return $date->format('M d, Y \a\t h:i:s A');
}

/**
 * Get time ago string (e.g., "5 minutes ago")
 * 
 * @param string $datetime DateTime string from database
 * @return string Time ago string
 */
function getTimeAgo($datetime) {
    $date = new DateTime($datetime);
    $now = new DateTime();
    $interval = $now->diff($date);
    
    if ($interval->y > 0) {
        return $interval->y . ' year' . ($interval->y > 1 ? 's' : '') . ' ago';
    } elseif ($interval->m > 0) {
        return $interval->m . ' month' . ($interval->m > 1 ? 's' : '') . ' ago';
    } elseif ($interval->d > 0) {
        return $interval->d . ' day' . ($interval->d > 1 ? 's' : '') . ' ago';
    } elseif ($interval->h > 0) {
        return $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
    } elseif ($interval->i > 0) {
        return $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
    } else {
        return 'Just now';
    }
}

?>
