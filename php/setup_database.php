<?php
declare(strict_types=1);

/* ============================================================
   Database Setup Script for PDF Batch Processing
   ============================================================ */

require_once __DIR__ . '/config.php';

echo "PDF Batch Processing - Database Setup\n";
echo "=====================================\n\n";

try {
    $companies = load_companies();
    
    if (empty($companies)) {
        echo "No companies configured. Please check your configuration.\n";
        exit(1);
    }
    
    foreach ($companies as $key => $company) {
        echo "Setting up database for company: {$key}\n";
        echo "Database: {$company['db_name']}\n";
        
        try {
            // Connect to MySQL server (without specific database)
            $mysqli = new mysqli(
                $company['db_host'],
                $company['db_user'],
                $company['db_pass']
            );
            
            if ($mysqli->connect_error) {
                throw new Exception("Connection failed: " . $mysqli->connect_error);
            }
            
            // Create database if it doesn't exist
            $dbName = $mysqli->real_escape_string($company['db_name']);
            $sql = "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
            
            if (!$mysqli->query($sql)) {
                throw new Exception("Error creating database: " . $mysqli->error);
            }
            
            // Select the database
            $mysqli->select_db($company['db_name']);
            
            // Initialize tables
            init_database_tables($mysqli);
            
            // Create sample data
            create_sample_data($mysqli);
            
            $mysqli->close();
            
            echo "✓ Database setup completed successfully\n\n";
            
        } catch (Exception $e) {
            echo "✗ Error setting up database: " . $e->getMessage() . "\n\n";
        }
    }
    
    echo "Database setup completed.\n";
    echo "\nNext steps:\n";
    echo "1. Configure your web server to serve PHP files\n";
    echo "2. Set environment variables (see .env.example)\n";
    echo "3. Install poppler-utils for PDF processing: apt-get install poppler-utils\n";
    echo "4. Access the application via: http://your-server/php/extract_api_batch.php\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>