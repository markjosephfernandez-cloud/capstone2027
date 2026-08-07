<?php 
require 'head.php';
require 'login-check.php';
?>
<!DOCTYPE html>
<html lang="en">
<title>Settings - File Management</title>


<body>
<?php
// Include the header and sidebar
include 'header.php';
include 'sidebar.php';
?>

<!-- Main Content -->
<main id="main" class="main">
    <div class="pagetitle">
        <h1>File Management Settings</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item active">Settings</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section settings">
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Manage File Settings</h5>

                        <form action="settings.php" method="post">
                            <div class="row mb-3">
                                <label for="storageLimit" class="col-sm-3 col-form-label">Storage Limit (in GB)</label>
                                <div class="col-sm-9">
                                    <input type="number" class="form-control" id="storageLimit" name="storageLimit" value="100">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="fileTypes" class="col-sm-3 col-form-label">Allowed File Types</label>
                                <div class="col-sm-9">
                                    <input type="text" class="form-control" id="fileTypes" name="fileTypes" value="jpg, png, pdf">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="maxFileSize" class="col-sm-3 col-form-label">Max File Size (in MB)</label>
                                <div class="col-sm-9">
                                    <input type="number" class="form-control" id="maxFileSize" name="maxFileSize" value="10">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="fileRetention" class="col-sm-3 col-form-label">File Retention Period (in days)</label>
                                <div class="col-sm-9">
                                    <input type="number" class="form-control" id="fileRetention" name="fileRetention" value="30">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="uploadDirectory" class="col-sm-3 col-form-label">Upload Directory</label>
                                <div class="col-sm-9">
                                    <input type="text" class="form-control" id="uploadDirectory" name="uploadDirectory" value="/uploads">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-sm-9 offset-sm-3">
                                <button type="submit" style="background-color: maroon; color: white; border: none; padding: 10px 20px; font-size: 16px; border-radius: 5px; cursor: pointer;">Save Settings</button>
                                </div>
                            </div>
                        </form>

                    </div>
                </div>
            </div>
        </div>
    </section>
</main><!-- End Main Content -->

<a href="#" class="back-to-top d-flex align-items-center justify-content-center">
    <i class="bi bi-arrow-up-short"></i>
</a>

<?php require 'footer.php'; ?>
</body>

</html>
