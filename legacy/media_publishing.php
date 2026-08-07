<?php
require 'head.php';
require "config.php";
require 'login-check.php';

$pageTitle = 'Media Publishing';

    // Set the base directory path
$base_directory = 'C:\xampp\htdocs\testcreative/uploads'; // Adjust this to your base directory
$current_directory = isset($_GET['dir']) ? urldecode($_GET['dir']) : $base_directory;

    
function convertFilePathToURL($filePath) {
    // Detect if running locally or on the server
    $isLocal = ($_SERVER['SERVER_ADDR'] === '127.0.0.1' || $_SERVER['SERVER_NAME'] === 'localhost');

    // Define base paths dynamically
    $basePath = 'C:\xampp\htdocs\testcreative/uploads'; // Adjust this to your base directory
    $baseURL = $isLocal ? 'http://localhost/testcreative' : 'http://172.16.152.47/testcreative';

    // Ensure the file path is inside the base directory
    if (strpos($filePath, $basePath) === 0) {
        $relative_path = substr($filePath, strlen($basePath)); // Get relative path
        $relative_path = ltrim($relative_path, '/'); // Remove leading slash
        return $baseURL . '/' . str_replace(' ', '%20', $relative_path); // Convert spaces to %20
    }
    
    return $filePath; // Return original path if invalid
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Include DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>



<!-- FontAwesome (For Icons) -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">














    <title>Media Publishing</title>
    <style>
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
        .thumbnail img {
            max-width: 50px;
            max-height: 50px;
            border-radius: 4px;
            cursor: pointer;
        }
        .shortened-path {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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
    .dataTables_filter
        {
            display:none;
        }

        .table-responsive {
    width: 100%;
    overflow-x: auto; /* Ensure scrollbars appear when needed */
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

<!-- Buttons for List View and Grid View -->
<div class="d-flex justify-content-end mb-2">
    <div class="btn-group" role="group" aria-label="View Toggle">
        <button type="button" class="btn btn-outline-primary active" id="listViewBtn" onclick="switchToListView()">
        <i class="fas fa-list"></i> List
        </button>
        <button type="button" class="btn btn-outline-secondary" id="gridViewBtn" onclick="switchToGridView()">
            <i class="fas fa-th-large"></i> Grid
        </button>
    </div>
</div>








<!-- Delete Selected Button (Below View Buttons) -->
<div class="mb-3">
    <button type="button" class="btn btn-danger" id="deleteSelectedBtn" style="display: none;">
        <i class="fas fa-trash"></i> Delete Selected
    </button>
</div>
<div id="listView" class="table-responsive">
    <table class="datatable table table-hover table-striped" id="fileTable">
        <thead>
            <tr>
                <th><input type="checkbox" id="selectAllCheckbox" title="Select/Deselect All"></th>
                <th>Thumbnail</th>
                <th>File/Folder Name</th>
                <th>Type</th>
                <th class="file-path-column">File Path</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php
$query = "
(
    SELECT 
        f.id AS file_id, 
        CONVERT(f.filename USING utf8mb4) COLLATE utf8mb4_unicode_ci AS filename, 
        CONVERT(f.filepath USING utf8mb4) COLLATE utf8mb4_unicode_ci AS filepath, 
        CONVERT(f.filetype USING utf8mb4) COLLATE utf8mb4_unicode_ci AS filetype, 
        COALESCE(f.album_id, 0) AS album_id,  -- ✅ Ensures album_id is not NULL
        COALESCE(CONVERT(a.name USING utf8mb4) COLLATE utf8mb4_unicode_ci, '') AS album_name, -- ✅ Replace NULL with an empty string
        NULL AS publish_id, 
        NULL AS publish_filename, 
        NULL AS publish_type, 
        NULL AS publish_filepath,
        'album' AS source_type  -- Identify this as an 'album' entry
    FROM files f
    LEFT JOIN albums a ON f.album_id = a.id
    WHERE f.album_id IS NOT NULL AND f.album_id != ''
)

UNION ALL

(
    SELECT 
        p.p_id AS file_id,  -- ✅ Use publish_id instead of NULL for consistency
        CONVERT(p.filename USING utf8mb4) COLLATE utf8mb4_unicode_ci AS filename, 
        CONVERT(p.filepath USING utf8mb4) COLLATE utf8mb4_unicode_ci AS filepath, 
        CONVERT(p.type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS filetype, 
        0 AS album_id,  -- ✅ Assign a default value (e.g., 0) instead of NULL
        '' AS album_name, -- ✅ Empty string instead of NULL to maintain column structure
        p.p_id AS publish_id, 
        CONVERT(p.filename USING utf8mb4) COLLATE utf8mb4_unicode_ci AS publish_filename, 
        CONVERT(p.type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS publish_type, 
        CONVERT(p.filepath USING utf8mb4) COLLATE utf8mb4_unicode_ci AS publish_filepath,
        'publish' AS source_type  -- Identify this as a 'publish' entry
    FROM publish p
)

ORDER BY source_type ASC, album_name ASC, filename ASC;

";

        
          

$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $filePath = $row['filepath'];
        $fileName = $row['filename'];
        $fileType = strtolower(htmlspecialchars($row['filetype'])); // Convert to lowercase for consistency

        // ✅ Use convertFilePathToURL() to get the correct local URL
        $fileURL = convertFilePathToURL($filePath);

        // ✅ Generate thumbnail preview
        $thumbnail = '';
        if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $filePath)) {
            $thumbnail = "<img src='" . htmlspecialchars($fileURL) . "' 
                          alt='Thumbnail' class='thumbnail' 
                          style='width: 60px; height: 60px; object-fit: cover;'>";
        } elseif (preg_match('/\.(mp4|mov|avi)$/i', $filePath)) {
            $thumbnail = "<video src='" . htmlspecialchars($fileURL) . "' 
                          class='thumbnail' 
                          style='width: 60px; height: 60px; object-fit: cover;' muted></video>";
        } else {
            $thumbnail = "<span>No Preview</span>"; // Default text if no preview is available
        }

        echo "<tr data-path='" . htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8') . "'>";
        echo "<td><input type='checkbox' class='rowCheckbox'></td>";
        echo "<td>{$thumbnail}</td>";
        echo "<td>
                <a href='javascript:void(0);' 
                   onclick=\"openModal('" . htmlspecialchars($fileURL) . "', '" . htmlspecialchars($fileType) . "')\" 
                   style='text-decoration: none; color: inherit;'>
                    " . htmlspecialchars($fileName) . "
                </a>
              </td>";
        echo "<td>" . htmlspecialchars($fileType) . "</td>";
        echo "<td class='file-path-column'>
                <a href='" . htmlspecialchars($fileURL) . "' target='_blank' class='file-path'>" 
                . htmlspecialchars($fileURL) . "
                </a>
              </td>";
              echo "<td>
              <div class='dropdown'>
                  <button class='btn btn-sm btn-danger dropdown-toggle' type='button' id='dropdownActions-" . htmlspecialchars($fileName) . "' data-bs-toggle='dropdown' aria-expanded='false'>
                      <i class='fas fa-cogs'></i> Actions
                  </button>
                  <ul class='dropdown-menu' aria-labelledby='dropdownActions-" . htmlspecialchars($fileName) . "'>
                      <li>
                          <a class='dropdown-item' href='javascript:void(0);' onclick=\"renameMedia('" . addslashes($filePath) . "', '" . addslashes($fileName) . "')\">
                              <i class='fas fa-i-cursor'></i> Rename
                          </a>
                      </li>
                      <li>
                          <a class='dropdown-item text-danger' href='javascript:void(0);' onclick=\"unpublishMedia('" . addslashes($filePath) . "')\">
                              <i class='fas fa-times'></i> Unpublish
                          </a>
                      </li>
                  </ul>
              </div>
          </td>";
      
    }
} else {
    echo "<tr><td colspan='6'>No files or folders found.</td></tr>";
}
?>


        </tbody>
    </table>
</div>


<script>
async function renameMedia(filePath, fileName) {
    const newName = prompt('Enter the new name for the file:', fileName);
    if (!newName) return;

    try {
        const response = await fetch('rename_file.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ filePath: filePath, newName: newName })
        });

        const result = await response.json();
        if (result.success) {
            alert('File renamed successfully!');
            location.reload(); // Refresh the page
        } else {
            alert('Error renaming file: ' + result.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred while renaming the file.');
    }
}


async function unpublishMedia(publishId) {
    if (!confirm("Are you sure you want to unpublish this file?")) return;

    try {
        const response = await fetch('unpublish_file.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `publish_id=${encodeURIComponent(publishId)}`
        });

        const result = await response.json();
        if (result.status === 'success') {
            alert('File unpublished successfully!');
            location.reload();
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred while unpublishing the file.');
    }
}


</script>

<script>
    // Initialize DataTables
    $(document).ready(function () {
        $('#fileTable').DataTable({
            "pageLength": 10,
            "order": [[2, "asc"]], // Sort by File/Folder Name
            "columnDefs": [
                { "orderable": false, "targets": [0, 1, 5] } // Disable sorting on checkboxes, thumbnail, and actions
            ]
        });
    });
    </script>
<!-- SCRIPT FOR BULK DELETE -->
<script>
document.addEventListener("DOMContentLoaded", function () {
    const rowCheckboxes = document.querySelectorAll('.rowCheckbox');
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');

    // Show/Hide "Delete Selected" Button
    function toggleDeleteButton() {
        const anyChecked = Array.from(rowCheckboxes).some(checkbox => checkbox.checked);
        deleteSelectedBtn.style.display = anyChecked ? 'block' : 'none';
    }

    // Select All Checkbox Logic
    selectAllCheckbox.addEventListener('change', function () {
        const isChecked = selectAllCheckbox.checked;
        rowCheckboxes.forEach(checkbox => (checkbox.checked = isChecked));
        toggleDeleteButton();
    });

    // Individual Checkbox Logic
    rowCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function () {
            if (!checkbox.checked) {
                selectAllCheckbox.checked = false;
            } else if (Array.from(rowCheckboxes).every(cb => cb.checked)) {
                selectAllCheckbox.checked = true;
            }
            toggleDeleteButton();
        });
    });

    // Delete Selected Files (Database Only)
    deleteSelectedBtn.addEventListener('click', function () {
        const selectedCheckboxes = Array.from(rowCheckboxes).filter(checkbox => checkbox.checked);
        if (selectedCheckboxes.length === 0) {
            alert('No files selected.');
            return;
        }

        const filePaths = selectedCheckboxes.map(checkbox => checkbox.closest('tr').dataset.path);

        if (confirm('Are you sure you want to delete the selected files from the database?')) {
            fetch('delete_files_db.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ filepaths: filePaths })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Selected files deleted from the database successfully.');
                    location.reload(); // Reload to reflect changes
                } else {
                    alert('Error deleting files: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while deleting files.');
            });
        }
    });
});



    </script>

<!-- Grid View -->
<div id="gridView" class="row" style="display: none;">
    <?php
    $result = $conn->query($query); // Reusing the same query for grid view

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $filePath = $row['filepath'];
            $fileName = $row['filename'];
            $fileURL = convertFilePathToURL($filePath);
            $fileType = htmlspecialchars($row['filetype']);

            echo "<div class='col-md-3 mb-4'>";
            echo "<div class='card h-100'>";
            echo "<div class='card-body text-center'>";

            // Add modal trigger for thumbnails
            if (preg_match('/(jpg|jpeg|png|gif)$/i', $fileType)) {
                echo "<a href='javascript:void(0);' onclick=\"openModal('" . htmlspecialchars($fileURL) . "', 'image')\">";
                echo "<img src='" . htmlspecialchars($fileURL) . "' alt='Image' class='img-fluid mb-2' style='height: 150px; object-fit: cover;'>";
                echo "</a>";
            } elseif (preg_match('/(mp4|mov|avi)$/i', $fileType)) {
                echo "<a href='javascript:void(0);' onclick=\"openModal('" . htmlspecialchars($fileURL) . "', 'video')\">";
                echo "<video src='" . htmlspecialchars($fileURL) . "' class='img-fluid mb-2' style='height: 150px; object-fit: cover;' muted></video>";
                echo "</a>";
            } else {
                echo "<p class='text-muted'>No Preview</p>";
            }

            // Add File Name
            echo "<h6 class='card-title'>
                    <a href='javascript:void(0);' 
                       onclick=\"openModal('" . htmlspecialchars($fileURL) . "', '" . htmlspecialchars($fileType) . "')\">
                        " . htmlspecialchars($fileName) . "
                    </a>
                  </h6>";

            echo "</div>";
            echo "</div>";
            echo "</div>";
        }
    } else {
        echo "<div class='col-12'><p>No files or folders found.</p></div>";
    }
    ?>
</div>



<!-- JavaScript for List/Grid View Toggle -->
<script>
// ✅ Fix: Ensure List/Grid View Toggles Properly
function switchToListView() {
    $("#gridView").hide();  // Hide grid view
    $("#listView").show(); // Show table (list) view

    $("#listViewBtn").addClass("btn-primary").removeClass("btn-secondary");
    $("#gridViewBtn").addClass("btn-secondary").removeClass("btn-primary");
}

function switchToGridView() {
    $("#listView").hide(); // Hide table (list) view
    $("#gridView").show();  // Show grid view

    $("#gridViewBtn").addClass("btn-primary").removeClass("btn-secondary");
    $("#listViewBtn").addClass("btn-secondary").removeClass("btn-primary");
}

// ✅ Fix: Initialize Toggle State on Load
$(document).ready(function () {
    $("#gridView").hide(); // Initially hide grid view
    $("#listView").show(); // Show list view by default
});

// ✅ Fix: Ensure File Preview Works
function openModal(fileUrl, fileType) {
    const overlay = document.getElementById("file-preview-overlay");
    const content = document.getElementById("file-preview-content");

    content.innerHTML = ""; // Clear previous content

    if (fileType.match(/(jpg|jpeg|png|gif)$/i)) {
        const img = document.createElement("img");
        img.src = fileUrl;
        img.className = "preview-media";
        content.appendChild(img);
    } else if (fileType.match(/(mp4|mp3|wav|mov)$/i)) {
        const video = document.createElement("video");
        video.src = fileUrl;
        video.className = "preview-media";
        video.controls = true;
        content.appendChild(video);
    } else {
        const text = document.createElement("p");
        text.textContent = "Preview not available.";
        content.appendChild(text);
    }

    overlay.style.display = "flex";
}

// ✅ Fix: Ensure Closing Works
function closePreview() {
    const overlay = document.getElementById("file-preview-overlay");
    overlay.style.display = "none";
}
</script>

<script src="assets/js/main.js"></script>

<?php require 'footer.php'; ?>

</body>
</html>
