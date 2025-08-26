```php
<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';

// Get database connection
$conn = getConnection();

// Get current user from session
$user = [
    'first_name' => isset($_SESSION['user_name']) ? explode(' ', $_SESSION['user_name'])[0] : 'User',
    'last_name' => isset($_SESSION['user_name']) ? (explode(' ', $_SESSION['user_name'])[1] ?? '') : '',
    'role' => $_SESSION['user_role'] ?? 'guest',
    'id' => $_SESSION['user_id']
];

// Permission check function
function hasPermission($requiredRole) {
    $userRole = $_SESSION['user_role'] ?? 'guest';
    
    $roles = [
        'managing_director' => 6,
        'super_admin' => 5,
        'hr_manager' => 4,
        'dept_head' => 3,
        'section_head' => 2,
        'manager' => 1,
        'employee' => 0
    ];
    
    $userLevel = $roles[$userRole] ?? 0;
    $requiredLevel = $roles[$requiredRole] ?? 0;
    
    return $userLevel >= $requiredLevel;
}

// Helper functions
function getEmployeeTypeBadge($type) {
    $badges = [
        'full_time' => 'badge-primary',
        'part_time' => 'badge-info',
        'contract' => 'badge-warning',
        'temporary' => 'badge-secondary',
        'officer' => 'badge-primary',
        'section_head' => 'badge-info',
        'manager' => 'badge-success',
        'hr_manager' => 'badge-success',
        'dept_head' => 'badge-info',
        'managing_director' => 'badge-primary',
        'bod_chairman' => 'badge-primary'
    ];
    return $badges[$type] ?? 'badge-light';
}

function getEmployeeStatusBadge($status) {
    $badges = [
        'active' => 'badge-success',
        'on_leave' => 'badge-warning',
        'terminated' => 'badge-danger',
        'resigned' => 'badge-secondary',
        'inactive' => 'badge-secondary',
        'fired' => 'badge-danger',
        'retired' => 'badge-secondary'
    ];
    return $badges[$status] ?? 'badge-light';
}

function formatDate($date) {
    if (!$date || $date == '0000-00-00') return 'N/A';
    return date('M d, Y', strtotime($date));
}

function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return false;
}

function redirectWithMessage($url, $message, $type = 'info') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header("Location: $url");
    exit();
}

function sanitizeInput($data) {
    if ($data === null) {
        return '';
    }
    return htmlspecialchars(stripslashes(trim($data)));
}

// Fetch current user and employee details
$user_stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user['id']);
$user_stmt->execute();
$current_user = $user_stmt->get_result()->fetch_assoc();
$employee_id_str = $current_user['employee_id'];

$emp_query = "
    SELECT e.*, 
           d.name as department_name, 
           s.name as section_name 
    FROM employees e 
    LEFT JOIN departments d ON e.department_id = d.id 
    LEFT JOIN sections s ON e.section_id = s.id 
    WHERE e.employee_id = ?
";
$emp_stmt = $conn->prepare($emp_query);
$emp_stmt->bind_param("s", $employee_id_str);
$emp_stmt->execute();
$employee = $emp_stmt->get_result()->fetch_assoc();

// Get departments and sections for forms
$departments = $conn->query("SELECT * FROM departments ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$sections = $conn->query("SELECT s.*, d.name as department_name FROM sections s LEFT JOIN departments d ON s.department_id = d.id ORDER BY d.name, s.name")->fetch_all(MYSQLI_ASSOC);

// Employees data (only if HR)
$employees = [];
if (hasPermission('hr_manager')) {
    // Get filter parameters
    $search = $_GET['search'] ?? '';
    $department_filter = $_GET['department'] ?? '';
    $section_filter = $_GET['section'] ?? '';
    $type_filter = $_GET['type'] ?? '';
    $status_filter = $_GET['status'] ?? '';

    // Build query with filters
    $where_conditions = [];
    $params = [];
    $types = '';

    if (!empty($search)) {
        $where_conditions[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ? OR e.email LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
        $types .= 'ssss';
    }

    if (!empty($department_filter)) {
        $where_conditions[] = "e.department_id = ?";
        $params[] = $department_filter;
        $types .= 'i';
    }

    if (!empty($section_filter)) {
        $where_conditions[] = "e.section_id = ?";
        $params[] = $section_filter;
        $types .= 'i';
    }

    if (!empty($type_filter)) {
        $where_conditions[] = "e.employee_type = ?";
        $params[] = $type_filter;
        $types .= 's';
    }

    if (!empty($status_filter)) {
        $where_conditions[] = "e.employee_status = ?";
        $params[] = $status_filter;
        $types .= 's';
    }

    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

    $query = "
        SELECT e.*, 
               COALESCE(e.first_name, '') as first_name,
               COALESCE(e.last_name, '') as last_name,
               d.name as department_name, 
               s.name as section_name 
        FROM employees e 
        LEFT JOIN departments d ON e.department_id = d.id 
        LEFT JOIN sections s ON e.section_id = s.id 
        $where_clause
        ORDER BY e.created_at DESC
    ";

    $stmt = $conn->prepare($query);

    // Bind parameters if needed
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $employees = $result->fetch_all(MYSQLI_ASSOC);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'add' && hasPermission('hr_manager')) {
            $employee_id = sanitizeInput($_POST['employee_id']);
            $first_name = sanitizeInput($_POST['first_name']);
            $last_name = sanitizeInput($_POST['last_name']);
            $gender = isset($_POST['gender']) ? sanitizeInput($_POST['gender']) : '';
            $national_id = sanitizeInput($_POST['national_id']);
            $email = sanitizeInput($_POST['email']);
            $phone = sanitizeInput($_POST['phone']);
            $address = sanitizeInput($_POST['address']);
            $date_of_birth = $_POST['date_of_birth'];
            $hire_date = $_POST['hire_date'];
            $designation = sanitizeInput($_POST['designation']) ?: 'Employee';
            $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
            $section_id = !empty($_POST['section_id']) ? $_POST['section_id'] : null;
            $employee_type = $_POST['employee_type'];
            $employment_type = $_POST['employment_type'] ?: 'permanent';

            try {
                $conn->begin_transaction();
                
                $stmt = $conn->prepare("INSERT INTO employees (employee_id, first_name, last_name, gender, national_id, phone, email, date_of_birth, designation, department_id, section_id, employee_type, employment_type, address, hire_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssssssiissss", 
                    $employee_id, 
                    $first_name, 
                    $last_name, 
                    $gender,
                    $national_id, 
                    $phone, 
                    $email, 
                    $date_of_birth, 
                    $designation, 
                    $department_id, 
                    $section_id, 
                    $employee_type, 
                    $employment_type,
                    $address, 
                    $hire_date
                );
                
                $stmt->execute();
                $new_employee_id = $conn->insert_id;
                
                $user_role = 'employee';
                switch($employee_type) {
                    case 'managing_director':
                    case 'bod_chairman':
                        $user_role = 'super_admin';
                        break;
                    case 'dept_head':
                        $user_role = 'dept_head';
                        break;
                    case 'hr_manager':
                        $user_role = 'hr_manager';
                        break;
                    case 'manager':
                        $user_role = 'manager';
                        break;
                    case 'section_head':
                        $user_role = 'section_head';
                        break;
                    default:
                        $user_role = 'employee';
                        break;
                }
                
                $hashed_password = password_hash($employee_id, PASSWORD_DEFAULT);
                
                $user_stmt = $conn->prepare("INSERT INTO users (email, first_name, last_name, gender, password, role, phone, address, employee_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $user_stmt->bind_param("sssssssss", 
                    $email, 
                    $first_name, 
                    $last_name, 
                    $gender,
                    $hashed_password, 
                    $user_role, 
                    $phone, 
                    $address, 
                    $employee_id
                );
                
                $user_stmt->execute();
                
                $conn->commit();
                
                redirectWithMessage('employee.php', 'Employee and user account created successfully! Default password is the employee ID.', 'success');
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error adding employee: ' . $e->getMessage();
            }
        } elseif ($action === 'edit' && hasPermission('hr_manager')) {
            $id = $_POST['id'];
            $employee_id = sanitizeInput($_POST['employee_id']);
            $first_name = sanitizeInput($_POST['first_name']);
            $last_name = sanitizeInput($_POST['last_name']);
            $gender = isset($_POST['gender']) ? sanitizeInput($_POST['gender']) : '';
            $national_id = sanitizeInput($_POST['national_id']);
            $email = sanitizeInput($_POST['email']);
            $phone = sanitizeInput($_POST['phone']);
            $address = sanitizeInput($_POST['address']);
            $date_of_birth = $_POST['date_of_birth'];
            $hire_date = $_POST['hire_date'];
            $designation = sanitizeInput($_POST['designation']);
            $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
            $section_id = !empty($_POST['section_id']) ? $_POST['section_id'] : null;
            $employee_type = $_POST['employee_type'];
            $employment_type = $_POST['employment_type'];
            $employee_status = $_POST['employee_status'];
            
            try {
                $conn->begin_transaction();
                
                $current_emp_stmt = $conn->prepare("SELECT employee_id FROM employees WHERE id = ?");
                $current_emp_stmt->bind_param("i", $id);
                $current_emp_stmt->execute();
                $current_emp_result = $current_emp_stmt->get_result();
                $current_employee = $current_emp_result->fetch_assoc();
                $old_employee_id = $current_employee['employee_id'];
                
                $stmt = $conn->prepare("UPDATE employees SET 
                    employee_id=?, 
                    first_name=?, 
                    last_name=?, 
                    gender=?, 
                    national_id=?, 
                    email=?, 
                    phone=?, 
                    address=?, 
                    date_of_birth=?, 
                    hire_date=?, 
                    designation=?, 
                    department_id=?, 
                    section_id=?, 
                    employee_type=?, 
                    employment_type=?, 
                    employee_status=?, 
                    updated_at=NOW() 
                    WHERE id=?");
                
                $stmt->bind_param("ssssssssssiissssi", 
                    $employee_id, 
                    $first_name, 
                    $last_name, 
                    $gender,
                    $national_id, 
                    $email, 
                    $phone, 
                    $address, 
                    $date_of_birth, 
                    $hire_date, 
                    $designation, 
                    $department_id, 
                    $section_id, 
                    $employee_type, 
                    $employment_type, 
                    $employee_status, 
                    $id
                );
                
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
                }
                
                $user_role = 'employee';
                switch($employee_type) {
                    case 'managing_director':
                    case 'bod_chairman':
                        $user_role = 'super_admin';
                        break;
                    case 'dept_head':
                        $user_role = 'dept_head';
                        break;
                    case 'hr_manager':
                        $user_role = 'hr_manager';
                        break;
                    case 'manager':
                        $user_role = 'manager';
                        break;
                    case 'section_head':
                        $user_role = 'section_head';
                        break;
                    default:
                        $user_role = 'employee';
                        break;
                }
                
                $user_update_stmt = $conn->prepare("UPDATE users SET email=?, first_name=?, last_name=?, gender=?, role=?, phone=?, address=?, employee_id=?, updated_at=NOW() WHERE employee_id=?");
                
                $user_update_stmt->bind_param("sssssssss", 
                    $email, 
                    $first_name, 
                    $last_name,
                    $gender,
                    $user_role, 
                    $phone, 
                    $address, 
                    $employee_id,
                    $old_employee_id
                );
                
                $user_update_stmt->execute();
                
                $conn->commit();
                
                redirectWithMessage('employee.php', 'Employee and user account updated successfully!', 'success');
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error updating employee: ' . $e->getMessage();
            }
        } elseif ($action === 'change_password') {
            $old_password = sanitizeInput($_POST['old_password']);
            $new_password = sanitizeInput($_POST['new_password']);
            $confirm_password = sanitizeInput($_POST['confirm_password']);

            if ($new_password !== $confirm_password) {
                $error = 'New passwords do not match.';
            } elseif (strlen($new_password) < 8) {
                $error = 'New password must be at least 8 characters long.';
            } else {
                $current_hash = $current_user['password'];
                if (!password_verify($old_password, $current_hash)) {
                    $error = 'Incorrect old password.';
                } else {
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $update_stmt->bind_param("si", $new_hash, $user['id']);
                    if ($update_stmt->execute()) {
                        redirectWithMessage('employee.php', 'Password changed successfully!', 'success');
                    } else {
                        $error = 'Error changing password.';
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - HR Management System</title>
    <link rel="stylesheet" href="style.css">
    <style>
   /* Global container styling */
.container {
    display: flex;
    min-height: 100vh;
    background: linear-gradient(135deg, #0a0f1e 0%, #1a2238 100%);
    color: #e0e6ff;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    overflow: hidden;
}

/* Sidebar styling */
.sidebar {
    width: 280px;
    background: linear-gradient(to bottom, #0d1326, #1a2238);
    border-right: 1px solid #2a3a55;
    padding: 20px;
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.3);
    transition: transform 0.3s ease;
}

.sidebar-brand {
    margin-bottom: 30px;
    text-align: center;
}

.sidebar-brand h1 {
    font-size: 1.8rem;
    color: #00d1ff;
    margin: 0;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.sidebar-brand p {
    color: #a0aec0;
    font-size: 0.9rem;
    margin: 5px 0 0;
}

.nav ul {
    list-style: none;
    padding: 0;
}

.nav ul li a {
    display: block;
    padding: 12px 20px;
    color: #a0aec0;
    text-decoration: none;
    font-size: 1rem;
    border-radius: 8px;
    margin-bottom: 8px;
    transition: all 0.3s ease;
}

.nav ul li a:hover {
    background: rgba(0, 209, 255, 0.1);
    color: #00d1ff;
}

.nav ul li a.active {
    background: #00d1ff;
    color: #0a0a0a;
    font-weight: 600;
}

/* Main content styling */
.main-content {
    flex: 1;
    padding: 30px;
    background: #141b2d;
    overflow-y: auto;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 15px;
    border-bottom: 1px solid #2a3a55;
}

.header h1 {
    font-size: 2rem;
    color: #00d1ff;
    margin: 0;
    font-weight: 700;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 15px;
}

.user-info .badge {
    font-size: 0.9rem;
    padding: 6px 12px;
    text-transform: capitalize;
}

/* Tabs styling */
.tabs ul {
    list-style: none;
    display: flex;
    margin: 0;
    padding: 0;
    border-bottom: 2px solid #00d1ff;
    background: #1a2238;
    border-radius: 8px 8px 0 0;
}

.tabs ul li a {
    display: block;
    padding: 12px 25px;
    text-decoration: none;
    color: #a0aec0;
    background: #1f2a40;
    margin-right: 5px;
    border-radius: 8px 8px 0 0;
    font-weight: 500;
    transition: all 0.3s ease;
}

.tabs ul li a:hover {
    background: #2a3a55;
    color: #00d1ff;
}

.tabs ul li a.active {
    background: #00d1ff;
    color: #0a0a0a;
    font-weight: 600;
}

.tab-content {
    display: none;
    padding: 25px;
    background: #1f2a40;
    border-radius: 0 8px 8px 8px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
}

.tab-content.active {
    display: block;
}

/* Document container styling */
.document-container {
    max-width: 900px;
    margin: 0 auto;
    background: #212b45;
    padding: 40px;
    border: 1px solid #2d3e5e;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0, 209, 255, 0.1);
}

.document-header {
    text-align: center;
    margin-bottom: 40px;
    padding-bottom: 15px;
    border-bottom: 2px solid #00d1ff;
}

.document-header h2 {
    color: #00d1ff;
    font-size: 1.8rem;
    font-weight: 700;
    margin: 0;
}

.document-content {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 25px;
    font-size: 1rem;
}

.document-content .label {
    font-weight: 600;
    color: #e0e6ff;
}

.document-content .value {
    color: #a0aec0;
}

/* Form styling */
.password-form {
    margin-top: 40px;
    padding-top: 25px;
    border-top: 1px solid #2a3a55;
}

.password-form h3 {
    color: #00d1ff;
    font-size: 1.5rem;
    margin-bottom: 25px;
}

.form-group {
    margin-bottom: 20px;
}

.form-control {
    width: 100%;
    padding: 10px;
    background: #283347;
    border: 1px solid #3c4c69;
    color: #e0e6ff;
    border-radius: 6px;
    font-size: 1rem;
    transition: border-color 0.3s ease;
}

.form-control:focus {
    outline: none;
    border-color: #00d1ff;
    box-shadow: 0 0 8px rgba(0, 209, 255, 0.3);
}

/* Button styling */
.btn-primary {
    background: #00d1ff;
    border: none;
    padding: 10px 20px;
    color: #0a0a0a;
    border-radius: 6px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-primary:hover {
    background: #00b8e6;
    box-shadow: 0 4px 12px rgba(0, 209, 255, 0.3);
}

.btn-success {
    background: #28a745;
    border: none;
    padding: 10px 20px;
    color: #ffffff;
    border-radius: 6px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-success:hover {
    background: #218838;
    box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
}

.btn-secondary {
    background: #6c757d;
    border: none;
    padding: 10px 20px;
    color: #ffffff;
    border-radius: 6px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-secondary:hover {
    background: #5a6268;
    box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
}

/* Alert styling */
.alert {
    padding: 12px;
    margin-bottom: 25px;
    border-radius: 6px;
    font-size: 1rem;
}

.alert-success {
    background: rgba(40, 167, 69, 0.15);
    color: #28a745;
    border: 1px solid #28a745;
}

.alert-danger {
    background: rgba(220, 53, 69, 0.15);
    color: #dc3545;
    border: 1px solid #dc3545;
}

/* Table styling */
.table-container {
    overflow-x: auto;
    margin-top: 20px;
}

.table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    color: #e0e6ff;
    background: #212b45;
    border-radius: 8px;
    overflow: hidden;
}

.table th, .table td {
    padding: 12px;
    border: 1px solid #2d3e5e;
    text-align: left;
}

.table th {
    background: #00d1ff;
    color: #0a0a0a;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.9rem;
}

.table td {
    background: #1f2a40;
}

/* Badge styling */
.badge {
    padding: 6px 12px;
    border-radius: 16px;
    font-size: 0.9rem;
    font-weight: 500;
    display: inline-block;
    text-transform: capitalize;
}

.badge-primary {
    background: #00d1ff;
    color: #0a0a0a;
}

.badge-success {
    background: #28a745;
    color: #ffffff;
}

.badge-info {
    background: #17a2b8;
    color: #ffffff;
}

.badge-warning {
    background: #ffc107;
    color: #212529;
}

.badge-danger {
    background: #dc3545;
    color: #ffffff;
}

.badge-secondary {
    background: #6c757d;
    color: #ffffff;
}

/* Modal styling */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    z-index: 1000;
    backdrop-filter: blur(5px);
}

.modal-content {
    background: #212b45;
    margin: 5% auto;
    padding: 30px;
    border-radius: 12px;
    max-width: 700px;
    width: 90%;
    color: #e0e6ff;
    border: 1px solid #2d3e5e;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
}

.modal-header h3 {
    margin: 0;
    font-size: 1.6rem;
    color: #00d1ff;
}

.close {
    cursor: pointer;
    font-size: 28px;
    color: #ffffff;
    transition: color 0.3s ease;
}

.close:hover {
    color: #00d1ff;
}

.form-row {
    display: flex;
    gap: 25px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.form-actions {
    margin-top: 25px;
    text-align: right;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

/* Search filters styling */
.search-filters .filter-row {
    display: flex;
    gap: 25px;
    flex-wrap: wrap;
    margin-bottom: 25px;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .container {
        flex-direction: column;
    }

    .sidebar {
        width: 100%;
        transform: translateX(-100%);
        position: fixed;
        z-index: 1001;
        height: 100%;
    }

    .sidebar.active {
        transform: translateX(0);
    }

    .main-content {
        padding: 20px;
    }

    .document-content {
        grid-template-columns: 1fr;
    }

    .form-row {
        flex-direction: column;
        gap: 15px;
    }
}
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
                    <?php if (hasPermission('super_admin') || hasPermission('hr_manager')): ?>
                    <li><a href="admin.php">Admin</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('hr_manager')): ?>
                    <li><a href="reports.php">Reports</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('hr_manager') || hasPermission('super_admin') || hasPermission('dept_head') || hasPermission('officer')): ?>
                    <li><a href="leave_management.php">Leave Management</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <button class="sidebar-toggle">☰</button>
                <h1>Profile</h1>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></span>
                    <span class="badge badge-info"><?php echo ucwords(str_replace('_', ' ', $user['role'])); ?></span>
                    <a href="logout.php" class="btn btn-secondary btn-sm">Logout</a>
                </div>
            </div>
            
            <div class="content">
                <?php $flash = getFlashMessage(); if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type']; ?>">
                        <?php echo htmlspecialchars($flash['message']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <div class="tabs">
                    <ul>
                        <li><a href="#" class="tab-link active" data-tab="profile">My Profile</a></li>
                        <?php if (hasPermission('hr_manager')): ?>
                            <li><a href="#" class="tab-link" data-tab="employees">Manage Employees</a></li>
                        <?php endif; ?>
                    </ul>
                    <div id="profile" class="tab-content active">
                        <div class="document-container">
                            <div class="document-header">
                                <h2>Employee Profile</h2>
                            </div>
                            <div class="document-content">
                                <div class="label">Employee ID:</div>
                                <div class="value"><?php echo htmlspecialchars($employee['employee_id']); ?></div>
                                <div class="label">Name:</div>
                                <div class="value"><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></div>
                                <div class="label">Email:</div>
                                <div class="value"><?php echo htmlspecialchars($employee['email'] ?? 'N/A'); ?></div>
                                <div class="label">Department:</div>
                                <div class="value"><?php echo htmlspecialchars($employee['department_name'] ?? 'N/A'); ?></div>
                                <div class="label">Section:</div>
                                <div class="value"><?php echo htmlspecialchars($employee['section_name'] ?? 'N/A'); ?></div>
                                <div class="label">Type:</div>
                                <div class="value"><?php echo ucwords(str_replace('_', ' ', $employee['employee_type'] ?? 'N/A')); ?></div>
                                <div class="label">Status:</div>
                                <div class="value"><?php echo ucwords($employee['employee_status'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="password-form">
                                <h3>Change Password</h3>
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="change_password">
                                    <div class="form-group">
                                        <label for="old_password">Old Password</label>
                                        <input type="password" class="form-control" id="old_password" name="old_password" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="new_password">New Password</label>
                                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="confirm_password">Confirm New Password</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                    </div>
                                    <button type="submit" class="btn btn-primary">Change Password</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php if (hasPermission('hr_manager')): ?>
                    <div id="employees" class="tab-content">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h2>Employees (<?php echo count($employees); ?>)</h2>
                            <button onclick="showAddModal()" class="btn btn-success">Add New Employee</button>
                        </div>
                        
                        <!-- Search and Filters -->
                        <div class="search-filters">
                            <form method="GET" action="">
                                <div class="filter-row">
                                    <div class="form-group">
                                        <label for="search">Search</label>
                                        <input type="text" class="form-control" id="search" name="search" 
                                               value="<?php echo htmlspecialchars($search); ?>" 
                                               placeholder="Name, ID, or Email">
                                    </div>
                                    <div class="form-group">
                                        <label for="department">Department</label>
                                        <select class="form-control" id="department" name="department">
                                            <option value="">All Departments</option>
                                            <?php foreach ($departments as $dept): ?>
                                                <option value="<?php echo $dept['id']; ?>" 
                                                        <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($dept['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="type">Employee Type</label>
                                        <select class="form-control" id="type" name="type">
                                            <option value="">All Types</option>
                                            <option value="officer" <?php echo $type_filter === 'officer' ? 'selected' : ''; ?>>Officer</option>
                                            <option value="section_head" <?php echo $type_filter === 'section_head' ? 'selected' : ''; ?>>Section Head</option>
                                            <option value="manager" <?php echo $type_filter === 'manager' ? 'selected' : ''; ?>>Manager</option>
                                            <option value="hr_manager" <?php echo $type_filter === 'hr_manager' ? 'selected' : ''; ?>>Human Resource Manager</option>
                                            <option value="dept_head" <?php echo $type_filter === 'dept_head' ? 'selected' : ''; ?>>Department Head</option>
                                            <option value="managing_director" <?php echo $type_filter === 'managing_director' ? 'selected' : ''; ?>>Managing Director</option>
                                            <option value="bod_chairman" <?php echo $type_filter === 'bod_chairman' ? 'selected' : ''; ?>>BOD Chairman</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="status">Status</label>
                                        <select class="form-control" id="status" name="status">
                                            <option value="">All Status</option>
                                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                            <option value="resigned" <?php echo $status_filter === 'resigned' ? 'selected' : ''; ?>>Resigned</option>
                                            <option value="fired" <?php echo $status_filter === 'fired' ? 'selected' : ''; ?>>Fired</option>
                                            <option value="retired" <?php echo $status_filter === 'retired' ? 'selected' : ''; ?>>Retired</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <button type="submit" class="btn btn-primary">Filter</button>
                                        <a href="employee.php" class="btn btn-secondary">Clear</a>
                                    </div>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Employees Table -->
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Department</th>
                                        <th>Section</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($employees)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center">No employees found</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($employees as $emp): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($emp['employee_id']); ?></td>
                                            <td><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($emp['email'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($emp['department_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($emp['section_name'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="badge <?php echo getEmployeeTypeBadge($emp['employee_type'] ?? ''); ?>">
                                                    <?php 
                                                    $type = $emp['employee_type'] ?? '';
                                                    echo $type ? ucwords(str_replace('_', ' ', $type)) : 'N/A'; 
                                                    ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo getEmployeeStatusBadge($emp['employee_status'] ?? ''); ?>">
                                                    <?php 
                                                    $status = $emp['employee_status'] ?? '';
                                                    echo $status ? ucwords($status) : 'N/A'; 
                                                    ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button onclick="showEditModal(<?php echo htmlspecialchars(json_encode($emp)); ?>)" class="btn btn-sm btn-primary">Edit</button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Employee Modal -->
    <?php if (hasPermission('hr_manager')): ?>
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Employee</h3>
                <span class="close" onclick="hideAddModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="employee_id">Employee ID</label>
                        <input type="text" class="form-control" id="employee_id" name="employee_id" required>
                    </div>
                    <div class="form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" class="form-control" id="first_name" name="first_name" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" class="form-control" id="last_name" name="last_name" required>
                    </div>
                    <div class="form-group">
                        <label for="gender">Gender</label>
                        <select class="form-control" id="gender" name="gender" required>
                            <option value="">Select Gender</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="national_id">National ID</label>
                        <input type="text" class="form-control" id="national_id" name="national_id" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="designation">Designation</label>
                        <input type="text" class="form-control" id="designation" name="designation" required placeholder="e.g. Software Engineer">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="text" class="form-control" id="phone" name="phone" required>
                    </div>
                    <div class="form-group">
                        <label for="date_of_birth">Date of Birth</label>
                        <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea class="form-control" id="address" name="address" rows="3"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="hire_date">Hire Date</label>
                        <input type="date" class="form-control" id="hire_date" name="hire_date" required>
                    </div>
                    <div class="form-group">
                        <label for="employment_type">Employment Type</label>
                        <select class="form-control" id="employment_type" name="employment_type" required>
                            <option value="">Select Type</option>
                            <option value="permanent">Permanent</option>
                            <option value="contract">Contract</option>
                            <option value="temporary">Temporary</option>
                            <option value="intern">Intern</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="employee_type">Employee Type</label>
                        <select class="form-control" id="employee_type" name="employee_type" required onchange="handleEmployeeTypeChange()">
                            <option value="">Select Type</option>
                            <option value="officer">Officer</option>
                            <option value="section_head">Section Head</option>
                            <option value="manager">Manager</option>
                            <option value="hr_manager">Human Resource Manager</option>
                            <option value="dept_head">Department Head</option>
                            <option value="managing_director">Managing Director</option>
                            <option value="bod_chairman">BOD Chairman</option>
                        </select>
                    </div>
                    <div class="form-group" id="department_group">
                        <label for="department_id">Department</label>
                        <select class="form-control" id="department_id" name="department_id" onchange="updateSections()">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group" id="section_group">
                    <label for="section_id">Section</label>
                    <select class="form-control" id="section_id" name="section_id">
                        <option value="">Select Section</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-success">Add Employee</button>
                    <button type="button" class="btn btn-secondary" onclick="hideAddModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Edit Employee Modal -->
    <?php if (hasPermission('hr_manager')): ?>
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Employee</h3>
                <span class="close" onclick="hideEditModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" id="edit_id" name="id">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_employee_id">Employee ID</label>
                        <input type="text" class="form-control" id="edit_employee_id" name="employee_id" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_first_name">First Name</label>
                        <input type="text" class="form-control" id="edit_first_name" name="first_name" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_last_name">Last Name</label>
                        <input type="text" class="form-control" id="edit_last_name" name="last_name" required>
                    </div>
                    <div class="form-group">
                        <label for="gender">Gender</label>
                        <select class="form-control" id="edit_gender" name="gender" required>
                            <option value="">Select Gender</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_national_id">National ID</label>
                        <input type="text" class="form-control" id="edit_national_id" name="national_id" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_email">Email</label>
                        <input type="email" class="form-control" id="edit_email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_designation">Designation</label>
                        <input type="text" class="form-control" id="edit_designation" name="designation" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_phone">Phone</label>
                        <input type="text" class="form-control" id="edit_phone" name="phone" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_date_of_birth">Date of Birth</label>
                        <input type="date" class="form-control" id="edit_date_of_birth" name="date_of_birth" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="edit_address">Address</label>
                    <textarea class="form-control" id="edit_address" name="address" rows="3"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_hire_date">Hire Date</label>
                        <input type="date" class="form-control" id="edit_hire_date" name="hire_date" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_employment_type">Employment Type</label>
                        <select class="form-control" id="edit_employment_type" name="employment_type" required>
                            <option value="">Select Type</option>
                            <option value="permanent">Permanent</option>
                            <option value="contract">Contract</option>
                            <option value="temporary">Temporary</option>
                            <option value="intern">Intern</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_employee_type">Employee Type</label>
                        <select class="form-control" id="edit_employee_type" name="employee_type" required onchange="handleEditEmployeeTypeChange()">
                            <option value="">Select Type</option>
                            <option value="officer">Officer</option>
                            <option value="section_head">Section Head</option>
                            <option value="manager">Manager</option>
                            <option value="hr_manager">Human Resource Manager</option>
                            <option value="dept_head">Department Head</option>
                            <option value="managing_director">Managing Director</option>
                            <option value="bod_chairman">BOD Chairman</option>
                        </select>
                    </div>
                    <div class="form-group" id="edit_department_group">
                        <label for="edit_department_id">Department</label>
                        <select class="form-control" id="edit_department_id" name="department_id" onchange="updateEditSections()">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group" id="edit_section_group">
                        <label for="edit_section_id">Section</label>
                        <select class="form-control" id="edit_section_id" name="section_id">
                            <option value="">Select Section</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_employee_status">Status</label>
                        <select class="form-control" id="edit_employee_status" name="employee_status" required>
                            <option value="">Select Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="resigned">Resigned</option> 
                            <option value="fired">Fired</option> 
                            <option value="retired">Retired</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Update Employee</button>
                    <button type="button" class="btn btn-secondary" onclick="hideEditModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Tab switching
        document.querySelectorAll('.tab-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const tabId = this.dataset.tab;
                document.querySelectorAll('.tab-link').forEach(l => l.classList.remove('active'));
                this.classList.add('active');
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                document.getElementById(tabId).classList.add('active');
            });
        });

        function showAddModal() {
            document.getElementById('addModal').style.display = 'block';
        }
        
        function hideAddModal() {
            document.getElementById('addModal').style.display = 'none';
        }
        
        function showEditModal(employee) {
            document.getElementById('edit_id').value = employee.id;
            document.getElementById('edit_employee_id').value = employee.employee_id;
            document.getElementById('edit_first_name').value = employee.first_name || '';
            document.getElementById('edit_last_name').value = employee.last_name || '';
            document.getElementById('edit_national_id').value = employee.national_id;
            document.getElementById('edit_email').value = employee.email;
            document.getElementById('edit_designation').value = employee.designation;
            document.getElementById('edit_phone').value = employee.phone;
            document.getElementById('edit_date_of_birth').value = employee.date_of_birth;
            document.getElementById('edit_hire_date').value = employee.hire_date;
            document.getElementById('edit_address').value = employee.address || '';
            document.getElementById('edit_employment_type').value = employee.employment_type;
            document.getElementById('edit_employee_type').value = employee.employee_type;
            document.getElementById('edit_department_id').value = employee.department_id;
            document.getElementById('edit_employee_status').value = employee.employee_status;
            document.getElementById('edit_gender').value = employee.gender;
            
            updateEditSections();
            setTimeout(() => {
                document.getElementById('edit_section_id').value = employee.section_id;
            }, 100);
            
            handleEditEmployeeTypeChange();
            
            document.getElementById('editModal').style.display = 'block';
        }
        
        function hideEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        function handleEmployeeTypeChange() {
            const employeeType = document.getElementById('employee_type').value;
            const departmentGroup = document.getElementById('department_group');
            const sectionGroup = document.getElementById('section_group');
            
            departmentGroup.style.display = 'none';
            sectionGroup.style.display = 'none';
            document.getElementById('department_id').value = '';
            document.getElementById('section_id').value = '';
            
            if (employeeType === 'managing_director' || employeeType === 'bod_chairman') {
                // No department or section
            } else if (employeeType === 'dept_head') {
                departmentGroup.style.display = 'block';
            } else {
                departmentGroup.style.display = 'block';
                sectionGroup.style.display = 'block';
            }
        }
        
        function updateSections() {
            const departmentId = document.getElementById('department_id').value;
            const sectionSelect = document.getElementById('section_id');
            
            sectionSelect.innerHTML = '<option value="">Select Section</option>';
            
            if (departmentId) {
                const sections = <?php echo json_encode($sections); ?>;
                sections.forEach(function(section) {
                    if (section.department_id == departmentId) {
                        const option = document.createElement('option');
                        option.value = section.id;
                        option.textContent = section.name;
                        sectionSelect.appendChild(option);
                    }
                });
            }
        }
        
        function handleEditEmployeeTypeChange() {
            const employeeType = document.getElementById('edit_employee_type').value;
            const departmentGroup = document.getElementById('edit_department_group');
            const sectionGroup = document.getElementById('edit_section_group');
            
            departmentGroup.style.display = 'none';
            sectionGroup.style.display = 'none';
            document.getElementById('edit_department_id').value = '';
            document.getElementById('edit_section_id').value = '';
            
            if (employeeType === 'managing_director' || employeeType === 'bod_chairman') {
                // No department or section
            } else if (employeeType === 'dept_head') {
                departmentGroup.style.display = 'block';
            } else {
                departmentGroup.style.display = 'block';
                sectionGroup.style.display = 'block';
            }
        }
        
        function updateEditSections() {
            const departmentId = document.getElementById('edit_department_id').value;
            const sectionSelect = document.getElementById('edit_section_id');
            
            sectionSelect.innerHTML = '<option value="">Select Section</option>';
            
            if (departmentId) {
                const sections = <?php echo json_encode($sections); ?>;
                sections.forEach(function(section) {
                    if (section.department_id == departmentId) {
                        const option = document.createElement('option');
                        option.value = section.id;
                        option.textContent = section.name;
                        sectionSelect.appendChild(option);
                    }
                });
            }
        }
        
        window.onclick = function(event) {
            const addModal = document.getElementById('addModal');
            const editModal = document.getElementById('editModal');
            if (event.target == addModal) {
                hideAddModal();
            } else if (event.target == editModal) {
                hideEditModal();
            }
        }
    </script>
</body>
</html>
```