<?php
session_start();

// Database Connection Configuration
$host = 'sql101.infinityfree.com';
$db   = 'if0_42538348_gigpay_db';
$user = 'if0_42538348';
$pass = 'w6qxDZ0nlp1hr';
$charset = 'utf8mb4';
$conn = new mysqli($host, $user, $pass, $db);

// Check connection
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    // Prepare statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT id, username, full_name, password FROM admins WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $admin = $result->fetch_assoc();
        
        // Verify password hash
        if (password_verify($password, $admin['password'])) {
            // Set session variables
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_name'] = $admin['full_name'];
            
            // Redirect to admin portal page
            header("Location: admin.php");
            exit();
        } else {
            $_SESSION['error'] = "Invalid password.";
            header("Location: login.php");
            exit();
        }
    } else {
        $_SESSION['error'] = "Admin email not found.";
        header("Location: login.php");
        exit();
    }

    $stmt->close();
}
$conn->close();
?>