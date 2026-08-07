<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start(); // Start session if not already started
}
require "config.php"; // Ensure this file establishes your database connection

// Check if the user is logged in
$employee_id = $_SESSION['employee_id'] ?? null;

$notifications = []; // Initialize notifications as empty
$user = []; // Initialize user details as empty

if ($employee_id) {
    // Fetch admin user details
    $sql = "SELECT employee_id, name, department, position, email_address 
            FROM user 
            WHERE employee_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $employee_id); // Use "s" if employee_id is a string
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    // Fetch the latest 5 admin activity logs for notifications
    // ✅ Fetch activity logs from `activity` table using `user_id`
    $notif_sql = "SELECT a.action, a.details, a.timestamp, u.name 
              FROM activity a
              JOIN user u ON a.user_id = u.employee_id
              WHERE a.user_id = ?
              ORDER BY a.timestamp DESC 
              LIMIT 5";


$notif_stmt = $conn->prepare($notif_sql);
$notif_stmt->bind_param("s", $employee_id);
$notif_stmt->execute();
$notif_result = $notif_stmt->get_result();
$notifications = $notif_result->fetch_all(MYSQLI_ASSOC);
$notif_stmt->close();


    // Convert timestamps to Philippine Time and adjust for 4-hour discrepancy
    date_default_timezone_set('Asia/Manila');
    foreach ($notifications as &$notification) {
        $notification['timestamp'] = date('Y-m-d H:i:s', strtotime($notification['timestamp']) - 4 * 3600);
    }
}
?>

<?php
// Handle potential redirections based on GET or POST parameters
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['redirect']) && $_GET['redirect'] === 'activity_logs') {
        header("Location: activity_logs.php");
        exit();
    } elseif (isset($_GET['redirect']) && $_GET['redirect'] === 'users_profile') {
        header("Location: users-profile.php");
        exit();
    } elseif (isset($_GET['redirect']) && $_GET['redirect'] === 'logout') {
        header("Location: logout.php");
        exit();
    }
}
?>


<header id="header" class="header fixed-top d-flex align-items-center">
    <div class="d-flex align-items-center justify-content-between">
        <a href="index.php" class="logo d-flex align-items-center">
            <img src="assets/img/logoo.png" alt="">
            <span class="d-none d-lg-block">Greyhound Hub</span>
        </a>
        <i class="bi bi-list toggle-sidebar-btn"></i>
    </div><!-- End Logo -->

    <nav class="header-nav ms-auto">
        <ul class="d-flex align-items-center">

            <!-- Notifications -->
            <li class="nav-item dropdown">
                <a class="nav-link nav-icon" href="#" data-bs-toggle="dropdown">
                <i class="bi bi-file-text"></i>
                <!-- <span class="badge bg-primary badge-number">
                        <?php echo isset($notifications) ? count($notifications) : 0; ?>
                    </span> -->
                </a><!-- End Notification Icon -->

                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow notifications">
                    <li class="dropdown-header">
                        Recent Activity
                        <a href="activity_logs.php"><span class="badge rounded-pill bg-primary p-2 ms-2">View All</span></a>
                    </li>
                    <li><hr class="dropdown-divider"></li>

                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $notification): ?>
                            <li class="dropdown-item">
    <i class="bi bi-info-circle text-primary"></i>
    <div>
        <strong><?php echo htmlspecialchars($notification['name']); ?></strong> <!-- Show Employee Name -->
        <br>
        <span><?php echo htmlspecialchars($notification['details']); ?></span>
        <span class="small text-muted d-block"><?php echo htmlspecialchars($notification['timestamp']); ?></span>
    </div>
</li>

                            <li><hr class="dropdown-divider"></li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="dropdown-item text-center">
                            <span class="small text-muted">No new notifications</span>
                        </li>
                    <?php endif; ?>
                </ul><!-- End Notification Dropdown -->
            </li><!-- End Notifications -->

          <!-- Profile -->
          <li class="nav-item dropdown pe-3">
                <a class="nav-link nav-profile d-flex align-items-center pe-0" href="#" data-bs-toggle="dropdown">
                    <img src="https://studentlogs.foundationu.com/Photo/<?php echo htmlspecialchars($user['employee_id'] ?? 'default'); ?>.JPG"
                         alt="Profile" class="rounded-circle" width="40" height="40"
                         onerror="this.onerror=null; this.src='assets/img/default-profile.png';">
                    <span class="d-none d-md-block dropdown-toggle ps-2"><?php echo htmlspecialchars($user['name'] ?? 'Admin'); ?></span>
                </a><!-- End Profile Image Icon -->

                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow profile">
                    <li class="dropdown-header">
                        <h6><?php echo htmlspecialchars($user['name'] ?? 'Admin'); ?></h6>
                        <span><?php echo htmlspecialchars($user['position'] ?? ''); ?></span>
                    </li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li>
    <a class="dropdown-item d-flex align-items-center" id="profileLink">
        <i class="bi bi-person"></i>
        <span>My Profile</span>
    </a>
</li>

<script>
    document.getElementById("profileLink").addEventListener("click", function() {
        window.location.href = "users-profile.php";
    });
</script>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li>
                    <a class="dropdown-item d-flex align-items-center" href="logout.php">
    <i class="bi bi-box-arrow-right"></i>
    <span>Sign Out</span>
</a>

                    </li>
                </ul><!-- End Profile Dropdown Items -->
            </li><!-- End Profile Nav -->
        </ul>
    </nav><!-- End Icons Navigation -->
</header><!-- End Header -->

