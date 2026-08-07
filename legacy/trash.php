<?php
date_default_timezone_set('Asia/Manila');

require 'head.php';
require "config.php";
require 'login-check.php';

$pageTitle = 'Trash';

// Set base directory and URL for trash files
$trash_directory = '/Applications/XAMPP/xamppfiles/htdocs/TRASH';
$trash_base_url = 'http://172.16.152.47/TRASH';

function convertFilePathToURL($filePath) {
    $basePath = '/Applications/XAMPP/xamppfiles/htdocs/TRASH';
    $baseURL  = 'http://172.16.152.47/TRASH';

    if (strpos($filePath, $basePath) === 0) {
        $relative_path = substr($filePath, strlen($basePath));
        $relative_path = ltrim($relative_path, '/');          // remove any leading slash
        $relative_path = str_replace('\\', '/', $relative_path);
        $relative_path = str_replace(' ', '%20', $relative_path);

        return $baseURL . '/' . $relative_path;  // e.g. http://172.16.152.47/TRASH/trashsample.jpeg
    }
    return false;
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);





function getTrashFilesFromDB($conn, $trash_directory, $trash_base_url) {
    $files = [];

    // JOIN with `files` and `user` to get filename, user name, etc.
    $query = "
        SELECT 
            tf.id AS trash_id,
            tf.file_id,
            tf.deleted_at,
            tf.deleted_by,
            u.name AS deleted_by_name,
            tf.reason,
            f.filename,
            f.filepath,
            f.filetype,
            f.size,
            f.dateupload
        FROM trashfiles tf
        LEFT JOIN files f ON tf.file_id = f.id
        LEFT JOIN user u ON tf.deleted_by = u.employee_id
        ORDER BY tf.deleted_at DESC
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("SQL error: " . $conn->error);
        return []; // Prevent white screen on SQL error
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $realFilePath = $trash_directory . '/' . $row['filename'];
        $fileUrl = convertFilePathToURL($realFilePath);

        if (file_exists($realFilePath)) {
            $files[] = [
                'id'            => $row['trash_id'],
                'filename'      => htmlspecialchars($row['filename']),
                'filepath'      => htmlspecialchars($row['filepath']),
                'real_filepath' => htmlspecialchars($realFilePath),
                'filetype'      => htmlspecialchars($row['filetype']),
                'size'          => $row['size'],
                'dateupload'    => $row['dateupload'],
                'deleted_by'    => htmlspecialchars($row['deleted_by_name'] ?? 'Unknown'),
                'reason'        => htmlspecialchars($row['reason'] ?? 'N/A'),
                'timestamp'     => date('Y-m-d h:i:s A', strtotime($row['deleted_at'])),
                'mtime'         => strtotime($row['deleted_at']),
                'thumbnail'     => $fileUrl
            ];
        }
    }

    return $files;
}






// Get trash files from the database ONLY
$trashFiles = getTrashFilesFromDB($conn, $trash_directory, $trash_base_url);

// Sort by newest files first
usort($trashFiles, function($a, $b) {
    return $b['mtime'] - $a['mtime'];
});


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Trash</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js"></script>

    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css" rel="stylesheet">

    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>

    <style>

        .btn-success {
            background-color: #5cb85c; /* Light green */
            border-color: #4cae4c;
        }

        #fileTable td {
            vertical-align: middle;
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
            z-index: 1050; /* Ensures the modal is above the header */
        }
        #file-preview-content {
            max-width: 80%;
            max-height: 80%;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 8px;
            padding: 10px;
            position: relative;
        }
        /* Navigation Buttons */
        .navigation-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            font-size: 2rem;
            background: none;
            border: none;
            cursor: pointer;
            color: white;
            z-index: 1100;
        }

        /* Move close button to left */
        .close-btn {
            top: 20px;
            left: 20px;
            font-size: 3rem;
        }

        /* Next & Previous Buttons */
        .prev-btn { left: 20px; }
        .next-btn { right: 20px; }

        .table img, .table video {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 5px;
            cursor: pointer;
        }
        /* Grid View Styles */
        .grid-view {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
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
        /* Align Search Bar & View Buttons */
        .d-flex.justify-content-end {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            align-items: center;
        }

        /* Apply truncation to file names */
        .grid-item .file-info {
            max-width: 150px;  /* Adjust width as needed */
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Show full file name on hover */
        .grid-item .file-info:hover {
            overflow: visible;
            white-space: normal;
            background-color: rgba(255, 255, 255, 0.9);
            padding: 5px;
            border-radius: 4px;
            position: absolute;
            z-index: 10;
        }

        /* Ensure the Search Bar Doesn't Shrink Too Much */
        #search {
            width: 200px;
            min-width: 180px;
        }

        /* Ensure Buttons Stay Next to Search Bar */
        .btn-group {
            display: flex;
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
            <button id="list-view-btn" class="btn btn-outline-primary active" title="List View"><i class="fas fa-list"></i></button>
            <button id="grid-view-btn" class="btn btn-outline-secondary" title="Grid View"><i class="fas fa-th-large"></i></button>
        </div>
    </div>



    <!-- ✅ Confirmation Modal -->
    <div class="modal fade" id="confirmationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmationModalLabel">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="confirmationModalBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmActionBtn">Proceed</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ✅ Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Success</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="successModalBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ✅ Error Modal -->
    <div class="modal fade" id="errorModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Error</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="errorModalBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>


    <!-- Bulk Action Buttons -->
    <!-- Bulk Action Buttons (initially hidden) -->
    <div class="action-button-container d-flex gap-2 mb-3 d-none">
        <!-- Bulk Delete -->
        <button type="button" class="btn btn-danger d-flex align-items-center" id="deleteSelectedBtn">
            <i class="fas fa-trash-alt me-2"></i> Delete Permanently
        </button>
        <!-- Bulk Restore (light green) -->
        <button type="button" class="btn btn-success d-flex align-items-center" id="restoreSelectedBtn">
            <i class="fas fa-undo me-2"></i> Restore Selected
        </button>
        <!-- (Other bulk buttons as needed) -->
    </div>


    <div id="fileContainer" class="table-responsive">
        <table class="table table-hover table-striped" id="fileTable">
            <thead>
            <tr>
                <th><input type="checkbox" id="selectAll"></th>
                <th>Thumbnail</th>
                <th>File Name</th>
                <th>File Path</th>
                <th>Type</th>
                <th>Deleted At</th>
                <th>Deleted By</th>
                <th>Reason</th>
                <th>Action</th>
            </tr>
            </thead>
            <?php
            function shortenPath($path, $maxLength = 50) {
                if (strlen($path) <= $maxLength) return $path;
                return substr($path, 0, $maxLength - 18) . '...';
            }
            ?>
            <tbody>
            <?php foreach ($trashFiles as $file): ?>
                <?php $shortFilepath = shortenPath($file['filepath'] ?? '', 50); ?>
                <tr data-path="<?php echo htmlspecialchars($file['real_filepath']); ?>">
                    <td>
                        <input type="checkbox" class="file-checkbox"
                               value="<?php echo htmlspecialchars($file['real_filepath']); ?>">
                    </td>
                    <td>
                        <?php if (!empty($file['thumbnail'])): ?>
                            <img src="<?php echo htmlspecialchars($file['thumbnail']); ?>"
                                 alt="Thumbnail"
                                 class="thumbnail"
                                 style="width: 40px; height: 40px; object-fit: cover; cursor: pointer;"
                                 onclick="openPreview('<?php echo htmlspecialchars($file['thumbnail']); ?>', '<?php echo htmlspecialchars($file['filetype']); ?>')">
                        <?php else: ?>
                            <span>No Preview</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($file['filename']); ?></td>
                    <td>
                    <span data-bs-toggle="tooltip" title="<?php echo htmlspecialchars($file['filepath']); ?>">
                        <?php echo htmlspecialchars($shortFilepath); ?>
                    </span>
                    </td>
                    <td><?php echo htmlspecialchars($file['filetype'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($file['timestamp'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($file['deleted_by'] ?? 'Unknown'); ?></td>
                    <td><?php echo htmlspecialchars($file['reason'] ?? ''); ?></td>
                    <td>
                        <button class="btn btn-sm btn-danger delete-file"
                                data-path="<?php echo htmlspecialchars($file['real_filepath']); ?>">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                        <button class="btn btn-sm btn-success restore-file"
                                data-realpath="<?php echo htmlspecialchars($file['real_filepath']); ?>"
                                data-originalpath="<?php echo htmlspecialchars($file['filepath']); ?>">
                            <i class="fas fa-undo"></i> Restore
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>



    <script>
        // Initialize Bootstrap tooltips using jQuery once the document is ready
        $(document).ready(function(){
            $('[data-bs-toggle="tooltip"]').tooltip();
        });
    </script>



    <!-- Grid View Container -->
    <!-- Grid View Container -->
    <div id="grid-view" class="grid-view d-none">
        <?php foreach ($trashFiles as $file): ?>
            <?php
            // Convert the file’s TRASH path to a valid URL for <img> or <video>.
            $fileURL = convertFilePathToURL($file['real_filepath']);

            // Ensure we have a lowercased extension to check.
            $ext      = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
            $fileName = htmlspecialchars($file['filename'], ENT_QUOTES);
            $fileType = htmlspecialchars($file['filetype'], ENT_QUOTES);
            ?>

            <div class="grid-item" data-path="<?php echo htmlspecialchars($file['filepath']); ?>">
                <?php if (in_array($ext, ['jpg','jpeg','png','gif'])): ?>
                    <!-- IMAGE PREVIEW -->
                    <img src="<?php echo htmlspecialchars($fileURL); ?>"
                         alt="Thumbnail"
                         style="width:100%; max-height:150px; object-fit:cover; cursor:pointer;"
                         onclick="openPreview('<?php echo htmlspecialchars($fileURL); ?>', '<?php echo $ext; ?>')">
                <?php else: ?>
                    <!-- NOT AN IMAGE -->
                    <span>No Preview</span>
                <?php endif; ?>

                <!-- Basic file info below the thumbnail -->
                <div class="file-info" style="margin-top:8px;">
                    <div title="<?php echo $fileName; ?>">
                        <!-- If you want to shorten the name if it's too long -->
                        <?php echo (strlen($fileName) > 20)
                            ? substr($fileName, 0, 20).'...'
                            : $fileName; ?>
                    </div>
                    <div><?php echo $fileType; ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>




    <!-- File Preview Modal -->
    <div id="file-preview-overlay" style="display: none;">
        <button id="close-preview-btn" class="navigation-btn close-btn">&#10005;</button>
        <button id="prev-btn" class="navigation-btn prev-btn">&#8249;</button>
        <button id="next-btn" class="navigation-btn next-btn">&#8250;</button>
        <div id="file-preview-content"></div>
    </div>



<script>
    $(document).ready(function () {
        // Bulk Restore: Trigger when the "Restore Selected" button is clicked
        // Bulk Restore: Trigger when the "Restore Selected" button is clicked
        $('#restoreSelectedBtn').click(function () {
            let selectedFiles = $('.file-checkbox:checked').map(function () {
                return $(this).val(); // These should be the TRASH paths (real_filepath)
            }).get();

            if (selectedFiles.length === 0) {
                showErrorModal("No files selected for restore.");
                return;
            }

            // Extract just the filenames for display
            let filenamesForDisplay = selectedFiles.map(filePath => filePath.split('/').pop()).join("<br>");

            // Open confirmation modal with the list of files to restore
            $("#confirmationModalLabel").html("Confirm Bulk Restore");
            $("#confirmationModalBody").html(`Are you sure you want to restore these files?<br><br>
        <strong>${filenamesForDisplay}</strong>`);
            $("#confirmationModal").modal("show");

            // Set confirm action for bulk restore
            $("#confirmActionBtn").off("click").on("click", function () {
                $("#confirmationModal").modal("hide");
                performBulkRestore(selectedFiles);
            });
        });

        function performBulkRestore(selectedFiles) {
            $.ajax({
                url: 'restoreFile.php',
                type: 'POST',
                data: JSON.stringify({ filepath: selectedFiles }), // Passing an array of TRASH paths
                contentType: 'application/json',
                dataType: 'json',
                success: function (response) {
                    if (response.status === 'success') {
                        showSuccessModal(response.message);
                        // Remove restored files from UI dynamically
                        selectedFiles.forEach(filePath => {
                            $(`input.file-checkbox[value="${filePath}"]`).closest('tr').remove();
                        });
                        // Uncheck "Select All" after restore
                        $('#selectAll').prop('checked', false);
                    } else {
                        showErrorModal("Error restoring files: " + response.message);
                    }
                },
                error: function (xhr) {
                    console.error("Error restoring files:", xhr.responseText);
                    showErrorModal("An error occurred while restoring files.");
                }
            });
        }


        // (Your existing code for bulk delete, individual delete, restore, tooltips, etc. remains intact.)
    });

</script>
            <!--Script for restore -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Attach click listeners to all .restore-file buttons
            document.querySelectorAll('.restore-file').forEach(button => {
                button.addEventListener('click', function() {
                    // Read the TRASH file path from data-realpath and the original path for display
                    const trashPath = this.getAttribute('data-realpath');
                    const originalPath = this.getAttribute('data-originalpath');
                    if (!trashPath) {
                        document.getElementById('errorModalBody').textContent = "Error: Trash file path is missing.";
                        new bootstrap.Modal(document.getElementById('errorModal')).show();
                        return;
                    }
                    // Use originalPath to extract the filename for display
                    const filename = originalPath.split('/').pop();
                    console.log("Restore button clicked. TRASH path:", trashPath);
                    console.log("Original path:", originalPath);
                    console.log("Filename:", filename);

                    // Update confirmation modal content
                    document.getElementById('confirmationModalLabel').textContent = "Confirm Restore";
                    document.getElementById('confirmationModalBody').innerHTML = `
                Are you sure you want to restore this file?<br><br>
                <strong>${filename}</strong>
            `;
                    document.getElementById('confirmActionBtn').textContent = "Restore File";

                    // Show the confirmation modal
                    const confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
                    confirmationModal.show();

                    // Remove any existing click handlers on the confirmation button to avoid duplicates
                    const confirmBtn = document.getElementById('confirmActionBtn');
                    confirmBtn.replaceWith(confirmBtn.cloneNode(true));
                    const newConfirmBtn = document.getElementById('confirmActionBtn');

                    // Attach a one‑time click handler for restoring the file
                    newConfirmBtn.addEventListener('click', async function() {
                        console.log("Restore confirmed for TRASH path:", trashPath);
                        confirmationModal.hide(); // Hide modal

                        try {
                            const response = await fetch('restoreFile.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ filepath: trashPath })
                            });
                            console.log("Restore fetch response:", response);
                            const result = await response.json();
                            console.log("Restore fetch result:", result);
                            if (result.status === 'success') {
                                document.getElementById('successModalBody').textContent = "File restored successfully.";
                                new bootstrap.Modal(document.getElementById('successModal')).show();
                                setTimeout(() => location.reload(), 2000);
                            } else {
                                document.getElementById('errorModalBody').textContent =
                                    "Error restoring file: " + result.message;
                                new bootstrap.Modal(document.getElementById('errorModal')).show();
                            }
                        } catch (error) {
                            console.error("Error restoring file:", error);
                            document.getElementById('errorModalBody').textContent =
                                "An unexpected error occurred.";
                            new bootstrap.Modal(document.getElementById('errorModal')).show();
                        }
                    }, { once: true });
                });
            });
        });

    </script>



    <script>

        function openPreview(fileUrl, fileType) {
            console.log("openPreview called with fileUrl:", fileUrl, "and fileType:", fileType);
            const overlay = document.getElementById('file-preview-overlay');
            const content = document.getElementById('file-preview-content');

            // Clear any previous content
            content.innerHTML = "";

            if (!fileUrl) {
                console.error("Error: fileUrl is empty.");
                content.innerHTML = "<p>Error: No preview available.</p>";
            } else {
                // For images, create an <img> element with an onerror fallback
                if (fileType.match(/(jpg|jpeg|png|gif)$/i)) {
                    const img = document.createElement("img");
                    img.src = fileUrl;
                    img.style.width = "100%";
                    img.style.maxHeight = "80vh";
                    img.style.objectFit = "contain";
                    // Fallback image if error occurs (adjust path as needed)
                    img.onerror = function() {
                        this.src = 'assets/img/no-preview.png';
                    };
                    content.appendChild(img);
                }
                // For video files
                else if (fileType.match(/(mp4|mov|avi)$/i)) {
                    const video = document.createElement("video");
                    video.src = fileUrl;
                    video.controls = true;
                    video.style.width = "100%";
                    video.style.maxHeight = "80vh";
                    video.style.objectFit = "contain";
                    content.appendChild(video);
                }
                // For unsupported types
                else {
                    content.innerHTML = "<p>Preview not available for this file type.</p>";
                }
            }

            // Show the overlay
            overlay.style.display = "flex";
        }

</script>
    <script>
        let currentFiles = []; // Global array to store file objects for preview
        let currentIndex = 0;  // Global index of the currently previewed file

        // Function to open the preview modal with the given file URL and type
        function openModal(fileUrl, fileType) {
            const overlay = $("#file-preview-overlay");
            const content = $("#file-preview-content");
            content.html(""); // Clear previous content

            if (!fileUrl) {
                content.html("<p>No preview available.</p>");
            } else if (fileType.match(/(jpg|jpeg|png|gif)$/i)) {
                content.append(`<img src="${fileUrl}" class="preview-media" style="width: 100%; max-height: 80vh; object-fit: contain;">`);
            } else if (fileType.match(/(mp4|mov|avi)$/i)) {
                content.append(`<video src="${fileUrl}" controls class="preview-media" style="width: 100%; max-height: 80vh; object-fit: contain;"></video>`);
            } else {
                content.append("<p>Preview not available for this file type.</p>");
            }
            overlay.fadeIn();
        }

        // Function to initialize preview with the collected files
        function initializeFilePreview(files, startIndex) {
            if (!files.length) return;
            currentFiles = files;
            currentIndex = startIndex;
            openModal(currentFiles[currentIndex].url, currentFiles[currentIndex].type);
        }

        // Navigation function for preview modal
        function navigateFile(direction) {
            if (direction === "next" && currentIndex < currentFiles.length - 1) {
                currentIndex++;
            } else if (direction === "prev" && currentIndex > 0) {
                currentIndex--;
            } else {
                console.log("Reached the limit.");
                return;
            }
            openModal(currentFiles[currentIndex].url, currentFiles[currentIndex].type);
        }

        // Function to collect files for preview from both Grid and List views
        function collectFiles() {
            currentFiles = []; // Reset the array

            // Collect files from Grid View
            $(".grid-item .thumbnail").each(function (index) {
                const fileUrl = $(this).attr("src");
                const fileType = $(this).closest(".grid-item").attr("data-type");
                // Only add if not already added
                if (!currentFiles.some(file => file.url === fileUrl)) {
                    currentFiles.push({ url: fileUrl, type: fileType });
                }
                // Bind click event for grid view thumbnails
                $(this).off("click").on("click", function () {
                    initializeFilePreview(currentFiles, index);
                });
            });

            // Collect files from List View (Table View)
            // Note: In the table, our columns are:
            // 1: checkbox, 2: thumbnail, 3: file name, 4: file path, 5: type, 6: timestamp, 7: action.
            $("#fileTable .thumbnail").each(function (index) {
                const fileUrl = $(this).attr("src");
                // Get file type from the 5th column of the table row
                const fileType = $(this).closest("tr").find("td:nth-child(5)").text().trim();
                if (!currentFiles.some(file => file.url === fileUrl)) {
                    currentFiles.push({ url: fileUrl, type: fileType });
                }
                // Bind click event for list view thumbnails
                $(this).off("click").on("click", function () {
                    initializeFilePreview(currentFiles, index);
                });
            });

            console.log("Collected files for preview:", currentFiles);
        }

        // Bind navigation buttons
        $(document).ready(function () {
            $("#prev-btn").on("click", function () {
                navigateFile("prev");
            });
            $("#next-btn").on("click", function () {
                navigateFile("next");
            });
            $("#close-preview-btn").on("click", function () {
                $("#file-preview-overlay").fadeOut();
            });

            // Initial file collection
            collectFiles();

            // Re-collect files when switching views
            $("#list-view-btn, #grid-view-btn").on("click", function () {
                collectFiles();
            });

            $(document).keydown(function(e) {
                // Check if the preview overlay is visible
                if ($("#file-preview-overlay").is(":visible")) {
                    // Left arrow key (37)
                    if (e.which === 37) {
                        navigateFile("prev");
                        e.preventDefault();
                    }
                    // Right arrow key (39)
                    else if (e.which === 39) {
                        navigateFile("next");
                        e.preventDefault();
                    }
                    // Escape key (27) to close preview
                    else if (e.which === 27) {
                        $("#file-preview-overlay").fadeOut();
                        e.preventDefault();
                    }
                }
            });

        });
    </script>


<script>

</script>
<script>
    $(document).ready(function () {
        $('#fileTable').DataTable({
            "pagingType": "full_numbers", // Bootstrap styled pagination
            "autoWidth": false,

            "order": [[6, "desc"]], // Sort by Timestamp (column index 6) descending
            "pageLength": 500,      // ✅ Default to 500 entries
            "lengthMenu": [[500, 1000, 1500, 2000], [500, 1000, 1500, 2000]], // ✅ Dropdown options
            "dom": 'lfrtip',
            "responsive": true
        });
        // Ensure Bulk Delete Button is Hidden Initially
        $('.action-button-container').hide();

        // Select All Checkbox Behavior
        $('#selectAll').change(function () {
            $('.file-checkbox').prop('checked', $(this).prop('checked'));
            toggleBulkDeleteButton();
        });

        // Individual Checkboxes Behavior
        $(document).on('change', '.file-checkbox', function () {
            let allChecked = $('.file-checkbox').length === $('.file-checkbox:checked').length;
            $('#selectAll').prop('checked', allChecked);
            toggleBulkDeleteButton();
        });

        // Toggle List View
        $('#list-view-btn').click(function () {
            $('#fileContainer').removeClass('d-none').show();
            $('#grid-view').addClass('d-none').hide();
            $(this).addClass('active');
            $('#grid-view-btn').removeClass('active');
        });

        // Toggle Grid View
        $('#grid-view-btn').click(function () {
            $('#fileContainer').addClass('d-none').hide();
            $('#grid-view').removeClass('d-none').show();
            $(this).addClass('active');
            $('#list-view-btn').removeClass('active');
        });

        // ✅ Show Confirmation Modal Before Bulk Delete (Display Only Filenames)
        $('#deleteSelectedBtn').click(function () {
            let selectedFiles = $('.file-checkbox:checked').map(function () {
                return $(this).val();
            }).get();

            if (selectedFiles.length === 0) {
                showErrorModal("No files selected for deletion.");
                return;
            }

            // ✅ Extract filenames only for display
            let filenamesForDisplay = selectedFiles.map(filePath => filePath.split('/').pop()).join("<br>");

            // ✅ Open Confirmation Modal (Display Filenames)
            $("#confirmationModalLabel").html("Confirm Bulk Delete");
            $("#confirmationModalBody").html(`Are you sure you want to delete these files? <br><br>
        <strong>${filenamesForDisplay}</strong>`); // ✅ Only filenames shown
            $("#confirmationModal").modal("show");

            // ✅ Set Confirm Action (Pass full paths for deletion)
            $("#confirmActionBtn").off("click").on("click", function () {
                $("#confirmationModal").modal("hide");
                performBulkDelete(selectedFiles); // ✅ Full paths still passed
            });
        });


// ✅ Show Confirmation Modal Before Individual File Delete (Display Only Filename)
        $(document).on('click', '.delete-file', function () {
            let filePath = $(this).data('path');
            let filename = filePath.split('/').pop();  // ✅ Extract filename

            // ✅ Open Confirmation Modal (Display Filename)
            $("#confirmationModalLabel").html("Confirm Delete");
            $("#confirmationModalBody").html(`Are you sure you want to permanently delete this file? <br><br>
        <strong>${filename}</strong>`); // ✅ Only filename shown
            $("#confirmationModal").modal("show");

            // ✅ Set Confirm Action (Pass full path for deletion)
            $("#confirmActionBtn").off("click").on("click", function () {
                $("#confirmationModal").modal("hide");
                deleteFile(filePath); // ✅ Full path still passed
            });
        });


        // ✅ Function to Perform Bulk Delete via AJAX
        function performBulkDelete(selectedFiles) {
            $.ajax({
                url: 'delete_bulk.php',
                type: 'POST',
                data: { files: selectedFiles },
                dataType: 'text',
                success: function (response) {
                    if (response.trim() === "success") {
                        showSuccessModal("Selected files deleted successfully!");

                        // ✅ Remove deleted files from UI dynamically
                        selectedFiles.forEach(filePath => {
                            $(`input.file-checkbox[value="${filePath}"]`).closest('tr').remove();
                        });

                        // ✅ Uncheck "Select All" checkbox after deletion
                        $('#selectAll').prop('checked', false);
                        toggleBulkDeleteButton();
                    } else {
                        showErrorModal("Error deleting files: " + response);
                    }
                },
                error: function (xhr) {
                    console.error('Error deleting files:', xhr.responseText);
                    showErrorModal('An error occurred while deleting files.');
                }
            });
        }

        // ✅ Function to Show/Hide Bulk Delete Button
        function toggleBulkDeleteButton() {
            let checkedCount = $('.file-checkbox:checked').length;
            if (checkedCount > 0) {
                $('.action-button-container').removeClass('d-none').show();
            } else {
                $('.action-button-container').addClass('d-none').hide();
            }
        }
    });

    // ✅ Individual File Delete Function
    function deleteFile(filePath) {
        $.ajax({
            url: 'delete_file_trash.php',
            type: 'POST',
            data: { filepath: filePath },
            dataType: 'text',
            success: function (response) {
                if (response.trim() === "success") {
                    showSuccessModal("File deleted successfully!");
                    $(`button[data-path="${filePath}"]`).closest('tr').remove();
                } else {
                    showErrorModal("Error deleting file: " + response);
                }
            },
            error: function (xhr) {
                console.error('Error deleting file:', xhr.responseText);
                showErrorModal('An error occurred while deleting the file.');
            }
        });
    }

    // ✅ Show Success Modal
    function showSuccessModal(message) {
        $("#successModalBody").html(message);
        $("#successModal").modal("show");
    }

    // ✅ Show Error Modal
    function showErrorModal(message) {
        $("#errorModalBody").html(message);
        $("#errorModal").modal("show");
    }






</script>
</main>

<!-- DataTables Bootstrap 5 CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

<script src="assets/js/main.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js"></script>

<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css" rel="stylesheet">

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
</body>
<?php require 'footer.php'; ?>
</html>
