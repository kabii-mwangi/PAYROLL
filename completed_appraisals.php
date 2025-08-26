```php
<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Assuming Dompdf is installed via composer: composer require dompdf/dompdf
// If not, download from https://github.com/dompdf/dompdf and adjust the require path accordingly
require_once 'vendor/autoload.php';
use Dompdf\Dompdf;

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'config.php';
$conn = getConnection();

// Get current user from session
$user = [
    'first_name' => isset($_SESSION['user_name']) ? explode(' ', $_SESSION['user_name'])[0] : 'User',
    'last_name' => isset($_SESSION['user_name']) ? (explode(' ', $_SESSION['user_name'])[1] ?? '') : '',
    'role' => $_SESSION['user_role'] ?? 'guest',
    'id' => $_SESSION['user_id'],
    'employee_id' => $_SESSION['employee_id'] ?? null
];

// Permission checking function
function hasPermission($requiredRole) {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    $roleHierarchy = [
        'super_admin' => 5,
        'hr_manager' => 4,
        'managing_director' => 4,
        'dept_head' => 3,
        'section_head' => 2,
        'manager' => 1,
        'officer' => 0,
        'employee' => 0
    ];
    
    $userLevel = $roleHierarchy[$_SESSION['user_role']] ?? 0;
    $requiredLevel = $roleHierarchy[$requiredRole] ?? 0;
    
    return $userLevel >= $requiredLevel;
}

// Get current user's employee record
$userEmployeeQuery = "SELECT e.* FROM employees e 
                     LEFT JOIN users u ON u.employee_id = e.employee_id 
                     WHERE u.id = ?";
$stmt = $conn->prepare($userEmployeeQuery);
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$currentEmployee = $stmt->get_result()->fetch_assoc();

// Export functions
function exportToPDF($appraisal, $scores, $totalScore) {
    $html = generateAppraisalHTML($appraisal, $scores, $totalScore);
    
    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream("appraisal_" . $appraisal['emp_id'] . "_" . date('Y-m-d') . ".pdf", ["Attachment" => true]);
    exit();
}

function exportToPrint($appraisal, $scores, $totalScore) {
    $html = generateAppraisalHTML($appraisal, $scores, $totalScore);
    // Add print script
    $html = str_replace('</body>', '<script>window.print();</script></body>', $html);
    echo $html;
    exit();
}

function exportToWord($appraisal, $scores, $totalScore) {
    // Clean any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Start new output buffer
    ob_start();
    
    // Set proper headers for Word document
    header("Content-Type: application/vnd.ms-word; charset=utf-8");
    header("Content-Disposition: attachment; filename=appraisal_" . $appraisal['emp_id'] . "_" . date('Y-m-d') . ".doc");
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    echo generateAppraisalHTML($appraisal, $scores, $totalScore);
    
    // Flush and clean buffer
    ob_end_flush();
    exit();
}

// Improved HTML generation function with supervisor comments and better formatting
function generateAppraisalHTML($appraisal, $scores, $totalScore) {
    // Ensure all variables are properly escaped
    $employee_name = htmlspecialchars($appraisal['first_name'] . ' ' . $appraisal['last_name']);
    $employee_id = htmlspecialchars($appraisal['emp_id']);
    $cycle_name = htmlspecialchars($appraisal['cycle_name']);
    $department = htmlspecialchars($appraisal['department_name'] ?? 'N/A');
    $section = htmlspecialchars($appraisal['section_name'] ?? 'N/A');
    $appraiser_name = htmlspecialchars($appraisal['appraiser_first_name'] . ' ' . $appraisal['appraiser_last_name']);
    
    $html = '<!DOCTYPE html>
<html>
<head>
    <title>Performance Appraisal Report</title>
    <meta charset="utf-8">
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 15px; 
            line-height: 1.3; 
            color: #333;
            font-size: 12px;
        }
        .header { 
            text-align: center; 
            margin-bottom: 20px; 
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
        }
        .header h1 {
            font-size: 18px;
            margin: 0;
        }
        .header h3 {
            font-size: 14px;
            margin: 5px 0 0 0;
            font-weight: normal;
        }
        .employee-info { 
            margin-bottom: 15px; 
        }
        .employee-info h3 {
            font-size: 14px;
            margin-bottom: 8px;
        }
        .info-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 8px 0; 
            font-size: 11px;
        }
        .info-table th, .info-table td { 
            border: 1px solid #ddd; 
            padding: 6px; 
            text-align: left; 
        }
        .info-table th { 
            background-color: #f5f5f5; 
            font-weight: bold; 
            width: 25%;
        }
        .scores-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 15px 0; 
            font-size: 11px;
        }
        .scores-table th, .scores-table td { 
            border: 1px solid #ddd; 
            padding: 6px; 
            text-align: left; 
        }
        .scores-table th { 
            background-color: #f5f5f5; 
            font-weight: bold; 
        }
        .total-score { 
            background-color: #e8f5e9; 
            font-weight: bold; 
        }
        .comments-section { 
            margin-top: 15px; 
            border: 1px solid #ddd; 
            padding: 10px; 
            background: #f9f9f9; 
            font-size: 11px;
        }
        .comments-section h3 {
            font-size: 13px;
            margin: 0 0 8px 0;
        }
        @media print {
            body { margin: 10px; }
            .header h1 { color: #333; }
            .no-print { display: none !important; }
        }
        .footer {
            margin-top: 30px;
            font-size: 10px; 
            color: #666; 
            border-top: 1px solid #ddd; 
            padding-top: 10px;
            text-align: center;
        }
        .print-buttons {
            text-align: center;
            margin-top: 20px;
        }
        .print-buttons button {
            padding: 8px 16px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .print-buttons button.close-btn {
            background: #f44336;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Performance Appraisal Report</h1>
        <h3>' . $cycle_name . '</h3>
    </div>
    
    <div class="employee-info">
        <h3>Employee Information</h3>
        <table class="info-table">
            <tr>
                <th>Employee Name</th>
                <td>' . $employee_name . '</td>
            </tr>
            <tr>
                <th>Employee ID</th>
                <td>' . $employee_id . '</td>
            </tr>
            <tr>
                <th>Department</th>
                <td>' . $department . '</td>
            </tr>
            <tr>
                <th>Section</th>
                <td>' . $section . '</td>
            </tr>
            <tr>
                <th>Appraisal Period</th>
                <td>' . date('M d, Y', strtotime($appraisal['start_date'])) . ' - ' . date('M d, Y', strtotime($appraisal['end_date'])) . '</td>
            </tr>
            <tr>
                <th>Appraiser</th>
                <td>' . $appraiser_name . '</td>
            </tr>
            <tr>
                <th>Submitted Date</th>
                <td>' . date('M d, Y H:i', strtotime($appraisal['submitted_at'])) . '</td>
            </tr>
        </table>
    </div>
    
    <h3 style="font-size: 14px; margin-bottom: 8px;">Performance Scores</h3>
    <table class="scores-table">
        <thead>
            <tr>
                <th>Performance Indicator</th>
                <th>Score</th>
                <th>Max Score</th>
                <th>Percentage</th>
                <th>Comments</th>
            </tr>
        </thead>
        <tbody>';

    // Add scores
    if (!empty($scores)) {
        foreach ($scores as $score) {
            $percentage = ($score['max_score'] > 0) ? ($score['score'] / $score['max_score']) * 100 : 0;
            $indicator_name = htmlspecialchars($score['indicator_name'] ?? 'Performance Indicator');
            $comment = htmlspecialchars($score['appraiser_comment'] ?? '');
            
            $html .= '
            <tr>
                <td>' . $indicator_name . '</td>
                <td>' . intval($score['score']) . '</td>
                <td>' . intval($score['max_score']) . '</td>
                <td>' . number_format($percentage, 1) . '%</td>
                <td>' . $comment . '</td>
            </tr>';
        }
    }
    
    $html .= '
            <tr class="total-score">
                <td colspan="3"><strong>Overall Score</strong></td>
                <td><strong>' . number_format($totalScore, 1) . '%</strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>';

    // Add employee comments if available
    if (!empty($appraisal['employee_comment'])) {
        $employee_comment = nl2br(htmlspecialchars($appraisal['employee_comment']));
        $comment_date = date('M d, Y H:i', strtotime($appraisal['employee_comment_date']));
        
        $html .= '
        <div class="comments-section">
            <h3>Employee Comments</h3>
            <p>' . $employee_comment . '</p>
            <p><small>Commented on: ' . $comment_date . '</small></p>
        </div>';
    }

    // Add supervisor comments if available
    if (!empty($appraisal['supervisors_comment'])) {
        $supervisor_comment = nl2br(htmlspecialchars($appraisal['supervisors_comment']));
        $supervisor_comment_date = date('M d, Y H:i', strtotime($appraisal['supervisors_comment_date']));
        
        $html .= '
        <div class="comments-section">
            <h3>Supervisor Comments</h3>
            <p>' . $supervisor_comment . '</p>
            <p><small>Commented on: ' . $supervisor_comment_date . '</small></p>
        </div>';
    }

    $html .= '
    <div class="footer">
        <p>Generated on: ' . date('M d, Y H:i:s') . '</p>
        <p>HR Management System - Performance Appraisal Report</p>
    </div>
    
    <div class="print-buttons no-print">
        <button onclick="window.print()">Print Now</button>
        <button onclick="window.close()" class="close-btn">Close</button>
    </div>
</body>
</html>';

    return $html;
}

// Handle export requests
if (isset($_POST['export']) && isset($_POST['appraisal_id'])) {
    $appraisal_id = intval($_POST['appraisal_id']);
    $export_type = $_POST['export_type'];
    
    // Validate inputs
    if ($appraisal_id <= 0 || !in_array($export_type, ['pdf', 'word', 'print'])) {
        die('Invalid export parameters');
    }
    
    // Get detailed appraisal data for export
    $exportQuery = "
        SELECT 
            ea.*,
            ac.name as cycle_name,
            ac.start_date,
            ac.end_date,
            e.first_name,
            e.last_name,
            e.employee_id as emp_id,
            d.name as department_name,
            s.name as section_name,
            e_appraiser.first_name as appraiser_first_name,
            e_appraiser.last_name as appraiser_last_name
        FROM employee_appraisals ea
        JOIN employees e ON ea.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN sections s ON e.section_id = s.id
        JOIN appraisal_cycles ac ON ea.appraisal_cycle_id = ac.id
        JOIN employees e_appraiser ON ea.appraiser_id = e_appraiser.id
        WHERE ea.id = ? AND ea.status = 'submitted'
    ";
    
    // Add role-based restrictions
    $exportParamTypes = "i";
    $exportParams = [$appraisal_id];

    if (!hasPermission('hr_manager')) {  // hr_manager, super_admin, managing_director can see all
        if (hasPermission('dept_head')) {
            $exportQuery .= " AND e.department_id = ?";
            $exportParamTypes .= "i";
            $exportParams[] = $currentEmployee['department_id'];
        } elseif (hasPermission('section_head') || hasPermission('manager')) {
            $exportQuery .= " AND e.section_id = ?";
            $exportParamTypes .= "i";
            $exportParams[] = $currentEmployee['section_id'];
        } else {
            $exportQuery .= " AND ea.employee_id = ?";
            $exportParamTypes .= "i";
            $exportParams[] = $currentEmployee['id'];
        }
    }
    
    $exportStmt = $conn->prepare($exportQuery);
    if (!$exportStmt) {
        error_log('Database error in export query: ' . $conn->error);
        die('Database error: Unable to prepare export query');
    }
    $exportStmt->bind_param($exportParamTypes, ...$exportParams);
    
    if (!$exportStmt->execute()) {
        error_log('Query execution failed: ' . $exportStmt->error);
        die('Query execution failed: ' . $exportStmt->error);
    }
    
    $appraisalData = $exportStmt->get_result()->fetch_assoc();
    
    if (!$appraisalData) {
        die('Appraisal not found or access denied');
    }
    
    // Get scores for this appraisal
    $scoresQuery = "
        SELECT 
            as_.*,
            pi.name as indicator_name,
            pi.description as indicator_description,
            pi.max_score
        FROM appraisal_scores as_
        JOIN performance_indicators pi ON as_.performance_indicator_id = pi.id
        WHERE as_.employee_appraisal_id = ?
        ORDER BY pi.max_score DESC, pi.name
    ";
    
    $scoresStmt = $conn->prepare($scoresQuery);
    if (!$scoresStmt) {
        error_log('Database error in scores query: ' . $conn->error);
        die('Database error: Unable to prepare scores query');
    }
    
    $scoresStmt->bind_param("i", $appraisal_id);
    if (!$scoresStmt->execute()) {
        error_log('Query execution failed: ' . $scoresStmt->error);
        die('Query execution failed: ' . $scoresStmt->error);
    }
    
    $scores = $scoresStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Calculate total score without weights
    $total_score = 0;
    $total_max = 0;
    foreach ($scores as $score) {
        if ($score['max_score'] > 0) {
            $total_score += $score['score'];
            $total_max += $score['max_score'];
        }
    }
    $final_percentage = $total_max > 0 ? ($total_score / $total_max) * 100 : 0;
    
    // Handle different export types
    switch ($export_type) {
        case 'pdf':
            exportToPDF($appraisalData, $scores, $final_percentage);
            break;
        case 'word':
            exportToWord($appraisalData, $scores, $final_percentage);
            break;
        case 'print':
            exportToPrint($appraisalData, $scores, $final_percentage);
            break;
        default:
            die('Invalid export type');
    }
}

// Get appraisal cycles for filtering
$cyclesStmt = $conn->prepare("SELECT * FROM appraisal_cycles ORDER BY start_date DESC");
$cyclesStmt->execute();
$cycles = $cyclesStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get employees based on user role
$employees = [];
if (hasPermission('hr_manager')) {  // Covers hr_manager, managing_director, super_admin
    // Can see all employees
    $employeesStmt = $conn->prepare("SELECT id, first_name, last_name, employee_id FROM employees ORDER BY first_name, last_name");
    $employeesStmt->execute();
    $employees = $employeesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
} elseif (hasPermission('dept_head')) {
    // Department Head can see employees in their department
    $employeesStmt = $conn->prepare("SELECT id, first_name, last_name, employee_id FROM employees WHERE department_id = ? ORDER BY first_name, last_name");
    $employeesStmt->bind_param("i", $currentEmployee['department_id']);
    $employeesStmt->execute();
    $employees = $employeesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
} elseif (hasPermission('section_head') || hasPermission('manager')) {
    // Section Head and Manager can see employees in their section
    $employeesStmt = $conn->prepare("SELECT id, first_name, last_name, employee_id FROM employees WHERE section_id = ? ORDER BY first_name, last_name");
    $employeesStmt->bind_param("i", $currentEmployee['section_id']);
    $employeesStmt->execute();
    $employees = $employeesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Filter parameters
$selected_cycle = $_GET['cycle_id'] ?? '';
$selected_employee = $_GET['employee_id'] ?? '';

// Build query based on user permissions and filters
$appraisalsQuery = "
    SELECT 
        ea.*,
        ac.name as cycle_name,
        ac.start_date,
        ac.end_date,
        e.first_name,
        e.last_name,
        e.employee_id as emp_id,
        d.name as department_name,
        s.name as section_name,
        e_appraiser.first_name as appraiser_first_name,
        e_appraiser.last_name as appraiser_last_name
    FROM employee_appraisals ea
    JOIN employees e ON ea.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN sections s ON e.section_id = s.id
    JOIN appraisal_cycles ac ON ea.appraisal_cycle_id = ac.id
    JOIN employees e_appraiser ON ea.appraiser_id = e_appraiser.id
    WHERE ea.status = 'submitted'
";

$queryParams = [];
$paramTypes = "";

// Add filters
if ($selected_cycle) {
    $appraisalsQuery .= " AND ea.appraisal_cycle_id = ?";
    $queryParams[] = $selected_cycle;
    $paramTypes .= "i";
}

// Employee filter based on user role
if (hasPermission('hr_manager')) {  // Covers hr_manager, managing_director, super_admin
    // Can see all appraisals
    if ($selected_employee) {
        $appraisalsQuery .= " AND ea.employee_id = ?";
        $queryParams[] = $selected_employee;
        $paramTypes .= "i";
    }
} elseif (hasPermission('dept_head')) {
    // Department Head can see appraisals for employees in their department
    $appraisalsQuery .= " AND e.department_id = ?";
    $queryParams[] = $currentEmployee['department_id'];
    $paramTypes .= "i";
    
    if ($selected_employee) {
        $appraisalsQuery .= " AND ea.employee_id = ?";
        $queryParams[] = $selected_employee;
        $paramTypes .= "i";
    }
} elseif (hasPermission('section_head') || hasPermission('manager')) {
    // Section Head and Manager can see appraisals for employees in their section
    $appraisalsQuery .= " AND e.section_id = ?";
    $queryParams[] = $currentEmployee['section_id'];
    $paramTypes .= "i";
    
    if ($selected_employee) {
        $appraisalsQuery .= " AND ea.employee_id = ?";
        $queryParams[] = $selected_employee;
        $paramTypes .= "i";
    }
} else {
    // Regular employees/officers can only see their own appraisals
    $appraisalsQuery .= " AND ea.employee_id = ?";
    $queryParams[] = $currentEmployee['id'];
    $paramTypes .= "i";
}

$appraisalsQuery .= " ORDER BY ea.submitted_at DESC, ac.start_date DESC";

$appraisalsStmt = $conn->prepare($appraisalsQuery);
if (!empty($queryParams)) {
    $appraisalsStmt->bind_param($paramTypes, ...$queryParams);
}
$appraisalsStmt->execute();
$appraisals = $appraisalsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get scores for all appraisals
$scores_by_appraisal = [];
if (!empty($appraisals)) {
    $appraisal_ids = array_column($appraisals, 'id');
    $placeholders = str_repeat('?,', count($appraisal_ids) - 1) . '?';
    
    $scoresQuery = "
        SELECT 
            as_.*,
            pi.max_score,
            pi.name as indicator_name
        FROM appraisal_scores as_
        JOIN performance_indicators pi ON as_.performance_indicator_id = pi.id
        WHERE as_.employee_appraisal_id IN ($placeholders)
    ";
    
    $scoresStmt = $conn->prepare($scoresQuery);
    $types = str_repeat('i', count($appraisal_ids));
    $scoresStmt->bind_param($types, ...$appraisal_ids);
    $scoresStmt->execute();
    $scores = $scoresStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($scores as $score) {
        $scores_by_appraisal[$score['employee_appraisal_id']][] = $score;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Completed Appraisals - HR System</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .filters-section {
            background: var(--bg-glass);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            align-items: end;
        }
        
        .appraisals-table {
            background: var(--bg-glass);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            overflow: hidden;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        
        .table th {
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            border: none;
        }
        
        .table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        
        .table tbody tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .score-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 15px;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .export-buttons {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }
        
        .btn-export {
            padding: 0.375rem 0.75rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-pdf {
            background: #dc3545;
            color: white;
        }
        
        .btn-word {
            background: #0d6efd;
            color: white;
        }
        
        .btn-print {
            background: #28a745;
            color: white;
        }
        
        .btn-export:hover {
            opacity: 0.8;
            transform: translateY(-1px);
        }
        
        .employee-info {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .employee-details {
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        
        .no-results {
            text-align: center;
            padding: 3rem;
            color: var(--text-secondary);
            background: var(--bg-glass);
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }
        
        /* Responsive table */
        @media (max-width: 1200px) {
            .table {
                font-size: 0.875rem;
            }
            
            .table th,
            .table td {
                padding: 0.75rem 0.5rem;
            }
            
            .export-buttons {
                flex-direction: column;
                gap: 0.125rem;
            }
        }
        
        @media (max-width: 768px) {
            .appraisals-table {
                overflow-x: auto;
            }
            
            .table {
                min-width: 800px;
            }
        }

        /* Loading states */
        .btn-export:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .export-loading {
            position: relative;
        }

        .export-loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 8px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-brand">
                <h1>HR System</h1>
                <p>Management Portal</p>
            </div>
            <nav class="nav">
               <ul>
                    <li><a href="dashboard.php">Dashboard</a></li>
                    <li><a href="employees.php">Employees</a></li>
                    <?php if (hasPermission('hr_manager')): ?>
                    <li><a href="departments.php">Departments</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('super_admin')): ?>
                   <li><a href="admin.php?tab=users">Admin</a></li>
                   <?php elseif (hasPermission('hr_manager')): ?>
                  <li><a href="admin.php?tab=financial">Admin</a></li>
                   <?php endif; ?>
                    <?php if (hasPermission('hr_manager')): ?>
                    <li><a href="reports.php">Reports</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('hr_manager')||hasPermission('super_admin')||hasPermission('dept_head')||hasPermission('officer')): ?>
                    <li><a href="leave_management.php">Leave Management</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('section_head')): ?>
                    <li><a href="employee_appraisal.php" class="active">Performance Appraisal</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <button class="sidebar-toggle">☰</button>
                <h1>Completed Appraisals</h1>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></span>
                    <span class="badge badge-info"><?php echo ucwords(str_replace('_', ' ', $user['role'])); ?></span>
                    <a href="logout.php" class="btn btn-secondary btn-sm">Logout</a>
                </div>
            </div>
            
            <div class="content">
                <!-- Navigation Tabs -->
                <div class="leave-tabs">
                    <a href="employee_appraisal.php" class="leave-tab ">Employee Appraisal</a>
                    <?php if(in_array($user['role'], ['hr_manager', 'super_admin', 'manager','managing_director', 'section_head', 'dept_head'])): ?>
                        <a href="performance_appraisal.php" class="leave-tab ">Performance Appraisal</a>
                    <?php endif; ?>
                    <?php if(in_array($user['role'], ['hr_manager', 'super_admin','managing_director'])): ?>
                        <a href="appraisal_management.php" class="leave-tab">Appraisal Management</a>
                    <?php endif; ?>
                        <a href="completed_appraisals.php" class="leave-tab active">Completed Appraisals</a>                   
                </div>

                <!-- Filters Section -->
                <div class="filters-section">
                    <h3>Filter Appraisals</h3>
                    <form method="GET" action="">
                        <div class="filters-grid">
                            <?php if (hasPermission('hr_manager') || hasPermission('dept_head') || hasPermission('manager') || hasPermission('section_head')): ?>
                            <div class="form-group">
                                <label for="employee_id">Employee</label>
                                <select name="employee_id" id="employee_id" class="form-control">
                                    <option value="">All Employees</option>
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?php echo $employee['id']; ?>" <?php echo ($selected_employee == $employee['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name'] . ' (' . $employee['employee_id'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label for="cycle_id">Appraisal Cycle</label>
                                <select name="cycle_id" id="cycle_id" class="form-control">
                                    <option value="">All Cycles</option>
                                    <?php foreach ($cycles as $cycle): ?>
                                        <option value="<?php echo $cycle['id']; ?>" <?php echo ($selected_cycle == $cycle['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cycle['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="completed_appraisals.php" class="btn btn-secondary">Clear</a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Appraisals Table -->
                <?php if (!empty($appraisals)): ?>
                    <div class="appraisals-table">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Cycle</th>
                                    <th>Period</th>
                                    <th>Score</th>
                                    <th>Appraiser</th>
                                    <th>Submitted</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($appraisals as $appraisal): 
                                    $appraisal_scores = $scores_by_appraisal[$appraisal['id']] ?? [];
                                    
                                    // Calculate total score without weights
                                    $total_score = 0;
                                    $total_max = 0;
                                    foreach ($appraisal_scores as $score) {
                                        $total_score += $score['score'];
                                        $total_max += $score['max_score'];
                                    }
                                    $final_percentage = $total_max > 0 ? ($total_score / $total_max) * 100 : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <div class="employee-info">
                                                <?php echo htmlspecialchars($appraisal['first_name'] . ' ' . $appraisal['last_name']); ?>
                                            </div>
                                            <div class="employee-details">
                                                ID: <?php echo htmlspecialchars($appraisal['emp_id']); ?><br>
                                                <?php echo htmlspecialchars($appraisal['department_name'] ?? 'N/A'); ?>
                                                <?php if ($appraisal['section_name']): ?>
                                                    - <?php echo htmlspecialchars($appraisal['section_name']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($appraisal['cycle_name']); ?></strong>
                                        </td>
                                        <td>
                                            <div>
                                                <?php echo date('M d, Y', strtotime($appraisal['start_date'])); ?><br>
                                                <small class="text-muted">to</small><br>
                                                <?php echo date('M d, Y', strtotime($appraisal['end_date'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="score-badge"><?php echo number_format($final_percentage, 1); ?>%</span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($appraisal['appraiser_first_name'] . ' ' . $appraisal['appraiser_last_name']); ?>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($appraisal['submitted_at'])); ?>
                                        </td>
                                        <td>
                                            <div class="export-buttons">
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="appraisal_id" value="<?php echo $appraisal['id']; ?>">
                                                    <input type="hidden" name="export_type" value="pdf">
                                                    <button type="submit" name="export" class="btn-export btn-pdf" title="Export PDF">PDF</button>
                                                </form>
                                                
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="appraisal_id" value="<?php echo $appraisal['id']; ?>">
                                                    <input type="hidden" name="export_type" value="word">
                                                    <button type="submit" name="export" class="btn-export btn-word" title="Export Word">Word</button>
                                                </form>
                                                
                                                <form method="POST" action="" target="_blank" style="display: inline;">
                                                    <input type="hidden" name="appraisal_id" value="<?php echo $appraisal['id']; ?>">
                                                    <input type="hidden" name="export_type" value="print">
                                                    <button type="submit" name="export" class="btn-export btn-print" title="Print">Print</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="margin-top: 1rem; text-align: center; color: var(--text-secondary);">
                        <small>Total: <?php echo count($appraisals); ?> completed appraisal(s)</small>
                    </div>
                <?php else: ?>
                    <div class="no-results">
                        <h3>No Completed Appraisals Found</h3>
                        <p>There are no completed appraisals matching your current filters.</p>
                        <?php if ($selected_cycle || $selected_employee): ?>
                            <a href="completed_appraisals.php" class="btn btn-primary">View All Appraisals</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Sidebar toggle functionality
        document.querySelector('.sidebar-toggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('collapsed');
        });
        
        // Responsive table handling
        function handleResponsiveTable() {
            const table = document.querySelector('.table');
            const container = document.querySelector('.appraisals-table');
            
            if (table && container) {
                if (window.innerWidth <= 768) {
                    container.style.overflowX = 'auto';
                } else {
                    container.style.overflowX = 'visible';
                }
            }
        }
        
        window.addEventListener('resize', handleResponsiveTable);
        window.addEventListener('load', handleResponsiveTable);
        
        // Auto-refresh data every 5 minutes to show new completed appraisals
        setInterval(function() {
            const currentUrl = new URL(window.location);
            const searchParams = currentUrl.searchParams;
            
            // Add a timestamp to prevent caching
            searchParams.set('refresh', Date.now());
            
            // Only auto-refresh if we're still on the same page
            if (window.location.pathname.includes('completed_appraisals.php')) {
                window.location.search = searchParams.toString();
            }
        }, 300000); // 5 minutes
    </script>
</body>
</html>