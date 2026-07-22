<?php
/**
 * Chemical Room Security System - Get Statistics Endpoint
 * 
 * AJAX endpoint to retrieve system statistics and detection metrics.
 * 
 * Request Method: GET
 * Query Parameters:
 *   - period (optional): Time period for stats (today, week, month, all) (default: today)
 * 
 * Response:
 * {
 *     "success": true,
 *     "message": "Statistics retrieved successfully",
 *     "data": {
 *         "total_detections": 150,
 *         "detections_today": 12,
 *         "detections_this_week": 45,
 *         "detections_this_month": 120,
 *         "last_detection": "2024-01-15 14:30:45",
 *         "average_per_day": 5.2,
 *         "peak_hour": 14
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

// Get period parameter
$period = isset($_GET['period']) ? sanitizeInput($_GET['period']) : 'today';

// Validate period
$validPeriods = ['today', 'week', 'month', 'all'];
if (!in_array($period, $validPeriods, true)) {
    sendErrorResponse('Invalid period. Must be: today, week, month, or all.', 400);
}

// Get database connection
$connection = getDBConnection();

// Get total detections
$totalSql = "SELECT COUNT(*) as total FROM detections";
$totalStmt = $connection->prepare($totalSql);

if (!$totalStmt) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to prepare statement: ' . $connection->error, 500);
}

if (!$totalStmt->execute()) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to get total count: ' . $totalStmt->error, 500);
}

$totalResult = $totalStmt->get_result();
$totalRow = $totalResult->fetch_assoc();
$totalDetections = (int)$totalRow['total'];
$totalStmt->close();

// Get today's detections
$todaySql = "SELECT COUNT(*) as total FROM detections WHERE DATE(timestamp) = CURDATE()";
$todayStmt = $connection->prepare($todaySql);

if (!$todayStmt) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to prepare statement: ' . $connection->error, 500);
}

if (!$todayStmt->execute()) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to get today count: ' . $todayStmt->error, 500);
}

$todayResult = $todayStmt->get_result();
$todayRow = $todayResult->fetch_assoc();
$detectionsToday = (int)$todayRow['total'];
$todayStmt->close();

// Get this week's detections
$weekSql = "SELECT COUNT(*) as total FROM detections WHERE WEEK(timestamp) = WEEK(NOW()) AND YEAR(timestamp) = YEAR(NOW())";
$weekStmt = $connection->prepare($weekSql);

if (!$weekStmt) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to prepare statement: ' . $connection->error, 500);
}

if (!$weekStmt->execute()) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to get week count: ' . $weekStmt->error, 500);
}

$weekResult = $weekStmt->get_result();
$weekRow = $weekResult->fetch_assoc();
$detectionsWeek = (int)$weekRow['total'];
$weekStmt->close();

// Get this month's detections
$monthSql = "SELECT COUNT(*) as total FROM detections WHERE MONTH(timestamp) = MONTH(NOW()) AND YEAR(timestamp) = YEAR(NOW())";
$monthStmt = $connection->prepare($monthSql);

if (!$monthStmt) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to prepare statement: ' . $connection->error, 500);
}

if (!$monthStmt->execute()) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to get month count: ' . $monthStmt->error, 500);
}

$monthResult = $monthStmt->get_result();
$monthRow = $monthResult->fetch_assoc();
$detectionsMonth = (int)$monthRow['total'];
$monthStmt->close();

// Get last detection
$lastSql = "SELECT timestamp FROM detections ORDER BY timestamp DESC LIMIT 1";
$lastStmt = $connection->prepare($lastSql);

if (!$lastStmt) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to prepare statement: ' . $connection->error, 500);
}

if (!$lastStmt->execute()) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to get last detection: ' . $lastStmt->error, 500);
}

$lastResult = $lastStmt->get_result();
$lastRow = $lastResult->fetch_assoc();
$lastDetection = $lastRow ? $lastRow['timestamp'] : null;
$lastStmt->close();

// Get average detections per day
$dayCountSql = "SELECT COUNT(DISTINCT DATE(timestamp)) as days FROM detections";
$dayCountStmt = $connection->prepare($dayCountSql);

if (!$dayCountStmt) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to prepare statement: ' . $connection->error, 500);
}

if (!$dayCountStmt->execute()) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to get day count: ' . $dayCountStmt->error, 500);
}

$dayCountResult = $dayCountStmt->get_result();
$dayCountRow = $dayCountResult->fetch_assoc();
$daysWithDetections = (int)$dayCountRow['days'];
$dayCountStmt->close();

$averagePerDay = $daysWithDetections > 0 ? round($totalDetections / $daysWithDetections, 2) : 0;

// Get peak hour
$peakHourSql = "SELECT HOUR(timestamp) as hour, COUNT(*) as count FROM detections GROUP BY HOUR(timestamp) ORDER BY count DESC LIMIT 1";
$peakHourStmt = $connection->prepare($peakHourSql);

if (!$peakHourStmt) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to prepare statement: ' . $connection->error, 500);
}

if (!$peakHourStmt->execute()) {
    closeDBConnection($connection);
    sendErrorResponse('Failed to get peak hour: ' . $peakHourStmt->error, 500);
}

$peakHourResult = $peakHourStmt->get_result();
$peakHourRow = $peakHourResult->fetch_assoc();
$peakHour = $peakHourRow ? (int)$peakHourRow['hour'] : null;
$peakHourStmt->close();

closeDBConnection($connection);

// Send success response
sendSuccessResponse(
    [
        'total_detections' => $totalDetections,
        'detections_today' => $detectionsToday,
        'detections_this_week' => $detectionsWeek,
        'detections_this_month' => $detectionsMonth,
        'last_detection' => $lastDetection,
        'formatted_last_detection' => $lastDetection ? formatDateTime($lastDetection) : 'Never',
        'average_per_day' => $averagePerDay,
        'peak_hour' => $peakHour
    ],
    'Statistics retrieved successfully',
    200
);

?>
