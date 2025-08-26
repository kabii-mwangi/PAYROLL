<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ob_start();

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
    'id' => $_SESSION['user_id']
];

// Updated permission checking function
function hasAppraisalAccess($userRole) {
    $allowedRoles = ['super_admin', 'hr_manager', 'dept_head', 'section_head', 'manager', 'managing_director'];
    return in_array($userRole, $allowedRoles);
}

function hasPermission($requiredRole) {
    global $user;
    $role_hierarchy = [
        'managing_director' => 6,
        'super_admin' => 5,
        'hr_manager' => 4,
        'dept_head' => 3,
        'section_head' => 2,
        'manager' => 1,
        'employee' => 0
    ];

    $user_level = $role_hierarchy[$user['role']] ?? 0;
    $required_level = $role_hierarchy[$requiredRole] ?? 0;

    return $user_level >= $required_level;
}

// Check if user has permission to access performance appraisals
if (!hasAppraisalAccess($user['role'])) {
    $_SESSION['flash_message'] = 'Access denied. You do not have permission to access performance appraisals.';
    $_SESSION['flash_type'] = 'danger';
    header("Location: dashboard.php");
    exit();
}

// Get user's employee record with null checks
$userEmployeeQuery = "SELECT e.*, d.id as department_id, s.id as section_id 
                     FROM employees e
                     LEFT JOIN users u ON u.employee_id = e.employee_id 
                     LEFT JOIN departments d ON e.department_id = d.id
                     LEFT JOIN sections s ON e.section_id = s.id
                     WHERE u.id = ?";
$stmt = $conn->prepare($userEmployeeQuery);
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$currentEmployee = $stmt->get_result()->fetch_assoc();

if (!$currentEmployee) {
    $_SESSION['flash_message'] = 'Employee record not found. Please contact HR.';
    $_SESSION['flash_type'] = 'danger';
    header("Location: dashboard.php");
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'save_scores':
                $appraisal_id = $_POST['appraisal_id'];
                $scores = $_POST['scores'] ?? [];
                $comments = $_POST['comments'] ?? [];
                
                foreach ($scores as $indicator_id => $score) {
                    $comment = $comments[$indicator_id] ?? '';
                    
                    $scoreStmt = $conn->prepare("
                        INSERT INTO appraisal_scores (employee_appraisal_id, performance_indicator_id, score, appraiser_comment)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                        score = VALUES(score), 
                        appraiser_comment = VALUES(appraiser_comment),
                        updated_at = CURRENT_TIMESTAMP
                    ");
                    $scoreStmt->bind_param("iids", $appraisal_id, $indicator_id, $score, $comment);
                    $scoreStmt->execute();
                }
                
                // Only change status to awaiting_employee if it's currently draft
                $updateStmt = $conn->prepare("UPDATE employee_appraisals SET status = CASE 
                                            WHEN status = 'draft' THEN 'awaiting_employee' 
                                            ELSE status 
                                          END, 
                                          updated_at = CURRENT_TIMESTAMP 
                                          WHERE id = ?");
                $updateStmt->bind_param("i", $appraisal_id);
                $updateStmt->execute();
                
                $_SESSION['flash_message'] = 'Appraisal scores saved successfully. Awaiting employee comment.';
                $_SESSION['flash_type'] = 'success';
                break;
                
            case 'submit_appraisal':
                $appraisal_id = $_POST['appraisal_id'];
                
                $checkStmt = $conn->prepare("SELECT employee_comment, status FROM employee_appraisals WHERE id = ?");
                $checkStmt->bind_param("i", $appraisal_id);
                $checkStmt->execute();
                $result = $checkStmt->get_result();
                $appraisal = $result->fetch_assoc();
                
                if ($appraisal && !empty($appraisal['employee_comment'])) {
                    // If in awaiting_submission and user has appraisal access, validate and save supervisor comment
                    if ($appraisal['status'] === 'awaiting_submission' && hasAppraisalAccess($user['role'])) {
                        $supervisor_comment = trim($_POST['supervisor_comment'] ?? '');
                        if (empty($supervisor_comment)) {
                            $_SESSION['flash_message'] = 'Supervisor comment is required for submission.';
                            $_SESSION['flash_type'] = 'warning';
                            header("Location: performance_appraisal.php" . (!empty($_GET['employee_id']) ? '?employee_id=' . $_GET['employee_id'] : ''));
                            exit();
                        }
                        
                        // Save supervisor comment
                        $commentStmt = $conn->prepare("
                            UPDATE employee_appraisals 
                            SET supervisors_comment = ?, 
                                supervisors_comment_date = CURRENT_TIMESTAMP,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ? AND status = 'awaiting_submission'
                        ");
                        $commentStmt->bind_param("si", $supervisor_comment, $appraisal_id);
                        $commentStmt->execute();
                    }
                    
                    // Proceed with submission
                    $submitStmt = $conn->prepare("UPDATE employee_appraisals SET status = 'submitted', submitted_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $submitStmt->bind_param("i", $appraisal_id);
                    $submitStmt->execute();
                    
                    $_SESSION['flash_message'] = 'Appraisal submitted successfully.';
                    $_SESSION['flash_type'] = 'success';
                } else {
                    $_SESSION['flash_message'] = 'Cannot submit appraisal. Employee comment is required.';
                    $_SESSION['flash_type'] = 'warning';
                }
                break;
                
            case 'save_employee_comment':
                $appraisal_id = $_POST['appraisal_id'];
                $comment = trim($_POST['employee_comment'] ?? '');
                
                if (!empty($comment)) {
                    $commentStmt = $conn->prepare("UPDATE employee_appraisals 
                                                 SET employee_comment = ?, 
                                                 employee_comment_date = CURRENT_TIMESTAMP,
                                                 status = 'awaiting_submission'
                                                 WHERE id = ?");
                    $commentStmt->bind_param("si", $comment, $appraisal_id);
                    $commentStmt->execute();
                    
                    $_SESSION['flash_message'] = 'Your comments have been saved. Awaiting supervisor review.';
                    $_SESSION['flash_type'] = 'success';
                } else {
                    $_SESSION['flash_message'] = 'Please enter your comments before saving.';
                    $_SESSION['flash_type'] = 'warning';
                }
                break;
        }
        
        header("Location: performance_appraisal.php" . (!empty($_GET['employee_id']) ? '?employee_id=' . $_GET['employee_id'] : ''));
        exit();
    }
}

// Get active appraisal cycles that haven't been submitted for selected employee
$cyclesQuery = "
    SELECT ac.* 
    FROM appraisal_cycles ac
    WHERE ac.status = 'active'
    " . (isset($_GET['employee_id']) ? "AND NOT EXISTS (
        SELECT 1 FROM employee_appraisals ea
        WHERE ea.appraisal_cycle_id = ac.id
        AND ea.employee_id = ?
        AND ea.status = 'submitted'
    )" : "") . "
    ORDER BY ac.start_date DESC
";

$cyclesStmt = $conn->prepare($cyclesQuery);
if (isset($_GET['employee_id'])) {
    $cyclesStmt->bind_param("i", $_GET['employee_id']);
}
$cyclesStmt->execute();
$cycles = $cyclesStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get selected cycle (default to first active cycle)
$selected_cycle_id = $_GET['cycle_id'] ?? ($cycles[0]['id'] ?? null);

// Get employees based on user role with proper null checks - exclude current user
$employeesQuery = "";
$employeesParams = [];

switch ($user['role']) {
    case 'section_head':
        if (!empty($currentEmployee['section_id'])) {
            $employeesQuery = "
                SELECT e.id, e.first_name, e.last_name, e.employee_id, 
                       d.name as department_name, s.name as section_name, 
                       e.employee_type as job_role
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN sections s ON e.section_id = s.id
                LEFT JOIN users u ON u.employee_id = e.id
                WHERE e.section_id = ? AND e.employee_status = 'active' AND e.id != ?
                ORDER BY e.first_name, e.last_name
            ";
            $employeesParams = [$currentEmployee['section_id'], $currentEmployee['id']];
        }
        break;
        
    case 'dept_head':
        if (!empty($currentEmployee['department_id'])) {
            $employeesQuery = "
                SELECT e.id, e.first_name, e.last_name, e.employee_id, 
                       d.name as department_name, s.name as section_name, 
                       e.employee_type as job_role
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN sections s ON e.section_id = s.id
                LEFT JOIN users u ON u.employee_id = e.id
                WHERE e.department_id = ? AND e.employee_status = 'active' AND e.id != ?
                ORDER BY e.first_name, e.last_name
            ";
            $employeesParams = [$currentEmployee['department_id'], $currentEmployee['id']];
        }
        break;
        
    case 'manager':
        if (!empty($currentEmployee['section_id'])) {
            $employeesQuery = "
                SELECT e.id, e.first_name, e.last_name, e.employee_id, 
                       d.name as department_name, s.name as section_name, 
                       e.employee_type as job_role
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN sections s ON e.section_id = s.id
                LEFT JOIN users u ON u.employee_id = e.id
                WHERE e.section_id = ? AND e.employee_status = 'active' AND e.id != ?
                ORDER BY e.first_name, e.last_name
            ";
            $employeesParams = [$currentEmployee['section_id'], $currentEmployee['id']];
        }
        break;
        
    case 'hr_manager':
    case 'super_admin':
    case 'managing_director':
        $employeesQuery = "
            SELECT e.id, e.first_name, e.last_name, e.employee_id, 
                   d.name as department_name, s.name as section_name, 
                   e.employee_type as job_role
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            LEFT JOIN users u ON u.employee_id = e.id
            WHERE e.employee_status = 'active' AND e.id != ?
            ORDER BY e.first_name, e.last_name
        ";
        $employeesParams = [$currentEmployee['id']];
        break;
}

// Execute the employees query
$employees = [];
if (!empty($employeesQuery)) {
    $employeesStmt = $conn->prepare($employeesQuery);
    if (!empty($employeesParams)) {
        $employeesStmt->bind_param(str_repeat('i', count($employeesParams)), ...$employeesParams);
    }
    $employeesStmt->execute();
    $employees = $employeesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get selected employee from GET parameter
$selected_employee_id = $_GET['employee_id'] ?? null;

// Get performance indicators with prioritized filtering
$indicators = [];
if ($selected_employee_id) {
    $appraiseeStmt = $conn->prepare("
        SELECT e.*, d.id as department_id, s.id as section_id, 
               e.employee_type as job_role
        FROM employees e
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN sections s ON e.section_id = s.id
        LEFT JOIN users u ON u.employee_id = e.id
        WHERE e.id = ?
    ");
    $appraiseeStmt->bind_param("i", $selected_employee_id);
    $appraiseeStmt->execute();
    $appraiseeDetails = $appraiseeStmt->get_result()->fetch_assoc();

    if ($appraiseeDetails) {
        $deptId = $appraiseeDetails['department_id'];
        $sectionId = $appraiseeDetails['section_id'];
        $role = $appraiseeDetails['job_role'];

        // Build WHERE clause
        $whereConditions = ["pi.role = ?"];
        $types = "s";
        $params = [$role];

        if ($deptId) {
            $whereConditions[] = "(pi.department_id = ? AND pi.role IS NULL)";
            $types .= "i";
            $params[] = $deptId;
        }
        if ($sectionId) {
            $whereConditions[] = "(pi.section_id = ? AND pi.role IS NULL)";
            $types .= "i";
            $params[] = $sectionId;
        }

        // Build ORDER BY clause
        $orderByConditions = [];
        if ($sectionId) {
            $orderByConditions[] = "CASE WHEN pi.section_id = ? THEN 1 END";
            $types .= "i";
            $params[] = $sectionId;
        }
        if ($deptId) {
            $orderByConditions[] = "CASE WHEN pi.department_id = ? THEN 2 END";
            $types .= "i";
            $params[] = $deptId;
        }
        $orderByConditions[] = "CASE WHEN pi.role = ? THEN 3 END";
        $types .= "s";
        $params[] = $role;

        $indicatorsQuery = "
            SELECT pi.* 
            FROM performance_indicators pi
            WHERE pi.is_active = 1
            AND (" . implode(" OR ", $whereConditions) . ")
            ORDER BY " . implode(", ", $orderByConditions) . ", pi.max_score DESC, pi.name
        ";

        $indicatorsStmt = $conn->prepare($indicatorsQuery);
        if ($indicatorsStmt) {
            $indicatorsStmt->bind_param($types, ...$params);
            $indicatorsStmt->execute();
            $indicators = $indicatorsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }
}

// Get existing appraisals for the selected cycle and employee
$appraisals = [];
if ($selected_cycle_id) {
    $appraisalsQuery = "
        SELECT ea.*, e.first_name, e.last_name, e.employee_id as emp_id, ea.supervisors_comment, ea.supervisors_comment_date
        FROM employee_appraisals ea
        JOIN employees e ON ea.employee_id = e.id
        WHERE ea.appraisal_cycle_id = ?" . ($selected_employee_id ? " AND ea.employee_id = ?" : "") . "
        AND ea.appraiser_id = ?
        AND (ea.status = 'draft' OR ea.status = 'awaiting_submission')
    ";
    
    $appraisalsStmt = $conn->prepare($appraisalsQuery);
    if ($selected_employee_id) {
        $appraisalsStmt->bind_param("iii", $selected_cycle_id, $selected_employee_id, $currentEmployee['id']);
    } else {
        $appraisalsStmt->bind_param("ii", $selected_cycle_id, $currentEmployee['id']);
    }
    $appraisalsStmt->execute();
    $appraisals = $appraisalsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Create appraisals for selected employee if they don't have one yet
if ($selected_cycle_id && $selected_employee_id) {
    $checkStmt = $conn->prepare("
        SELECT id FROM employee_appraisals 
        WHERE employee_id = ? AND appraisal_cycle_id = ?
    ");
    $checkStmt->bind_param("ii", $selected_employee_id, $selected_cycle_id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        $createStmt = $conn->prepare("
            INSERT INTO employee_appraisals 
            (employee_id, appraiser_id, appraisal_cycle_id, status)
            VALUES (?, ?, ?, 'draft')
        ");
        $createStmt->bind_param("iii", $selected_employee_id, $currentEmployee['id'], $selected_cycle_id);
        
        if ($createStmt->execute()) {
            $new_appraisal_id = $createStmt->insert_id;
            header("Location: performance_appraisal.php?cycle_id=$selected_cycle_id&employee_id=$selected_employee_id");
            exit();
        }
    }
}

// Get scores for existing appraisals
$scores_by_appraisal = [];
if (!empty($appraisals)) {
    $appraisal_ids = array_column($appraisals, 'id');
    $placeholders = str_repeat('?,', count($appraisal_ids) - 1) . '?';
    
    $scoresQuery = "
        SELECT as_.*
        FROM appraisal_scores as_
        WHERE as_.employee_appraisal_id IN ($placeholders)
    ";
    
    $scoresStmt = $conn->prepare($scoresQuery);
    $types = str_repeat('i', count($appraisal_ids));
    $scoresStmt->bind_param($types, ...$appraisal_ids);
    $scoresStmt->execute();
    $scores = $scoresStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($scores as $score) {
        $scores_by_appraisal[$score['employee_appraisal_id']][$score['performance_indicator_id']] = $score;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Appraisal - HR Management System</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .appraisal-card {
            background: var(--bg-glass);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }
        
        .appraisal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .employee-info h4 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-size: 1.25rem;
        }
        
        .employee-details {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        
        .appraisal-status {
            text-align: right;
        }
        
        .indicators-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 1.5rem;
        }
        
        .indicators-table th,
        .indicators-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .indicators-table th {
            background: var(--bg-glass);
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .indicators-table td {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        
        .score-input {
            width: 80px;
            padding: 0.5rem;
            background: var(--bg-glass);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-primary);
            text-align: center;
        }
        
        .comment-textarea {
            width: 100%;
            min-height: 60px;
            padding: 0.5rem;
            background: var(--bg-glass);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-primary);
            resize: vertical;
        }
        
        .readonly-field {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.1);
            color: var(--text-muted);
            cursor: not-allowed;
        }
        
        .status-message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-weight: 500;
        }
        
        .status-draft {
            background: rgba(108, 117, 125, 0.1);
            color: var(--secondary-color);
            border: 1px solid rgba(108, 117, 125, 0.3);
        }
        
        .status-awaiting {
            background: rgba(253, 203, 110, 0.1);
            color: var(--warning-color);
            border: 1px solid rgba(253, 203, 110, 0.3);
        }
        
        .status-submitted {
            background: rgba(0, 184, 148, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(0, 184, 148, 0.3);
        }
        
        .status-awaiting-submission {
            background: rgba(23, 162, 184, 0.1);
            color: var(--info-color);
            border: 1px solid rgba(23, 162, 184, 0.3);
        }
        
        .cycle-selector {
            margin-bottom: 2rem;
        }
        
        .weight-badge {
            background: rgba(0, 212, 255, 0.2);
            color: var(--primary-color);
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .employee-selector {
            margin-bottom: 1.5rem;
        }
        
        .form-control {
            display: block;
            width: 100%;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            font-weight: 400;
            line-height: 1.5;
            color: var(--text-primary);
            background-color: var(--bg-glass);
            background-clip: padding-box;
            border: 1px solid var(--border-color);
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            border-radius: 8px;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
            backdrop-filter: blur(20px);
        }
        
        .form-control:focus {
            color: var(--text-primary);
            background-color: var(--bg-glass);
            border-color: var(--primary-color);
            outline: 0;
            box-shadow: 0 0 0 0.25rem rgba(0, 212, 255, 0.25);
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .employee-comment-form, .supervisor-comment-form {
            margin-top: 2rem;
            padding: 1.5rem;
            background: var(--bg-glass);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        
        .indicator-scope {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        
        .scope-department { color: var(--primary-color); }
        .scope-section { color: var(--warning-color); }
        .scope-role { color: var(--info-color); }
        
        .no-appraisals {
            padding: 2rem;
            text-align: center;
            background: var(--bg-glass);
            border-radius: 8px;
            border: 1px dashed var(--border-color);
        }
        
        .no-appraisals h4 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }
        
        .no-appraisals p {
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }
        
        .readonly-comment {
            background: rgba(255, 255, 255, 0.05);
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            line-height: 1.6;
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
                    <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                    <li><a href="employees.php"><i class="fas fa-users"></i> Employees</a></li>
                    <?php if (hasPermission('hr_manager')): ?>
                    <li><a href="departments.php"><i class="fas fa-building"></i> Departments</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('super_admin')): ?>
                   <li><a href="admin.php?tab=users"><i class="fas fa-cog"></i> Admin</a></li>
                   <?php elseif (hasPermission('hr_manager')): ?>
                  <li><a href="admin.php?tab=financial"><i class="fas fa-cog"></i> Admin</a></li>
                   <?php endif; ?>
                    <?php if (hasPermission('hr_manager')): ?>
                    <li><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('hr_manager') || hasPermission('super_admin') || hasPermission('dept_head') || hasPermission('officer')): ?>
                    <li><a href="leave_management.php"><i class="fas fa-calendar-alt"></i> Leave Management</a></li>
                    <?php endif; ?>
                    <li><a href="employee_appraisal.php" class="active"><i class="fas fa-chart-line"></i> Performance Appraisal</a></li>
                    <li><a href="payroll.php" ><i class="fas fa-money-check"></i> Payroll</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <button class="sidebar-toggle">☰</button>
                <h1>Performance Appraisal</h1>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></span>
                    <span class="badge badge-info"><?php echo ucwords(str_replace('_', ' ', $user['role'])); ?></span>
                    <a href="logout.php" class="btn btn-secondary btn-sm">Logout</a>
                </div>
            </div>
            
            <div class="content">
                <?php if (isset($_SESSION['flash_message'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['flash_type']; ?>">
                        <?php echo htmlspecialchars($_SESSION['flash_message']); ?>
                    </div>
                    <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
                <?php endif; ?>

                <div class="leave-tabs">
                    <a href="employee_appraisal.php" class="leave-tab">Employee Appraisal</a>
                    <?php if(in_array($user['role'], ['hr_manager', 'super_admin', 'manager','managing_director', 'section_head', 'dept_head'])): ?>
                        <a href="performance_appraisal.php" class="leave-tab active">Performance Appraisal</a>
                    <?php endif; ?>
                    <?php if(in_array($user['role'], ['hr_manager', 'super_admin', 'manager','managing_director'])): ?>
                        <a href="appraisal_management.php" class="leave-tab">Appraisal Management</a>
                    <?php endif; ?>
                        <a href="completed_appraisals.php" class="leave-tab">Completed Appraisals</a>                   
                </div>

                <!-- Employee Selector -->
                <div class="employee-selector glass-card">
                    <h3>Select Employee</h3>
                    <div class="form-group">
                        <select id="employee-select" class="form-control" onchange="updateEmployeeSelection()">
                            <option value="">Select an employee...</option>
                            <?php foreach ($employees as $emp): ?>
                                <?php if ($emp['id'] != $currentEmployee['id']): ?>
                                    <option value="<?php echo $emp['id']; ?>" 
                                        <?php echo ($selected_employee_id == $emp['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_id'] . ') - ' . ($emp['department_name'] ?? 'N/A')); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Cycle Selector -->
                <div class="cycle-selector glass-card">
                    <h3>Select Appraisal Cycle</h3>
                    <div class="form-group">
                        <select class="form-control" id="cycle-select" onchange="updateCycleSelection()">
                            <option value="">Select a cycle...</option>
                            <?php foreach ($cycles as $cycle): ?>
                                <option value="<?php echo $cycle['id']; ?>" <?php echo ($selected_cycle_id == $cycle['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cycle['name']); ?> 
                                    (<?php echo date('M d, Y', strtotime($cycle['start_date'])); ?> - <?php echo date('M d, Y', strtotime($cycle['end_date'])); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php if ($selected_cycle_id && $selected_employee_id): ?>
                    <?php if (!empty($appraisals)): ?>
                        <?php foreach ($appraisals as $appraisal): 
                            $is_readonly = ($appraisal['submitted_at'] !== null);
                            $employee_scores = $scores_by_appraisal[$appraisal['id']] ?? [];
                            
                            // Get employee details
                            $employeeDetails = null;
                            foreach ($employees as $emp) {
                                if ($emp['id'] == $appraisal['employee_id']) {
                                    $employeeDetails = $emp;
                                    break;
                                }
                            }
                        ?>
                            <div class="appraisal-card">
                                <div class="appraisal-header">
                                    <div class="employee-info">
                                        <h4><?php echo htmlspecialchars($appraisal['first_name'] . ' ' . $appraisal['last_name']); ?></h4>
                                        <div class="employee-details">
                                            <strong>Employee ID:</strong> <?php echo htmlspecialchars($appraisal['emp_id']); ?><br>
                                            <strong>Department:</strong> <?php echo htmlspecialchars($employeeDetails['department_name'] ?? 'N/A'); ?><br>
                                            <strong>Section:</strong> <?php echo htmlspecialchars($employeeDetails['section_name'] ?? 'N/A'); ?><br>
                                            <strong>Role:</strong> <?php echo ucwords(str_replace('_', ' ', $employeeDetails['job_role'] ?? 'employee')); ?>
                                        </div>
                                    </div>
                                    <div class="appraisal-status">
                                        <?php if ($appraisal['status'] === 'awaiting_submission'): ?>
                                            <span class="badge badge-info">Awaiting Submission</span>
                                            <div class="status-awaiting-submission">
                                                Employee has commented. Ready for supervisor review and submission.
                                            </div>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">Draft</span>
                                            <div class="status-draft">
                                                Draft - Not yet shared with employee
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="save_scores">
                                    <input type="hidden" name="appraisal_id" value="<?php echo $appraisal['id']; ?>">
                                    
                                    <table class="indicators-table">
                                        <thead>
                                            <tr>
                                                <th>Performance Indicator</th>
                                                <th>Score</th>
                                                <th>Appraiser Comment</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($indicators as $indicator): 
                                                $score_data = $employee_scores[$indicator['id']] ?? null;
                                                $scope_class = '';
                                                $scope_text = '';
                                                if ($indicator['role']) {
                                                    $scope_class = 'scope-role';
                                                    $scope_text = 'Role-specific (' . ucwords(str_replace('_', ' ', $indicator['role'])) . ')';
                                                } elseif ($indicator['department_id'] && $indicator['section_id']) {
                                                    $scope_class = 'scope-section';
                                                    $scope_text = 'Section-specific';
                                                } elseif ($indicator['department_id']) {
                                                    $scope_class = 'scope-department';
                                                    $scope_text = 'Department-wide';
                                                }
                                            ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($indicator['name']); ?></strong>
                                                        <?php if ($indicator['description']): ?>
                                                            <br><small class="text-muted"><?php echo htmlspecialchars($indicator['description']); ?></small>
                                                        <?php endif; ?>
                                                        <div class="indicator-scope <?php echo $scope_class; ?>">
                                                            <?php echo $scope_text; ?>
                                                        </div>
                                                    </td>
                                                                                                       <td>
                                                        <input type="number" 
                                                               name="scores[<?php echo $indicator['id']; ?>]" 
                                                               class="score-input <?php echo $is_readonly ? 'readonly-field' : ''; ?>"
                                                               min="1" max="<?php echo $indicator['max_score']; ?>" 
                                                               step="0.1"
                                                               value="<?php echo $score_data ? htmlspecialchars($score_data['score']) : ''; ?>"
                                                               <?php echo $is_readonly ? 'readonly' : ''; ?>>
                                                    </td>
                                                    <td>
                                                        <textarea name="comments[<?php echo $indicator['id']; ?>]" 
                                                                  class="comment-textarea <?php echo $is_readonly ? 'readonly-field' : ''; ?>"
                                                                  placeholder="Enter your comment..."
                                                                  <?php echo $is_readonly ? 'readonly' : ''; ?>><?php echo $score_data ? htmlspecialchars($score_data['appraiser_comment']) : ''; ?></textarea>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>

                                    <?php if (!$is_readonly && $appraisal['status'] !== 'awaiting_submission'): ?>
                                        <div class="form-actions">
                                            <button type="submit" class="btn btn-primary">Save Scores</button>
                                        </div>
                                    <?php endif; ?>
                                </form>

                                <!-- Employee Comment Section -->
                                <?php if ($appraisal['employee_comment']): ?>
                                    <div class="glass-card mt-3">
                                        <h5>Employee Comment</h5>
                                        <div class="readonly-comment">
                                            <?php echo nl2br(htmlspecialchars($appraisal['employee_comment'])); ?>
                                        </div>
                                        <small class="text-muted">
                                            Commented on <?php echo date('M d, Y H:i', strtotime($appraisal['employee_comment_date'])); ?>
                                        </small>
                                    </div>
                                <?php elseif ($appraisal['status'] === 'awaiting_employee' && $user['id'] == $appraisal['employee_id']): ?>
                                    <div class="employee-comment-form">
                                        <h5>Your Comments</h5>
                                        <form method="POST" action="">
                                            <input type="hidden" name="action" value="save_employee_comment">
                                            <input type="hidden" name="appraisal_id" value="<?php echo $appraisal['id']; ?>">
                                            <div class="form-group">
                                                <textarea name="employee_comment" class="form-control" placeholder="Enter your comments about this appraisal..." required></textarea>
                                            </div>
                                            <button type="submit" class="btn btn-primary">Save Comments</button>
                                        </form>
                                    </div>
                                <?php endif; ?>

                                

                                <!-- Submit Appraisal Form -->
                                <?php if ($appraisal['status'] === 'awaiting_submission' && !$is_readonly): ?>
    <form method="POST" action="" id="submit-appraisal-form-<?php echo $appraisal['id']; ?>" style="margin-top: 1rem;">
        <input type="hidden" name="action" value="submit_appraisal">
        <input type="hidden" name="appraisal_id" value="<?php echo $appraisal['id']; ?>">
        
        <?php if (hasAppraisalAccess($user['role'])): ?>
            <div class="supervisor-comment-form">
                <h5>Supervisor Comment</h5>
                <div class="form-group">
                    <textarea name="supervisor_comment" id="supervisor-comment-<?php echo $appraisal['id']; ?>" 
                              class="form-control" placeholder="Enter your comments as the supervisor..." 
                              required><?php echo isset($_POST['supervisor_comment']) ? htmlspecialchars($_POST['supervisor_comment']) : ''; ?></textarea>
                </div>
            </div>
        <?php endif; ?>
        
        <button type="submit" class="btn btn-success">
            Submit Appraisal
        </button>
    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-appraisals">
                            <h4>No active appraisals for this employee and cycle</h4>
                            <p>All quarters have been submitted or there are no drafts available.</p>
                            <?php if (hasPermission('hr_manager') || hasPermission('super_admin')): ?>
                                <a href="appraisal_management.php" class="btn btn-info mt-3">Manage Appraisals</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php elseif ($selected_cycle_id && !$selected_employee_id): ?>
                    <div class="alert alert-warning">
                        Please select an employee to view their appraisals.
                    </div>
                <?php elseif (!$selected_cycle_id): ?>
                    <div class="alert alert-warning">
                        Please select an appraisal cycle to view employee appraisals.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function updateEmployeeSelection() {
            const employeeId = document.getElementById('employee-select').value;
            const cycleId = document.getElementById('cycle-select').value;
            let url = 'performance_appraisal.php?';
            
            if (cycleId) {
                url += 'cycle_id=' + cycleId;
            }
            
            if (employeeId) {
                url += (cycleId ? '&' : '') + 'employee_id=' + employeeId;
            }
            
            window.location.href = url;
        }
        
        function updateCycleSelection() {
            const cycleId = document.getElementById('cycle-select').value;
            const employeeId = document.getElementById('employee-select').value;
            let url = 'performance_appraisal.php?';
            
            if (cycleId) {
                url += 'cycle_id=' + cycleId;
            }
            
            if (employeeId) {
                url += (cycleId ? '&' : '') + 'employee_id=' + employeeId;
            }
            
            window.location.href = url;
        }
        
        // Auto-save functionality
        const forms = document.querySelectorAll('form[method="POST"]');
        
        forms.forEach(form => {
            if (form.querySelector('input[name="action"][value="save_scores"]') || 
                form.querySelector('input[name="action"][value="save_employee_comment"]')) {
                const inputs = form.querySelectorAll('input, textarea');
                
                inputs.forEach(input => {
                    if (!input.classList.contains('readonly-field')) {
                        input.addEventListener('change', function() {
                            clearTimeout(this.saveTimeout);
                            this.saveTimeout = setTimeout(() => {
                                const formData = new FormData(form);
                                
                                fetch('performance_appraisal.php', {
                                    method: 'POST',
                                    body: formData
                                }).then(response => {
                                    if (response.ok) {
                                        input.style.borderColor = 'var(--success-color)';
                                        setTimeout(() => {
                                            input.style.borderColor = '';
                                        }, 1000);
                                    }
                                }).catch(error => {
                                    console.error('Auto-save failed:', error);
                                });
                            }, 2000);
                        });
                    }
                });
            }
        });
    </script>
</body>
</html>