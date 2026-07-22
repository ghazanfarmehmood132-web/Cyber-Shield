<?php
/**
 * Chemical Room Security System - Clear Detection History Endpoint
 * 
 * AJAX endpoint to clear all detection records from the database.
 * Requires confirmation parameter to prevent accidental deletion.
 * 
 * Request Method: POST
 * Content-Type: application/json
 * 
 * Request Body:
 * {
 *     "confirm": true,
 *     "password": "admin_password" (optional, for additional security)
 * }
 * 
 * Response:
 * {
 *     "success": true,
 *     "message": "Detection history cleared successfully",
 *     "data": {
 *         "records_deleted": 150
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
$validation = validateRequiredFields($input, ['confirm']);
if (!$validation['valid']) {
    sendErrorResponse($validation['message'], 400);
}

// Validate confirmation
$confirm = filter_var($input['confirm'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

if ($confirm !== true) {
    sendErrorResponse('Confirmation required to clear history. Set confirm to true.', 400);
}

// Optional: Validate password if provided (for additional security)
if (isset($input['password'])) {
    // This is a simple example. In production, use proper authentication
    $expectedPassword = 'admin'; // Change this to your actual password
    if ($input['password'] !== $expectedPassword) {
        sendErrorResponse('Invalid password.', 403);
    }
}

// Get database connection
$connection = getDBConnection();

// Get count of records before deletion
$countSql = "SELECT COUNT(*) as total FROM detections";
$countStmt = $connection->prepare($countSql);

if (!$countStmt) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to prepare count statement: ' . $connection->error, 500);
}

if (!$countStmt->execute()) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to count records: ' . $countStmt->error, 500);
}

$countResult = $countStmt->get_result();
$countRow = $countResult->fetch_assoc();
$recordsDeleted = (int)$countRow['total'];
$countStmt->close();

// Delete all records
$sql = "DELETE FROM detections";
$stmt = $connection->prepare($sql);

if (!$stmt) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to prepare delete statement: ' . $connection->error, 500);
}

if (!$stmt->execute()) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to clear history: ' . $stmt->error, 500);
}

$stmt->close();
closeDBConnection($connection);

// Send success response
sendSuccessResponse(
    [
        'records_deleted' => $recordsDeleted
    ],
    'Detection history cleared successfully',
    200
);

?>
