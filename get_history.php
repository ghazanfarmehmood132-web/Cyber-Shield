<?php
/**
 * Chemical Room Security System - Get Detection History Endpoint
 * 
 * AJAX endpoint to retrieve detection history from the database.
 * Supports filtering by date range and limiting results.
 * 
 * Request Method: GET
 * Query Parameters:
 *   - limit (optional): Maximum number of records to return (default: 50)
 *   - offset (optional): Number of records to skip (default: 0)
 *   - start_date (optional): Filter detections from this date (YYYY-MM-DD)
 *   - end_date (optional): Filter detections until this date (YYYY-MM-DD)
 * 
 * Response:
 * {
 *     "success": true,
 *     "message": "Detection history retrieved successfully",
 *     "data": {
 *         "total": 150,
 *         "returned": 10,
 *         "detections": [
 *             {
 *                 "id": 1,
 *                 "timestamp": "2024-01-15 14:30:45",
 *                 "formatted_time": "Jan 15, 2024 at 02:30:45 PM",
 *                 "time_ago": "2 hours ago"
 *             },
 *             ...
 *         ]
 *     }
 * }
 */

require_once 'config.php';
require_once 'utilities.php';

// Set CORS headers and handle preflight
setCORSHeaders();
handlePreflight();

// Validate request method
if (!validateRequestMethod('GET')) {
    sendErrorResponse('Invalid request method. GET required.', 405);
}

// Get and validate query parameters
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$startDate = isset($_GET['start_date']) ? sanitizeInput($_GET['start_date']) : null;
$endDate = isset($_GET['end_date']) ? sanitizeInput($_GET['end_date']) : null;

// Validate limit (prevent excessive queries)
if ($limit < 1 || $limit > 1000) {
    $limit = 50;
}

if ($offset < 0) {
    $offset = 0;
}

// Get database connection
$connection = getDBConnection();

// Build base query
$sql = "SELECT id, timestamp FROM detections WHERE 1=1";
$params = [];
$types = '';

// Add date range filters if provided
if ($startDate) {
    // Validate date format
    if (DateTime::createFromFormat('Y-m-d', $startDate) === false) {
        closeDBConnection($connection);
        sendErrorResponse('Invalid start_date format. Use YYYY-MM-DD.', 400);
    }
    $sql .= " AND DATE(timestamp) >= ?";
    $params[] = $startDate;
    $types .= 's';
}

if ($endDate) {
    // Validate date format
    if (DateTime::createFromFormat('Y-m-d', $endDate) === false) {
        closeDBConnection($connection);
        sendErrorResponse('Invalid end_date format. Use YYYY-MM-DD.', 400);
    }
    $sql .= " AND DATE(timestamp) <= ?";
    $params[] = $endDate;
    $types .= 's';
}

// Add ordering and pagination
$sql .= " ORDER BY timestamp DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

// Prepare and execute statement
$stmt = $connection->prepare($sql);

if (!$stmt) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to prepare statement: ' . $connection->error, 500);
}

// Bind parameters if any
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to retrieve history: ' . $stmt->error, 500);
}

$result = $stmt->get_result();
$detections = [];

while ($row = $result->fetch_assoc()) {
    $detections[] = [
        'id' => (int)$row['id'],
        'timestamp' => $row['timestamp'],
        'formatted_time' => formatDateTime($row['timestamp']),
        'time_ago' => getTimeAgo($row['timestamp'])
    ];
}

$stmt->close();

// Get total count of detections
$countSql = "SELECT COUNT(*) as total FROM detections WHERE 1=1";
$countParams = [];
$countTypes = '';

if ($startDate) {
    $countSql .= " AND DATE(timestamp) >= ?";
    $countParams[] = $startDate;
    $countTypes .= 's';
}

if ($endDate) {
    $countSql .= " AND DATE(timestamp) <= ?";
    $countParams[] = $endDate;
    $countTypes .= 's';
}

$countStmt = $connection->prepare($countSql);

if (!$countStmt) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to prepare count statement: ' . $connection->error, 500);
}

if (!empty($countParams)) {
    $countStmt->bind_param($countTypes, ...$countParams);
}

if (!$countStmt->execute()) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to count records: ' . $countStmt->error, 500);
}

$countResult = $countStmt->get_result();
$countRow = $countResult->fetch_assoc();
$totalCount = (int)$countRow['total'];
$countStmt->close();

closeDBConnection($connection);

// Send success response
sendSuccessResponse(
    [
        'total' => $totalCount,
        'returned' => count($detections),
        'limit' => $limit,
        'offset' => $offset,
        'detections' => $detections
    ],
    'Detection history retrieved successfully',
    200
);

?>
