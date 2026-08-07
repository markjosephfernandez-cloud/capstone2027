<?php
date_default_timezone_set('Asia/Manila');

require 'head.php';
require "config.php";
require 'login-check.php';

$pageTitle = 'Trash';

// Set the trash directory path (local files)
$trash_directory = '/Applications/XAMPP/xamppfiles/htdocs/TRASH';

// Convert file path to URL
function convertFilePathToURL($filePath) {
    $basePath = '/Applications/XAMPP/xamppfiles/htdocs/TRASH';
    $baseURL = 'http://172.16.152.47/TRASH';

    if (strpos($filePath, $basePath) === 0) {
        $relative_path = substr($filePath, strlen($basePath));
        $relative_path = ltrim($relative_path, '/');
        return $baseURL . '/' . str_replace(' ', '%20', $relative_path);
    }
    return false;
}

// Function to fetch trash files from the local directory
function getTrashFiles($directory) {
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'mov'];
    $files = [];

    if (!is_dir($directory)) {
        return [];
    }

    $items = scandir($directory);
    foreach ($items as $item) {
        if ($item !== "." && $item !== "..") {
            $filePath = $directory . '/' . $item;
            $fileType = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            if (in_array($fileType, $allowedExtensions)) {
                $files[] = [
                    'item_name' => $item,
                    'filepath'  => $filePath,
                    'filetype'  => $fileType,
                    'timestamp' => date('Y-m-d h:i:s A', strtotime('-5 hours', filemtime($filePath))),
                    'mtime'     => filemtime($filePath)
                ];
            }
        }
    }

    return $files;
}

// Function to fetch trash files from the database
function getTrashFilesFromDB($conn) {
    $files = [];
    $query = "SELECT filename, filepath, filetype, date_moved, description FROM Trash ORDER BY date_moved DESC";
    $result = mysqli_query($conn, $query);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $files[] = [
                'item_name'   => $row['filename'],
                'filepath'    => $row['filepath'],
                'filetype'    => $row['filetype'],
                'timestamp'   => date('Y-m-d h:i:s A', strtotime($row['date_moved'])),
                'mtime'       => strtotime($row['date_moved']),
                'description' => $row['description']
            ];
        }
    }
    return $files;
}

// Get trash files from both sources
$filesFromDB    = getTrashFilesFromDB($conn);
$filesFromLocal = getTrashFiles($trash_directory);

$trashFiles = array_merge($filesFromDB, $filesFromLocal);

// Sort merged array by mtime (newest first)
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
    <div class="action-button-container d-flex gap-2 mb-3 d-none">
        <!-- Delete Selected -->
        <button type="button" class="btn btn-danger d-flex align-items-center" id="deleteSelectedBtn">
            <i class="fas fa-trash-alt me-2"></i> Delete Selected
        </button>
    </div>


    <div id="fileContainer" class="table-responsive">
        <table class="table table-hover table-striped" id="fileTable">
            <thead>
            <tr>
                <th><input type="checkbox" id="selectAll"></th>
                <th>Thumbnail</th>
                <th>File Name</th>
                <th>Filepath</th>
                <th>Original Folder</th>
                <th>Type</th>
                <th>Timestamp</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($trashFiles as $index => $file): ?>
                <?php $fileUrl = convertFilePathToURL($file['filepath']); ?>
                <tr data-path="<?php echo $file['filepath']; ?>">
                    <td><input type="checkbox" class="file-checkbox" value="<?php echo $file['filepath']; ?>"></td>
                    <td>
                        <?php
                        if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $file['filepath'])) {
                            echo "<img src='" . htmlspecialchars($fileUrl) . "' 
                                 alt='Thumbnail' 
                                 class='thumbnail'
                                 style='width: 40px; height: 40px; object-fit: cover; cursor: pointer;'
                                 onclick=\"openPreview('" . htmlspecialchars($fileUrl) . "', '" . htmlspecialchars($file['filetype']) . "')\">";
                        } elseif (preg_match('/\.(mp4|mov|avi)$/i', $file['filepath'])) {
                            echo "<div class='video-thumbnail' 
                                    style='position: relative; width: 40px; height: 40px; cursor: pointer;'
                                    onclick=\"openPreview('" . htmlspecialchars($fileUrl) . "', '" . htmlspecialchars($file['filetype']) . "')\">
                                    <video src='" . htmlspecialchars($fileUrl) . "' 
                                           class='thumbnail' 
                                           muted 
                                           style='width: 100%; height: 100%; object-fit: cover;'>
                                    </video>
                                    <div class='play-button' 
                                         style='position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
                                                width: 20px; height: 20px; background: rgba(0, 0, 0, 0.5); 
                                                border-radius: 50%; display: flex; justify-content: center; align-items: center;'>
                                        <i class='fas fa-play' style='color: white; font-size: 12px;'></i>
                                    </div>
                                  </div>";
                        } else {
                            echo "<span>No Preview</span>";
                        }
                        ?>
                    </td>
                    <td class="file-folder-link"><?php echo htmlspecialchars($file['item_name']); ?></td>
                    <td title="<?php echo htmlspecialchars($file['filepath']); ?>" data-bs-toggle="tooltip">
                        <?php
                        $fullPath = $file['filepath'];
                        $maxLen   = 20;
                        $shortPath = (strlen($fullPath) > $maxLen)
                            ? substr($fullPath, 0, $maxLen) . '...'
                            : $fullPath;
                        ?>
                        <span style="display:inline-block; max-width:250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            <?php echo htmlspecialchars($shortPath); ?>
                        </span>
                    </td>
                    <td>
                        <?php
                        $desc = $file['description'] ?? '';
                        $prefix = "Moved from ";
                        if(strpos($desc, $prefix) === 0) {
                            $path = trim(substr($desc, strlen($prefix)));
                            $lastFolder = basename(dirname($path));
                            echo htmlspecialchars($lastFolder);
                        } else {
                            $maxLength = 30;
                            echo htmlspecialchars(strlen($desc) > $maxLength ? substr($desc, 0, $maxLength) . '...' : $desc);
                        }
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars($file['filetype']); ?></td>
                    <td data-order="<?php echo $file['mtime']; ?>">
                        <?php echo htmlspecialchars($file['timestamp']); ?>
                    </td>
                    <td>
                        <div class="btn-group" role="group">
                            <button class="btn btn-sm btn-danger delete-file" data-path="<?php echo $file['filepath']; ?>">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                            <button class="btn btn-sm btn-warning restore-file" data-path="<?php echo $file['filepath']; ?>">
                                <i class="fas fa-undo"></i> Restore
                            </button>
                        </div>
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


    <!-- Grid View (Initially Hidden) -->
    <div id="grid-view" class="grid-view d-none">
        <?php foreach ($trashFiles as $file): ?>
            <div class="grid-item" data-type="<?php echo htmlspecialchars($file['filetype']); ?>">
                <?php
                $fileURL = convertFilePathToURL($file['filepath']);
                $fileType = htmlspecialchars($file['filetype']);

                if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $file['filepath'])) {
                    // ✅ Image Thumbnail
                    echo "<img src='" . htmlspecialchars($fileURL) . "' 
                     alt='Thumbnail' 
                     class='thumbnail'
                     onclick=\"openPreview('" . htmlspecialchars($fileURL) . "', '$fileType')\">";

                } elseif (preg_match('/\.(mp4|mov|avi)$/i', $file['filepath'])) {
                    // ✅ Video Thumbnail with Play Button
                    echo "<div class='video-thumbnail' 
                        style='position: relative; width: 100%; cursor: pointer;'
                        onclick=\"openPreview('" . htmlspecialchars($fileURL) . "', '$fileType')\">
                    
                    <video src='" . htmlspecialchars($fileURL) . "' 
                           class='thumbnail' 
                           muted 
                           style='width: 100%; object-fit: cover;'>
                    </video>

                    <div class='play-button' 
                         style='position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
                                width: 30px; height: 30px; background: rgba(0, 0, 0, 0.5); 
                                border-radius: 50%; display: flex; justify-content: center; align-items: center;'>
                        <i class='fas fa-play' style='color: white; font-size: 16px;'></i>
                    </div>
                </div>";

                } else {
                    echo "<span>No Preview</span>";
                }
                ?>
                <div class="file-info">
                    <div><?php echo htmlspecialchars($file['item_name']); ?></div>
                    <div><?php echo htmlspecialchars($file['filetype']); ?></div>
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

            <!--Script for restore -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll('.restore-file').forEach(button => {
        button.addEventListener('click', function() {
            const filePath = this.getAttribute('data-path');

            if (!filePath) {
                document.getElementById('errorModalBody').textContent = "Error: Filepath is missing.";
                new bootstrap.Modal(document.getElementById('errorModal')).show();
                return;
            }

            // Update confirmation modal content
            document.getElementById('confirmationModalLabel').textContent = "Confirm Restore";
            document.getElementById('confirmationModalBody').innerHTML = `
                Are you sure you want to restore this file? <br><br>
                <strong>${filePath}</strong>
            `;
            document.getElementById('confirmActionBtn').textContent = "Restore File";

            // Show confirmation modal
            const confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
            confirmationModal.show();

            // Confirm restore action
            document.getElementById('confirmActionBtn').onclick = async function() {
                confirmationModal.hide();

                try {
                    const response = await fetch('restoreFile.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ filepath: filePath }) // Send `real_filepath`
                    });

                    const result = await response.json();

                    if (result.status === 'success') {
                        document.getElementById('successModalBody').textContent = "File restored successfully.";
                        new bootstrap.Modal(document.getElementById('successModal')).show();
                        setTimeout(() => location.reload(), 2000); // Refresh after success
                    } else {
                        document.getElementById('errorModalBody').textContent = "Error restoring file: " + result.message;
                        new bootstrap.Modal(document.getElementById('errorModal')).show();
                    }
                } catch (error) {
                    console.error("Error restoring file:", error);
                    document.getElementById('errorModalBody').textContent = "An unexpected error occurred.";
                    new bootstrap.Modal(document.getElementById('errorModal')).show();
                }
            };
        });
    });
});



    </script>


<script>

    function openPreview(fileUrl, fileType) {
        const overlay = document.getElementById('file-preview-overlay');
        const content = document.getElementById('file-preview-content');
        content.innerHTML = `<img src="${fileUrl}" style="width: 100%; max-height: 80vh; object-fit: contain;">`;
        overlay.style.display = 'flex';
    }
</script>
<script>
    $(document).ready(function () {
        let currentFiles = []; // Store the list of files (url, type)
        let currentIndex = 0;  // Track the current previewed file

        // Function to open the modal and preview the file
        function openModal(fileUrl, fileType) {
            const overlay = $("#file-preview-overlay");
            const content = $("#file-preview-content");
            content.html(""); // Clear previous content

            // Handle different file types
            if (fileType.match(/(jpg|jpeg|png|gif)$/i)) {
                content.append(`<img src="${fileUrl}" class="preview-media" style="width: 100%; max-height: 80vh; object-fit: contain;">`);
            } else if (fileType.match(/(mp4|mov|avi)$/i)) {
                content.append(`<video src="${fileUrl}" controls class="preview-media" style="width: 100%; max-height: 80vh;"></video>`);
            } else {
                content.append(`<p>Preview not available for this file type.</p>`);
            }

            overlay.fadeIn();
        }

        // Function to open a file and initialize navigation
        function initializeFilePreview(files, startIndex) {
            if (!files.length) return;

            currentFiles = files;
            currentIndex = startIndex;

            // Open the first file in the preview
            openModal(currentFiles[currentIndex].url, currentFiles[currentIndex].type);
        }

        // Function to navigate files
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

        // Event listeners for navigation
        $("#prev-btn").on("click", function () {
            navigateFile("prev");
        });

        $("#next-btn").on("click", function () {
            navigateFile("next");
        });

        $("#close-preview-btn").on("click", function () {
            $("#file-preview-overlay").fadeOut();
        });

        // **✅ FIX: Collect files from both List View and Grid View ✅**
        function collectFiles() {
            currentFiles = []; // Reset array to prevent duplicates

            // **Collect files from Grid View**
            $(".grid-item .thumbnail").each(function (index) {
                const fileUrl = $(this).attr("src");
                const fileType = $(this).closest(".grid-item").attr("data-type");

                if (!currentFiles.some(file => file.url === fileUrl)) {
                    currentFiles.push({ url: fileUrl, type: fileType });
                }

                $(this).off("click").on("click", function () {
                    initializeFilePreview(currentFiles, index);
                });
            });

            // **Collect files from List View (Table View)**
            $("#fileTable .thumbnail").each(function (index) {
                const fileUrl = $(this).attr("src");
                const fileType = $(this).closest("tr").find("td:nth-child(4)").text().trim(); // Get file type from table

                if (!currentFiles.some(file => file.url === fileUrl)) {
                    currentFiles.push({ url: fileUrl, type: fileType });
                }

                $(this).off("click").on("click", function () {
                    initializeFilePreview(currentFiles, index);
                });
            });
        }

        // Ensure files are collected when switching views
        $("#list-view-btn, #grid-view-btn").click(function () {
            collectFiles();
        });

        // Initial file collection
        collectFiles();
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
            $("#confirmationModalBody").html(`Are you sure you want to delete this file? <br><br>
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
            url: 'delete_file.php',
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
