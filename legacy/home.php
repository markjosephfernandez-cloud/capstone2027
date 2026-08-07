<?php 
require 'login-check.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">

    <title>Greyhound Hub</title>
    <meta content="" name="description">
    <meta content="" name="keywords">

    <!-- Favicons -->
    <link href="assets/img/logoo.png" rel="icon">
    <link href="assets/img/apple-touch-icon.png" rel="apple-touch-icon">

    <!-- Google Fonts -->
    <link href="https://fonts.gstatic.com" rel="preconnect">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">

    <!-- Vendor CSS Files -->
    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="assets/vendor/quill/quill.snow.css" rel="stylesheet">
    <link href="assets/vendor/quill/quill.bubble.css" rel="stylesheet">
    <link href="assets/vendor/remixicon/remixicon.css" rel="stylesheet">
    <link href="assets/vendor/simple-datatables/style.css" rel="stylesheet">

    <!-- Template Main CSS File -->
    <link href="assets/css/style.css" rel="stylesheet">
</head>

<body>

<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<main id="main" class="main">

    <div class="pagetitle">
        <h1>Home</h1>
    </div>

    <!-- Media Content Section Start Here -->
    <div class="container mt-5">

        <!-- Welcome Message -->
        <div class="text-center mb-5">
            <h2>Welcome to Drive</h2>
        </div>

        <!-- Include the Search Bar and Filter Options -->
        <?php include 'filter-options.php'; ?>

        <!-- Suggested Folders Section -->
        <div class="folder-suggestion">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5>Suggested folders</h5>
                <a href="#" class="text-primary">View more</a>
            </div>
            <div class="folder-container">
                <a href="directory-listing.php" class="folder-card-link">
                    <div class="folder-card p-3 border rounded d-flex align-items-center">
                        <i class="bi bi-folder-fill me-2"></i>
                        <span>My Folder</span><br>
                        <small>In My Drive</small>
                    </div>
                </a>
            </div>
        </div>

            <!-- List View -->
            <div class="suggested-files-table" id="list-view">
                <div class="table-container">
                    <table class="table table-borderless">
                        <thead>
                            <tr class="text-secondary">
                                <th>Name</th>
                                <th>Owner</th>
                                <th>Location</th>
                            </tr>
                        </thead>
                        <tbody id="list-view-content">
                            <!-- Dynamic content will be inserted here -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Grid View -->
            <div class="suggested-files-grid d-none" id="grid-view">
                <div class="row" id="grid-view-content">
                    <!-- Dynamic content will be inserted here -->
                </div>
            </div>
        </div>
    </div>

</main><!-- End Main -->


<a href="#" class="back-to-top d-flex align-items-center justify-content-center">
    <i class="bi bi-arrow-up-short"></i>
</a>

<!-- Vendor JS Files -->
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/chart.js/chart.umd.js"></script>
<script src="assets/vendor/echarts/echarts.min.js"></script>
<script src="assets/vendor/quill/quill.js"></script>
<script src="assets/vendor/simple-datatables/simple-datatables.js"></script>
<script src="assets/vendor/tinymce/tinymce.min.js"></script>
<script src="assets/vendor/php-email-form/validate.js"></script>

<!-- Template Main JS File -->
<script src="assets/js/main.js"></script>

<!-- Script to toggle between List View and Grid View -->
<script>
    document.getElementById('list-view-btn').addEventListener('click', function() {
        document.getElementById('list-view').classList.remove('d-none');
        document.getElementById('grid-view').classList.add('d-none');
    });

    document.getElementById('grid-view-btn').addEventListener('click', function() {
        document.getElementById('list-view').classList.add('d-none');
        document.getElementById('grid-view').classList.remove('d-none');
    });
</script>

</body>

</html>
