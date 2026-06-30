<?php
session_start();

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $errorMessage = 'Please enter both username and password.';
    } else {
        $dbHost = 'localhost';
        $dbUser = 'root';
        $dbPass = '';
        $dbName = 'sps';

        $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

        if ($mysqli->connect_error) {
            error_log('Database connection failed: ' . $mysqli->connect_error);
            $errorMessage = 'Unable to connect to the database.';
        } else {
            $stmt = $mysqli->prepare('SELECT id, email, password, role FROM staff WHERE email = ? LIMIT 1');
            if (!$stmt) {
                error_log('Prepare failed: ' . $mysqli->error);
                $errorMessage = 'Login failed.';
            } else {
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows === 1) {
                    $stmt->bind_result($userId, $dbEmail, $dbPasswordHash, $dbRole);
                    $stmt->fetch();

                    if (password_verify($password, $dbPasswordHash)) {
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['email'] = $dbEmail;
                        $_SESSION['role'] = $dbRole;
                        $_SESSION['logged_in'] = true;

                        $stmt->close();
                        $mysqli->close();

                        $redirectPage = '/sps/pages/dashboard.php';
                        if (strtolower($dbRole) === 'technician') {
                            $redirectPage = '/sps/pages/technician_dashboard.php';
                        } elseif (strtolower($dbRole) === 'staff') {
                            $redirectPage = '/sps/pages/staff_dashboard.php';
                        } elseif (strtolower($dbRole) === 'admin') {
                            $redirectPage = '/sps/pages/admin_dashboard.php';
                        }

                        header('Location: ' . $redirectPage);
                        exit;
                    }
                }

                $stmt->close();
                $mysqli->close();

                $errorMessage = 'Invalid username or password.';
            }
        }
    }
}

if ($errorMessage !== '') {
    echo '<p style="color: red;">' . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') . '</p>';
}
?>