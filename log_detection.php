<?php
/**
 * Chemical Room Security System - Log Detection Endpoint
 * 
 * AJAX endpoint to log when a person is detected in the chemical room.
 * Receives detection data from the frontend and stores it in the database.
 * 
 * Request Method: POST
 * Content-Type: application/json
 * 
 * Request Body:
 * {
 *     "person_detected": true,
 *     "confidence": 0.95
 * }
 * 
 * Response:
 * {
 *     "success": true,
 *     "message": "Detection logged successfully",
 *     "data": {
 *         "detection_id": 1,
 *         "timestamp": "2024-01-15 14:30:45"
 *     }
 * }
 */

require_once 'config.php';
require_once 'utilities.php';

// Set CORS headers and handle preflight
setCORSHeaders();
handlePreflight();

// Validate request method
if (!validateRequestMethod('POST')) {
    sendErrorResponse('Invalid request method. POST required.', 405);
}

// Get JSON input from request body
$input = getJSONInput();

// Validate required fields
$validation = validateRequiredFields($input, ['person_detected']);
if (!$validation['valid']) {
    sendErrorResponse($validation['message'], 400);
}

// Extract and sanitize input
$personDetected = filter_var($input['person_detected'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
$confidence = isset($input['confidence']) ? floatval($input['confidence']) : null;

// Validate person_detected is a boolean
if ($personDetected === null) {
    sendErrorResponse('Invalid value for person_detected. Boolean required.', 400);
}

// Only log if a person was actually detected
if (!$personDetected) {
    sendSuccessResponse(
        ['message' => 'No detection to log'],
        'No person detected',
        200
    );
}

// Get database connection
$connection = getDBConnection();

// Prepare SQL statement to insert detection record
$sql = "INSERT INTO detections (timestamp) VALUES (NOW())";
$stmt = $connection->prepare($sql);

if (!$stmt) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to prepare statement: ' . $connection->error, 500);
}

// Execute statement
if (!$stmt->execute()) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to log detection: ' . $stmt->error, 500);
}

// Get the ID of the inserted record
$detectionId = $stmt->insert_id;
$stmt->close();

// Retrieve the inserted record to get the exact timestamp
$sql = "SELECT id, timestamp FROM detections WHERE id = ?";
$stmt = $connection->prepare($sql);

if (!$stmt) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to prepare statement: ' . $connection->error, 500);
}

$stmt->bind_param('i', $detectionId);

if (!$stmt->execute()) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to retrieve detection: ' . $stmt->error, 500);
}

$result = $stmt->get_result();
$detection = $result->fetch_assoc();
$stmt->close();
closeDBConnection($connection);

// Send success response
sendSuccessResponse(
    [
        'detection_id' => (int)$detection['id'],
        'timestamp' => $detection['timestamp'],
        'confidence' => $confidence
    ],
    'Detection logged successfully',
    201
);

?>
