<?php
require "config.php";
require 'login-check.php';
// Check if the user is logged in
$employee_id = $_SESSION['employee_id'] ?? null;

// Redirect to login if user is not logged in
if (!$employee_id) {
    header("Location: login.php");
    exit;
}

// Fetch user details from the new database structure
$sql = "SELECT employee_id, name, department, position, email_address FROM user WHERE employee_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $employee_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// If no user details are found, redirect to login page
if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<title>User Profile</title>

<?php
require 'head.php';?>

<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<main id="main" class="main">
    <div class="profile-card-container">
        <div class="card profile-card">
          <!-- Header with profile picture and name -->
          <div class="profile-header text-center">
                <img src="https://studentlogs.foundationu.com/Photo/<?php echo htmlspecialchars($user['employee_id']); ?>.JPG" 
                     alt="Profile" class="rounded-circle profile-image" width="120" height="120"
                     onerror="this.onerror=null; this.src='assets/img/default-profile.png';">
                <h2><?php echo htmlspecialchars($user['name']); ?></h2>
                <h4><?php echo htmlspecialchars($user['position']); ?></h4>
            </div>

            <!-- Profile details section -->
            <div class="profile-body">
                <div class="about-section text-center">
                    <p>Welcome, <?php echo htmlspecialchars($user['name']); ?>! </p>
                </div>

                <div class="profile-content">
                    <h5 class="text-center">Profile Details</h5>
                    <div class="row mt-3">
                        <div class="col-4 col-md-3 label">Employee ID</div>
                        <div class="col-8 col-md-9"><?php echo htmlspecialchars($user['employee_id']); ?></div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-4 col-md-3 label">Full Name</div>
                        <div class="col-8 col-md-9"><?php echo htmlspecialchars($user['name']); ?></div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-4 col-md-3 label">Department</div>
                        <div class="col-8 col-md-9"><?php echo htmlspecialchars($user['department']); ?></div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-4 col-md-3 label">Position</div>
                        <div class="col-8 col-md-9"><?php echo htmlspecialchars($user['position']); ?></div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-4 col-md-3 label">Email</div>
                        <div class="col-8 col-md-9"><?php echo htmlspecialchars($user['email_address']); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<script src="assets/js/main.js"></script>

<?php
require 'footer.php';
?>

<a href="#" class="back-to-top"><i class="bi bi-arrow-up-short"></i></a>

</body>
</html>
