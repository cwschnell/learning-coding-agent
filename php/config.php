<?php
declare(strict_types=1);

/* ============================================================
   Configuration and utility functions for PDF batch processing
   ============================================================ */

/**
 * Get environment variable with default value
 */
function envv(string $key, string $default = ''): string {
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

/**
 * Load available companies configuration
 */
function load_companies(): array {
    // Example configuration - replace with actual company data source
    return [
        'company1' => [
            'name' => 'Company 1',
            'db_host' => envv('DB_HOST', 'localhost'),
            'db_name' => envv('DB_NAME_1', 'company1_db'),
            'db_user' => envv('DB_USER', 'root'),
            'db_pass' => envv('DB_PASS', ''),
            'dir' => envv('COMPANY1_DIR', '/var/data/company1')
        ],
        'company2' => [
            'name' => 'Company 2',
            'db_host' => envv('DB_HOST', 'localhost'),
            'db_name' => envv('DB_NAME_2', 'company2_db'),
            'db_user' => envv('DB_USER', 'root'),
            'db_pass' => envv('DB_PASS', ''),
            'dir' => envv('COMPANY2_DIR', '/var/data/company2')
        ]
    ];
}

/**
 * Select a company and store in session
 */
function select_company(string $companyKey): void {
    $companies = load_companies();
    
    if (!isset($companies[$companyKey])) {
        throw new Exception("Empresa não encontrada: " . $companyKey);
    }
    
    $company = $companies[$companyKey];
    
    // Test database connection
    $mysqli = new mysqli(
        $company['db_host'],
        $company['db_user'],
        $company['db_pass'],
        $company['db_name']
    );
    
    if ($mysqli->connect_error) {
        throw new Exception("Erro de conexão: " . $mysqli->connect_error);
    }
    
    $mysqli->close();
    
    // Store in session
    $_SESSION['company_key'] = $companyKey;
    $_SESSION['company'] = $company;
    $_SESSION['company_db'] = $company['db_name'];
    $_SESSION['company_dir'] = $company['dir'];
}

/**
 * Get current company from session
 */
function current_company(): ?array {
    if (!isset($_SESSION['company']) || !is_array($_SESSION['company'])) {
        return null;
    }
    
    // Add key to the company array for easy access
    $company = $_SESSION['company'];
    $company['key'] = $_SESSION['company_key'] ?? '';
    $company['db'] = $_SESSION['company_db'] ?? '';
    
    return $company;
}

/**
 * Get database connection for current company
 */
function db_company(): mysqli {
    $company = current_company();
    
    if (!$company) {
        throw new Exception("Nenhuma empresa selecionada");
    }
    
    $mysqli = new mysqli(
        $company['db_host'],
        $company['db_user'],
        $company['db_pass'],
        $company['db_name']
    );
    
    if ($mysqli->connect_error) {
        throw new Exception("Erro de conexão ao banco: " . $mysqli->connect_error);
    }
    
    return $mysqli;
}

/**
 * Get company paths configuration
 */
function company_paths(): array {
    $company = current_company();
    
    if (!$company) {
        return [];
    }
    
    $baseDir = $company['dir'] ?? '';
    
    return [
        'base_dir' => $baseDir,
        'pdf_files_copies' => $baseDir ? rtrim($baseDir, '/\\') . '/pdf_files_copies' : '',
        'temp_dir' => sys_get_temp_dir(),
    ];
}

/**
 * Initialize database tables if they don't exist
 */
function init_database_tables(mysqli $db): void {
    // Create pdf_files table
    $sql = "CREATE TABLE IF NOT EXISTS pdf_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_name VARCHAR(255) NOT NULL UNIQUE,
        subject VARCHAR(500),
        file_data LONGBLOB,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_subject (subject),
        INDEX idx_file_name (file_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!$db->query($sql)) {
        throw new Exception("Erro ao criar tabela pdf_files: " . $db->error);
    }
    
    // Create pdf_keywords table
    $sql = "CREATE TABLE IF NOT EXISTS pdf_keywords (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_name VARCHAR(255) NOT NULL,
        sum_pt TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_file_name (file_name),
        INDEX idx_file_name (file_name),
        FOREIGN KEY (file_name) REFERENCES pdf_files(file_name) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!$db->query($sql)) {
        throw new Exception("Erro ao criar tabela pdf_keywords: " . $db->error);
    }
}

/**
 * Create sample data for testing (optional)
 */
function create_sample_data(mysqli $db): void {
    // Check if data already exists
    $result = $db->query("SELECT COUNT(*) as count FROM pdf_files");
    $row = $result->fetch_assoc();
    
    if ($row['count'] > 0) {
        return; // Data already exists
    }
    
    // Insert sample PDF files
    $sampleFiles = [
        'document1_page_1.pdf',
        'document2_page_1.pdf',
        'document3_page_1.pdf'
    ];
    
    foreach ($sampleFiles as $fileName) {
        // Insert into pdf_files (with dummy BLOB data)
        $stmt = $db->prepare("INSERT INTO pdf_files (file_name, subject, file_data) VALUES (?, ?, ?)");
        $subject = $fileName;
        $dummyData = '%PDF-1.4 dummy data'; // Placeholder PDF data
        $stmt->bind_param('ssb', $fileName, $subject, $dummyData);
        $stmt->execute();
        $stmt->close();
        
        // Insert corresponding entry in pdf_keywords
        $stmt = $db->prepare("INSERT INTO pdf_keywords (file_name, sum_pt) VALUES (?, NULL)");
        $stmt->bind_param('s', $fileName);
        $stmt->execute();
        $stmt->close();
    }
}

/* ============================================================
   Auto-initialization (optional - uncomment if needed)
   ============================================================ */

/*
// Auto-create tables when config is loaded
if (isset($_SESSION['company']) && !empty($_SESSION['company'])) {
    try {
        $db = db_company();
        init_database_tables($db);
        create_sample_data($db);
        $db->close();
    } catch (Exception $e) {
        // Silently fail during auto-init to avoid breaking the application
        error_log("Auto-init failed: " . $e->getMessage());
    }
}
*/
?>