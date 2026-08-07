<?php
require 'head.php';
require 'login-check.php';
require 'config.php';

$pageTitle = 'Recent Views';

// Function to convert a stored filesystem path into a browser-accessible URL
function convertFilePathToURL($filePath) {
    $normalizedFilePath = str_replace('\\', '/', $filePath);

    // Map common local paths to web-accessible URLs.
    if (stripos($normalizedFilePath, '/Applications/XAMPP/xamppfiles/htdocs/testcreative') === 0
        || stripos($normalizedFilePath, 'C:/xampp/htdocs/testcreative') === 0) {
        $baseURL = 'http://localhost/testcreative';
        $basePath = stripos($normalizedFilePath, '/Applications/XAMPP/xamppfiles/htdocs/testcreative') === 0
            ? '/Applications/XAMPP/xamppfiles/htdocs/testcreative'
            : 'C:/xampp/htdocs/testcreative';
        $relative_path = ltrim(substr($normalizedFilePath, strlen($basePath)), '/');
        return $baseURL . '/' . str_replace(' ', '%20', $relative_path);
    }

    if (stripos($normalizedFilePath, 'C:/xampp/htdocs/greyhound-admin-2025-final_greyhound_admin_2025') === 0) {
        $baseURL = 'http://localhost/greyhound-admin-2025-final_greyhound_admin_2025';
        $relative_path = ltrim(substr($normalizedFilePath, strlen('C:/xampp/htdocs/greyhound-admin-2025-final_greyhound_admin_2025')), '/');
        return $baseURL . '/' . str_replace(' ', '%20', $relative_path);
    }

    return '#';
}

// Function to fetch recent file paths for all employees
function getRecentFilePaths($conn) {
    $query = "SELECT r.item_name, r.item_type, r.filepath, r.timestamp, r.employee_id, u.name AS uploader_name
              FROM recent r
              LEFT JOIN `user` u ON r.employee_id = u.employee_id
              WHERE r.item_type = 'file'
              ORDER BY r.timestamp DESC
              LIMIT 20";

    $result = $conn->query($query);
    if (!$result) {
        die("Query Failed: " . $conn->error);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Fetch recent files directly from the database
$recentFiles = getRecentFilePaths($conn);

// Do not require physical file existence for recent view; display all database records.
foreach ($recentFiles as &$file) {
    $file['filepath'] = str_replace('\\', '/', $file['filepath']);
}
unset($file);

// Function to convert timestamp to Philippine Time (PHT) in 12-hour format
function convertTimestamp($timestamp) {
    $dateTime = new DateTime($timestamp, new DateTimeZone('UTC'));
    $dateTime->setTimezone(new DateTimeZone('Asia/Manila'));
    $dateTime->modify('-1 hour');
    return $dateTime->format('Y-m-d h:i:s A');
}
?>
    

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Recent Files</title>
    <!-- Proper order of scripts in the head or at the end of the body -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js"></script>

    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.5/css/jquery.dataTables.min.css" rel="stylesheet">
    <!-- Include Bootstrap, DataTables, and FontAwesome -->
    <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js"></script>
    <style>
        /* ✅ Center the entire table body content horizontally */
        #fileTable td {
            vertical-align: middle;
        }


        #file-preview-content {
            display: flex;
            justify-content: center;
            align-items: center;
            max-width: 90vw;  /* Adjusted for wider view */
            max-height: 90vh; /* Adjusted for better centering */
            overflow: hidden;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%); /* Centers the preview */

            border-radius: 8px;
            padding: 10px;
            z-index: 1100;
        }

        #file-preview-content img,
        #file-preview-content video {
            max-width: 100%;
            max-height: 90vh;
            object-fit: contain;
            border-radius: 5px;
        }


        /* Ensure container uses full page width */
        .container {
            max-width: 100%; /* Remove any container width restrictions */
            padding-left: 10px;
            padding-right: 10px;
            margin: 0 auto; /* Center the container if necessary */
        }

        /* Grid View Styles */
        .grid-view {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-start; /* Align grid items to the left */
            gap: 10px; /* Add spacing between grid items */
        }

        .grid-view .grid-item {
            display: flex;
            flex-direction: column;
            width: 23%;
            margin-bottom: 15px;
            text-align: center;
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .grid-view .grid-item:hover {
            transform: scale(1.03); /* Slight zoom effect on hover */
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .grid-view img, .grid-view video {
            width: 100%;
            max-height: 150px;
            object-fit: cover;
            border-radius: 5px;
            margin-bottom: 10px;
            cursor: pointer;
        }

        .grid-view .file-info {
            text-align: center;
            font-size: 14px;
        }

        .grid-view .actions {
            margin-top: 10px;
            display: flex;
            justify-content: space-around;
            gap: 5px;
        }

        .grid-view .actions button {
            font-size: 12px;
        }
        #file-preview-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background-color: rgba(0, 0, 0, 0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1050;
        }

        #prev-btn {
            left: 10px;
        }

        #next-btn {
            right: 10px;
        }

        #file-preview-close {
            position: absolute;
            top: 20px; /* Adjusted position from the top */
            left: 20px; /* Adjusted position from the left */
            font-size: 2rem; /* Font size for the close button */
            color: white; /* Color set to white for visibility */
            background: none; /* No background */
            border: none; /* No border */
            cursor: pointer; /* Pointer cursor on hover */
            z-index: 1100; /* Ensure it’s above everything else */
            margin-top: 40px; /* Adjust this value as needed */
        }
        .view-buttons {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 10px;
        }
        .view-buttons .btn-group {
            display: flex;
            border: 1px solid #ccc;
            border-radius: 50px;
            overflow: hidden;
            background-color: #f8f9fa;
        }
        .view-buttons .btn {
            border: none;
            background: none;
            color: #495057;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
        }
        .view-buttons .btn.active {
            background-color: #007bff;
            color: white;
        }
        .view-buttons .btn i {
            font-size: 18px;
        }
        #fileTable img, #fileTable video {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 5px;
            cursor: pointer;
        }

        /* Apply truncation to file names */
        .grid-item .file-info {
            max-width: 150px;  /* Adjust width as needed */
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Show full file name on hover */
        /* .grid-item .file-info:hover {
            overflow: visible;
            white-space: normal;
            background-color: rgba(255, 255, 255, 0.9);
            padding: 5px;
            border-radius: 4px;
            position: absolute;
            z-index: 10;
        } */
        /* ✅ Make thumbnails smaller and center in the first column */
        #fileTable img.file-thumbnail,
        #fileTable video.file-thumbnail {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 5px;
            cursor: pointer;
        }

        /* ✅ Center the FILE column (image/video) horizontally */
        #fileTable td:nth-child(1),
        #fileTable th:nth-child(1) {
            text-align: center;
            vertical-align: middle;
        }

    </style>
</head>
<body>

<?php include 'header.php'; ?>

<?php include 'sidebar.php'; ?>



<main id="main" class="main">
    <div class="pagetitle">
        <h1><?php echo $pageTitle; ?></h1>
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">Home</a></li>
            <li class="breadcrumb-item active"><?php echo $pageTitle; ?></li>
        </ol>
    </div>

    <div class="d-flex justify-content-end align-items-center mb-3">
        <div class="btn-group ms-2">
            <!-- List View Button -->
            <button id="listViewBtn" class="btn btn-outline-primary active" title="List View" onclick="switchToListView()">
                <i class="fas fa-list"></i>
            </button>

            <!-- Grid View Button -->
            <button id="gridViewBtn" class="btn btn-outline-secondary" title="Grid View" onclick="switchToGridView()">
                <i class="fas fa-th-large"></i>
            </button>
        </div>
    </div>

    <div id="fileContainer" class="list-view table-responsive">
        <table class="table table-hover table-striped" id="fileTable">
            <thead>
            <tr>
                <th>FILE</th>
                <th>FILE NAME</th>
                <th>RECENT FILES</th>
                <th>TIMESTAMP</th>
                <th>FILE PATH</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($recentFiles as $file): ?>
                <?php
                $fileUrl = convertFilePathToURL($file['filepath']);
                $fileType = pathinfo($file['filepath'], PATHINFO_EXTENSION);
                // If no extension is found, assume 'jpg'
                if (!$fileType) {
                    $fileType = 'jpg';
                }
                $localTimestamp = convertTimestamp($file['timestamp']);
                $profileImageUrl = "https://studentlogs.foundationu.com/Photo/" . htmlspecialchars($file['employee_id']) . ".JPG";
                ?>
                <tr>
                    <td style="text-align: center; vertical-align: middle;">
                        <?php if (preg_match('/(jpg|jpeg|png|gif|webp)$/i', $fileType)): ?>
                            <img class="file-thumbnail"
                                 src="<?php echo $fileUrl; ?>"
                                 data-file-url="<?php echo $fileUrl; ?>"
                                 data-file-type="<?php echo $fileType; ?>"
                                 onclick="openPreviewModal('<?php echo $fileUrl; ?>', '<?php echo $fileType; ?>')">
                        <?php elseif (preg_match('/(mp4|mov)$/i', $fileType)): ?>
                            <video class="file-thumbnail"
                                   src="<?php echo $fileUrl; ?>"
                                   controls
                                   data-file-url="<?php echo $fileUrl; ?>"
                                   data-file-type="<?php echo $fileType; ?>"
                                   onclick="openPreviewModal('<?php echo $fileUrl; ?>', '<?php echo $fileType; ?>')"></video>
                        <?php else: ?>
                            <span>No Preview</span>
                        <?php endif; ?>
                    </td>

                    <td>
            <span class="file-name">
                <?php echo htmlspecialchars($file['item_name']); ?>
            </span>
                    </td>
                    <td>
                        <img src="<?php echo $profileImageUrl; ?>"
                             alt="Profile"
                             class="rounded-circle profile-image"
                             style="width: 25px; height: 25px; object-fit: cover; margin-right: 5px;">
                        <?php echo htmlspecialchars($file['uploader_name']); ?>
                    </td>

                    <td data-order="<?php echo htmlspecialchars($file['timestamp']); ?>">
                        <?php echo htmlspecialchars($localTimestamp); ?>
                    </td>
                    <td>
    <span class="file-path" data-fullpath="<?php echo htmlspecialchars($file['filepath']); ?>" data-bs-toggle="tooltip" title="<?php echo htmlspecialchars($file['filepath']); ?>">
        <?php echo htmlspecialchars($file['filepath']); ?>
    </span>
                    </td>

                </tr>
            <?php endforeach; ?>

            </tbody>
        </table>
    </div>


    <!-- ✅ CSS to visually truncate the file path -->
    <style>
        .file-path {
            display: inline-block;
            max-width: 250px; /* Adjust width as needed */
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
        }
    </style>

    <!-- ✅ JavaScript to ensure hover tooltip works -->

    <script>
        $(document).ready(function(){
            $('[data-bs-toggle="tooltip"]').tooltip();
        });
    </script>





    <div id="fileGrid" class="grid-view" style="display: none;">
        <?php foreach ($recentFiles as $file): ?>
            <?php
            $fileUrl = convertFilePathToURL($file['filepath']);
            $fileType = pathinfo($file['filepath'], PATHINFO_EXTENSION);
            ?>
            <div class="grid-item">
                <?php if (preg_match('/(jpg|jpeg|png|gif|webp)$/i', $fileType)): ?>
                    <img class="file-thumbnail"
                         src="<?php echo $fileUrl; ?>"
                         alt="<?php echo htmlspecialchars($file['item_name']); ?>"
                         data-file-url="<?php echo $fileUrl; ?>"
                         data-file-type="<?php echo $fileType; ?>">
                <?php elseif (preg_match('/(mp4|mov)$/i', $fileType)): ?>
                    <video class="file-thumbnail"
                           src="<?php echo $fileUrl; ?>"
                           controls
                           data-file-url="<?php echo $fileUrl; ?>"
                           data-file-type="<?php echo $fileType; ?>"></video>
                <?php else: ?>
                    <p>Preview not available</p>
                <?php endif; ?>
                <div class="file-info">
                    <p class="file-name" title="<?php echo htmlspecialchars($file['item_name']); ?>">
                        <?php echo htmlspecialchars($file['item_name']); ?>
                    </p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>



</main>

<!-- File Preview Overlay (Same as Trash) -->
<div id="file-preview-overlay" style="display: none;">
    <button id="prev-btn" class="navigation-btn">&#8249;</button> <!-- Previous -->
    <div id="file-preview-content"></div>
    <button id="next-btn" class="navigation-btn">&#8250;</button> <!-- Next -->
    <button id="close-preview-btn" class="close-btn">&#10005;</button> <!-- Close -->
</div>




<div id="file-preview-overlay">
    <button id="close-preview-btn">&#10005;</button>
    <div id="file-preview-content"></div>
</div>


<script>
    $(document).ready(function () {
        let currentIndex = 0;
        let currentFiles = [];

        // ✅ Function to update the file list (Grid & List View)
        function updateFileList() {
            currentFiles = $('.file-thumbnail').map(function () {
                return {
                    url: $(this).attr('data-file-url'),
                    type: $(this).attr('data-file-type')
                };
            }).get();
        }

        // ✅ Function to open file preview modal
        function openModal(fileUrl, fileType) {
            let previewHTML;
            if (fileType.match(/(jpg|jpeg|png|gif|webp)$/i)) {   // ✅ Now supports webp
                previewHTML = `<img src="${fileUrl}" alt="File Preview">`;
            } else if (fileType.match(/(mp4|mov)$/i)) {
                previewHTML = `<video controls><source src="${fileUrl}" type="video/mp4">Your browser does not support video.</video>`;
            } else {
                previewHTML = `<p>Unsupported file type</p>`;
            }

            $('#file-preview-content').html(previewHTML);
            $('#file-preview-overlay').fadeIn();
        }


        // ✅ Function to navigate between files
                    function navigateFile(direction) {
                        if (direction === 'next' && currentIndex < currentFiles.length - 1) {
                        currentIndex++;
                    } else if (direction === 'prev' && currentIndex > 0) {
                        currentIndex--;
                    } else {
                        console.log("Navigation limit reached");
                        return;
                    }

                        const currentFile = currentFiles[currentIndex];
                        openModal(currentFile.url, currentFile.type);
                    }

                    // ✅ Click event for file thumbnails (Grid & List View)
                    $(document).on('click', '.file-thumbnail', function () {
                        updateFileList(); // Refresh file list
                        currentIndex = $('.file-thumbnail').index(this); // Get index of clicked file
                        const currentFile = currentFiles[currentIndex];
                        openModal(currentFile.url, currentFile.type);
                    });

                    // ✅ Next & Previous buttons
                    $('#next-btn').off().on('click', () => navigateFile('next'));
                    $('#prev-btn').off().on('click', () => navigateFile('prev'));

                    // ✅ Allow Left/Right Arrow Key Navigation
                    $(document).off("keydown").on("keydown", (e) => {
                        if ($("input, textarea").is(":focus")) return; // Prevent navigation inside text inputs

                        if (e.key === "ArrowRight") {
                        console.log("➡️ [DEBUG] Right arrow key pressed. Navigating to next file.");
                        navigateFile('next');
                    } else if (e.key === "ArrowLeft") {
                        console.log("⬅️ [DEBUG] Left arrow key pressed. Navigating to previous file.");
                        navigateFile('prev');
                    }
                    });


                    // ✅ Close preview
                    // ✅ Ensure the close button properly hides the preview overlay
                    $("#close-preview-btn").on("click", function () {
                        $("#file-preview-overlay").fadeOut();
                    });

                    // ✅ Allow closing the preview with the "Escape" (Esc) key
                    $(document).on("keydown", function (e) {
                        if (e.key === "Escape") {
                        console.log("❌ [DEBUG] Escape key pressed. Closing preview.");
                        $("#file-preview-overlay").fadeOut();
                    }
                    });

                    // ✅ Close on overlay click (outside content)
                    $('#file-preview-overlay').off().on('click', function (e) {
                        if (e.target.id === 'file-preview-overlay') {
                        $('#file-preview-overlay').fadeOut();
                    }
                    });
                    });





</script>
<script>
    $(document).ready(function() {
        $('#fileTable').DataTable({
            "order": [[3, "desc"]], // Sort by the 4th column (timestamp) descending
            "pageLength": 500,        // Default number of rows per page
            "lengthMenu": [ [500, 1000, 1500, 2000], [500, 1000, 1500, 2000] ],
            "dom": 'lfrtip'         // Show the page length dropdown (l), search box (f), and pagination controls (p)
        });
    });


    function openPreviewModal(fileUrl, fileType) {
        const overlay = document.getElementById('file-preview-overlay');
        const content = document.getElementById('file-preview-content');
        content.innerHTML = ""; // Clear previous content

        let previewElement;

        if (fileType.match(/(jpg|jpeg|png|gif)$/i)) {
            previewElement = document.createElement("img");
        } else if (fileType.match(/(mp4|mov)$/i)) {
            previewElement = document.createElement("video");
            previewElement.controls = true;
        } else {
            content.innerHTML = "<p>Preview not available for this file type.</p>";
            overlay.style.display = "flex";
            return;
        }

        previewElement.src = fileUrl;
        previewElement.style.maxWidth = "80vw";
        previewElement.style.maxHeight = "80vh";
        previewElement.style.objectFit = "contain";
        previewElement.style.borderRadius = "8px";

        content.appendChild(previewElement);
        overlay.style.display = "flex";
    }

    // ✅ Close preview on button click
    document.getElementById('close-preview-btn').addEventListener("click", function () {
        document.getElementById('file-preview-overlay').style.display = "none";
    });


    function switchToListView() {
        document.getElementById('fileContainer').style.display = "block";
        document.getElementById('fileGrid').style.display = "none";
        document.getElementById('listViewBtn').classList.add("active");
        document.getElementById('gridViewBtn').classList.remove("active");
    }

    function switchToGridView() {
        document.getElementById('fileContainer').style.display = "none";
        document.getElementById('fileGrid').style.display = "flex";
        document.getElementById('gridViewBtn').classList.add("active");
        document.getElementById('listViewBtn').classList.remove("active");
    }
</script>



<script src="assets/js/main.js"></script>

<?php require 'footer.php'; ?>
</body>
</html>