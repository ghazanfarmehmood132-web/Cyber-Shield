<?php
/**
 * Chemical Room Security System - Database Configuration
 * 
 * This file contains database connection parameters for XAMPP.
 * Modify these settings to match your local MySQL setup.
 */

// Database connection parameters
define('DB_HOST', 'localhost');      // MySQL server host
define('DB_USER', 'root');           // MySQL username (default for XAMPP)
define('DB_PASS', '');               // MySQL password (empty by default in XAMPP)
define('DB_NAME', 'security_system'); // Database name

// Additional settings
define('CHARSET', 'utf8mb4');        // Character set for database

/**
 * Create MySQLi connection with error handling
 */
function getDBConnection() {
    $connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // Check for connection errors
    if ($connection->connect_error) {
        http_response_code(500);
        die(json_encode([
            'success' => false,
            'message' => 'Database connection failed: ' . $connection->connect_error
        ]));
    }
    
    // Set character set to utf8mb4
    $connection->set_charset(CHARSET);
    
    return $connection;
}

/**
 * Close database connection
 */
function closeDBConnection($connection) {
    if ($connection) {
        $connection->close();
    }
}

?>
