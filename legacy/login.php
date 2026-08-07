<?php
session_start();


// Redirect to index.php if the user is already logged in
if (isset($_SESSION["logged"]) && $_SESSION["logged"] === true) {
    header("Location: index.php");
    exit();
}

// Prevent browser from caching the page
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1
header("Pragma: no-cache"); // HTTP 1.0
header("Expires: 0"); // Proxies

$login_err = "";

// Enable error reporting for development (optional)
error_reporting(E_ALL);
ini_set('display_errors', 1);

require "config.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['employee_id']) && isset($_POST['password'])) {
        $inputID = $_POST['employee_id'];
        $inputPassword = $_POST['password'];

        // Prepare data for API login request
        $postData = json_encode([
            "employee_id" => $inputID,
            "password" => $inputPassword
        ]);

        // Step 1: Send login request to API to get the token
        $ch = curl_init("http://172.16.51.98:8080/api/public/greyhoundhub/login");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($postData)
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        $response = curl_exec($ch);
        unset($ch);

        $result = json_decode($response, true);

        if (!$result) {
            $login_err = "Error: API did not return valid JSON.";
        } elseif (isset($result['status']) && $result['status'] === 200) {
            // Step 2: Successful login, save session details and token
            $_SESSION["logged"] = true;
            $_SESSION["user_id"] = $result['id'];
            $_SESSION["employee_id"] = $inputID;
            $_SESSION["token"] = $result['token']; // Save the token for future use

            // Step 3: Fetch additional user details using the token
            $ch = curl_init("http://172.16.51.98:8080/api/public/greyhoundhub/login-check");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $_SESSION["token"],
                'Content-Type: application/json'
            ]);

            $userDetailsResponse = curl_exec($ch);
            unset($ch);

            $userDetails = json_decode($userDetailsResponse, true);

            // Accessing 'user' object in response
            if ($userDetails && isset($userDetails['user'])) {
                // Extract user information from 'user' object
                $userData = $userDetails['user'];
                $name = $userData['name'];
                $department = $userData['department'];
                $position = $userData['position'];
                $email_address = $userData['email_address'];

                // Step 4: Save or update user details in the local database
                $stmt = $conn->prepare("
                    INSERT INTO user (employee_id, name, department, position, email_address) 
                    VALUES (?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE 
                        name = VALUES(name), 
                        department = VALUES(department), 
                        position = VALUES(position), 
                        email_address = VALUES(email_address)
                ");
                $stmt->bind_param("sssss", $inputID, $name, $department, $position, $email_address);
                $stmt->execute();
                $stmt->close();

                // Step 5: Log the login action in admin_activity_logs
                $action = 'login';
                $details = "Admin user {$inputID} logged in.";
                $timestamp = date("Y-m-d H:i:s");

                $log_stmt = $conn->prepare("
                    INSERT INTO activity (id, action, details, timestamp) 
                    VALUES (?, ?, ?, ?)
                ");
                $log_stmt->bind_param("isss", $inputID, $action, $details, $timestamp);
                $log_stmt->execute();
                $log_stmt->close();

                // Redirect to home.php after successful login and data save
                header("Location: index.php");
                exit();
            } else {
                $login_err = "Error: Failed to retrieve user details.";
            }
        } else {
            $login_err = isset($result['message']) ? $result['message'] : "Invalid employee ID or password.";
        }
    } else {
        $login_err = "Please enter both employee ID and password.";
    }
            // If API login fails, attempt local database authentication
            if (!$result || !isset($result['status']) || $result['status'] !== 200) {
                $stmt = $conn->prepare("SELECT employee_id, name, department, position, email_address, password FROM user WHERE employee_id = ?");
                $stmt->bind_param("s", $inputID);
                $stmt->execute();
                $result = $stmt->get_result();
                $stmt->close();
    
                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();
    
                    // Verify password (plain text; consider hashing for security)
                    if ($inputPassword === $user["password"]) {
                        $_SESSION["logged"] = true;
                        $_SESSION["employee_id"] = $user["employee_id"];
                        $_SESSION["name"] = $user["name"];
                        $_SESSION["department"] = $user["department"];
                        $_SESSION["position"] = $user["position"];
                        $_SESSION["email"] = $user["email_address"];
    
                        // Log login action
                        $action = 'login';
                        $details = "User {$inputID} logged in via local database.";
                        $timestamp = date("Y-m-d H:i:s");
    
                        $log_stmt = $conn->prepare("
                            INSERT INTO activity (user_id, action, details, timestamp) 
                            VALUES (?, ?, ?, ?)
                        ");
                        $log_stmt->bind_param("isss", $inputID, $action, $details, $timestamp);
                        $log_stmt->execute();
                        $log_stmt->close();
    
                        // Redirect to index.php after successful login
                        header("Location: index.php");
                        exit();
                    } else {
                        $login_err = "Invalid password.";
                    }
                } else {
                    $login_err = "Invalid employee ID or password.";
                }
            }
    
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Login - GreyHound Hub</title>

    <!-- Vendor CSS Files -->
    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    
    <!-- Main CSS File -->
    <link href="assets/css/style.css" rel="stylesheet">

    <!-- Inline CSS for Background Image -->
    <style>
        body {
            background-image: url('assets/img/background-1.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
        }
    </style>
</head>
<body>
<main>
    <div class="container">
        <section class="section register min-vh-100 d-flex flex-column align-items-center justify-content-center py-4">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-lg-4 col-md-6 d-flex flex-column align-items-center justify-content-center">
                        <div class="d-flex justify-content-center py-4">
                        </div>

                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="pt-4 pb-2">
                                    <h5 class="card-title text-center pb-0 fs-4">Login to Your Account</h5>
                                </div>

                                <!-- Display Error Message -->
                                <?php if (!empty($login_err)): ?>
                                    <div class="alert alert-danger" role="alert">
                                        <?php echo htmlspecialchars($login_err); ?>
                                    </div>
                                <?php endif; ?>

                                <form class="row g-3 needs-validation" method="POST" novalidate>
                                    <div class="col-12">
                                        <div class="input-group has-validation">
                                            <input type="text" name="employee_id" class="form-control" id="yourEmployeeID" placeholder="Employee ID" required>
                                            <div class="invalid-feedback">Please enter your employee ID.</div>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <input type="password" name="password" class="form-control" id="yourPassword" placeholder="Password" required>
                                        <div class="invalid-feedback">Please enter your password!</div>
                                    </div>

                                    <div class="col-12">
                                        <button type="submit" style="width: 100%; background-color: #9A1B2F; color: #fff; border: 2px solid #9A1B2F; padding: 10px; font-size: 16px; border-radius: 5px; cursor: pointer;">
                                            Login
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</main>

<!-- Vendor JS Files -->
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/jquery/jquery.min.js"></script>
<script src="assets/vendor/fontawesome/js/all.min.js"></script>

<!-- Main JS File -->
<script src="assets/js/main.js"></script>
</body>
</html>

