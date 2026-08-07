<?php
require 'head.php';
require 'login-check.php';
require 'config.php';



ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$pageTitle = 'File Management';

// Set the base directory path
$base_directory = '/Applications/XAMPP/xamppfiles/htdocs/testcreative';
$current_directory = isset($_GET['dir']) ? urldecode($_GET['dir']) : $base_directory;
$isFeaturedDir = (strpos($current_directory, '/Featured') !== false);


// Function to convert file path to URL
function convertFilePathToURL($filePath) {
    $basePath = '/Applications/XAMPP/xamppfiles/htdocs/testcreative';
    $baseURL = 'http://172.16.152.47/testcreative';

    // Ensure the file path is inside the base directory
    if (strpos($filePath, $basePath) === 0) {
        $relative_path = substr($filePath, strlen($basePath)); // Get relative path
        $relative_path = ltrim($relative_path, '/'); // Remove leading slash
        return $baseURL . '/' . str_replace(' ', '%20', $relative_path); // Append relative path
    }

    return false; // Return false if the path is invalid
}


// **Check if the directory exists before scanning**
if (!is_dir($current_directory)) {
    die("Error: Directory '$current_directory' does not exist or is not accessible.");
}

// Get and filter the directory contents
$items = scandir($current_directory);
if ($items === false) {
    die("Error: Unable to read directory contents.");
}

// Exclude unwanted files
$items = array_filter($items, function ($item) use ($current_directory) {
    return $item !== '.' && $item !== '..' && $item !== '.DS_Store' && file_exists($current_directory . '/' . $item);
});

// Display the directory listing
foreach ($items as $item) {
    $filePath = realpath($current_directory . '/' . $item);
    $fileURL = convertFilePathToURL($filePath);

    if ($fileURL) {

    }
}


// Capture employee ID for activity tracking
$employee_id = $_SESSION['employee_id'] ?? null;




// Function to insert an activity record into the recent table
function recordActivity($conn, $employee_id, $item_type, $item_name, $filepath) {
    $timestamp = date("Y-m-d H:i:s");
    $stmt = $conn->prepare("INSERT INTO recent (employee_id, item_type, item_name, filepath, timestamp) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $employee_id, $item_type, $item_name, $filepath, $timestamp);
    $stmt->execute();
    $stmt->close();
}
function searchFilesAndFolders($conn, $searchTerm) {
    $searchTerm = '%' . $conn->real_escape_string($searchTerm) . '%';

    // Prepare SQL query to search in files table for direct matches
    $fileQuery = $conn->prepare("SELECT * FROM files WHERE filename LIKE ? OR filepath LIKE ?");
    $fileQuery->bind_param("ss", $searchTerm, $searchTerm);
    $fileQuery->execute();
    $fileResults = $fileQuery->get_result();

    // Prepare SQL query to search in folders table for direct matches
    $folderQuery = $conn->prepare("SELECT * FROM folders WHERE filename LIKE ? OR filepath LIKE ?");
    $folderQuery->bind_param("ss", $searchTerm, $searchTerm);
    $folderQuery->execute();
    $folderResults = $folderQuery->get_result();

    // New logic: Find tags matching the search term and get related files
    $taggedFilesQuery = $conn->prepare("
    SELECT DISTINCT f.id, f.filename, f.filepath, f.filetype
    FROM files AS f
    INNER JOIN tag AS t ON f.filepath = t.filepath
    WHERE t.tag LIKE ?
");
    $taggedFilesQuery->bind_param("s", $searchTerm);
    $taggedFilesQuery->execute();
    $taggedFilesResults = $taggedFilesQuery->get_result();

// Merge file results with tagged files (ensure unique entries)
    $fileResultsArray = $fileResults->fetch_all(MYSQLI_ASSOC);
    $taggedFilesArray = $taggedFilesResults->fetch_all(MYSQLI_ASSOC);

// Combine the results and remove duplicates based on 'id'
    $allFiles = [];
    $fileIds = []; // To track already added file IDs

// Add files directly found in the 'files' table
    foreach ($fileResultsArray as $file) {
        $file['filetype'] = $file['filetype'] ?? 'unknown'; // Handle missing filetype
        if (!in_array($file['id'], $fileIds)) {
            $allFiles[] = $file;
            $fileIds[] = $file['id'];
        }
    }

// Add files matched via tags
    foreach ($taggedFilesArray as $file) {
        $file['filetype'] = $file['filetype'] ?? 'unknown'; // Handle missing filetype
        if (!in_array($file['id'], $fileIds)) {
            $allFiles[] = $file;
            $fileIds[] = $file['id'];
        }
    }

// Combine results
    $results = [
        'files' => $allFiles, // Files now include unique entries matched by tags and filenames/filepaths
        'folders' => $folderResults->fetch_all(MYSQLI_ASSOC), // Folders remain unchanged
    ];

// Close statements
    $fileQuery->close();
    $folderQuery->close();
    $taggedFilesQuery->close();

    return $results;
}






// Capture search term from URL if present
$searchResults = [];
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];
    $searchResults = searchFilesAndFolders($conn, $searchTerm);
}


// Upload File
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file'])) {
    $response = ['status' => 'success', 'message' => 'File uploaded successfully'];
    $fileCount = count($_FILES['file']['name']);
    $description = isset($_POST['description']) ? $_POST['description'] : NULL;
    $tag = isset($_POST['tag']) ? trim($_POST['tag']) : NULL;
    $album_id = isset($_POST['albumId']) && !empty($_POST['albumId']) ? intval($_POST['albumId']) : NULL;

    // Validate the tag to ensure it contains only one word (no commas or spaces)
    if ($tag && preg_match('/[,\s]/', $tag)) {
        $response['status'] = 'error';
        $response['message'] = "Tags should not contain multiple words or commas. Please enter a single tag.";
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    for ($i = 0; $i < $fileCount; $i++) {
        $fileName = $_FILES['file']['name'][$i];
        $fileTmpPath = $_FILES['file']['tmp_name'][$i];
        $fileType = $_FILES['file']['type'][$i];
        $fileSize = $_FILES['file']['size'][$i];
        $fileError = $_FILES['file']['error'][$i];
        $filePath = $current_directory . '/' . basename($fileName);

        if ($fileError === UPLOAD_ERR_OK) {
            if (move_uploaded_file($fileTmpPath, $filePath)) {
                $type = '';
                if (preg_match('/\.(jpg|jpeg|png|gif|bmp|tiff|webp)$/i', $fileName)) {
                    $type = 'image';
                } elseif (preg_match('/\.(mp3|wav|aac|ogg|flac|m4a|wma)$/i', $fileName)) {
                    $type = 'audio';
                } elseif (preg_match('/\.(mp4|mov|avi|mkv|flv|wmv|webm|m4v|3gp|ogg)$/i', $fileName)) {
                    $type = 'video';
                }

                if (!empty($type)) {
                    $tag_id = NULL;
                    if (!empty($tag)) {
                        $checkTagStmt = $conn->prepare("SELECT tag_id FROM tag WHERE tag = ? AND type = ?");
                        if ($checkTagStmt) {
                            $checkTagStmt->bind_param("ss", $tag, $type);
                            $checkTagStmt->execute();
                            $checkTagStmt->store_result();

                            if ($checkTagStmt->num_rows > 0) {
                                $checkTagStmt->bind_result($tag_id);
                                $checkTagStmt->fetch();
                            } else {
                                $insertTagStmt = $conn->prepare("INSERT INTO tag (tag, type, filepath) VALUES (?, ?, ?)");
                                if ($insertTagStmt) {
                                    $insertTagStmt->bind_param("sss", $tag, $type, $filePath);
                                    $insertTagStmt->execute();
                                    $tag_id = $insertTagStmt->insert_id;
                                    $insertTagStmt->close();
                                }
                            }
                            $checkTagStmt->close();
                        }
                    }

                    if ($album_id !== NULL) {
                        $stmt = $conn->prepare("INSERT INTO album_files (filename, filepath, filetype, size, dateupload, description, tag_id, album_id) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?)");
                        $stmt->bind_param("sssissi", $fileName, $filePath, $fileType, $fileSize, $description, $tag_id, $album_id);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO files (filename, filepath, filetype, size, dateupload, description, tag_id) VALUES (?, ?, ?, ?, NOW(), ?, ?)");
                        $stmt->bind_param("sssiss", $fileName, $filePath, $fileType, $fileSize, $description, $tag_id);
                    }

                    if (!$stmt->execute()) {
                        $response['status'] = 'error';
                        $response['message'] .= " Failed to upload $fileName to the database.";
                    }
                    $stmt->close();
                } else {
                    $response['status'] = 'error';
                    $response['message'] .= " Unsupported file type for $fileName.";
                }
            } else {
                $response['status'] = 'error';
                $response['message'] .= " Failed to move $fileName to the directory.";
            }
        } else {
            $response['status'] = 'error';
            $response['message'] .= " Error during upload for $fileName (Error code: $fileError).";
        }
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}







// Handle folder creation logic
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['folderName'])) {
    $folderName = trim($_POST['folderName']);
    $description = isset($_POST['description']) ? $_POST['description'] : NULL;

    if (!empty($folderName)) {
        $folderPath = $current_directory . '/' . $folderName;

        // Create the folder in the file system
        if (!file_exists($folderPath)) {
            if (mkdir($folderPath, 0777, true)) {
                // Check if the folder is being created inside the 'Featured' directory
                if (strpos($current_directory, '/Featured') !== false) {
                    // Insert folder details into the database
                    $stmt = $conn->prepare("INSERT INTO albums(name, description) VALUES (?, ?)");
                    $stmt->bind_param("ss", $folderName, $description);

                    if ($stmt->execute()) {
                        $_SESSION['alert'] = "Folder created in 'Featured' directory and database entry added successfully.";
                    } else {
                        $_SESSION['alert'] = "Error adding folder to database: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $_SESSION['alert'] = "Folder created, but not in 'Featured' directory; no database entry added.";
                }
            } else {
                $_SESSION['alert'] = "Failed to create folder in the file system.";
            }
        } else {
            $_SESSION['alert'] = "Folder already exists.";
        }
    } else {
        $_SESSION['alert'] = "Folder name cannot be empty.";
    }
}


?>


<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <title>File Management</title>


    <!-- Proper order of scripts in the head or at the end of the body -->

    <style>
        /* Ensure the table scrolls horizontally on smaller screens */
        #tagDropdown {
            position: absolute;
            top: 100%; /* Position below the input */
            left: 0;
            z-index: 1050;
            border: 1px solid #ddd;
            background-color: white;
            box-shadow: 0px 4px 6px rgba(0, 0, 0, 0.1);
            border-radius: 4px;
        }

        #tagDropdown .dropdown-item:hover {
            background-color: #f8f9fa;
        }

        /* Style for truncating long file paths */
        .file-path-wrapper {
            max-width: 300px; /* Adjust as needed */
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .file-path {
            display: inline-block;
            width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: pointer;
        }

        .file-path:hover {
            color: #0056b3; /* Optional: Add hover color */
            text-decoration: underline; /* Optional: Add underline effect */
        }

        .table-responsive {
            overflow-x: auto;
        }

        /* Limit the width of the file path column */
        .file-path-column {
            max-width: 300px; /* Adjust as needed */
            overflow: hidden;
            text-overflow: ellipsis; /* Add ellipsis for truncated text */
            white-space: nowrap; /* Prevent wrapping */
        }

        /* Make file path clickable and readable */
        .file-path-wrapper a {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 100%;
            text-decoration: none;
            color: #007bff; /* Bootstrap link color */
        }

        .col-lg-12,
        .table-responsive,
        table {
            width: 100%;
        }

        table {
            border-collapse: collapse; /* Optional: removes spacing between cells */
        }

        th, td {
            padding: 8px; /* Adjust padding as needed */
            text-align: left;
        }

        #file-list img {
            margin: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
            cursor: pointer; /* Make images clickable */
        }

        #file-list li {
            list-style: none;
        }

        /* Styles for the preview overlay and content */
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
            z-index: 1000;
            overflow: hidden; /* Prevents scrolling of the entire overlay */
        }

        #file-preview-content {
            max-width: 95vw;
            max-height: 95vh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden; /* Prevents scrollbars inside the content container */
        }

        .preview-media {
            width: 100vw; /* Full width of the viewport */
            height: 80vh; /* Full height of the viewport */
            object-fit: contain; /* Maintain aspect ratio */
        }

        #close-preview-btn {
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

        #close-preview-btn:hover {
            opacity: 0.7; /* Slightly transparent on hover */
        }

        .dropdown-toggle.no-caret::after {
            display: none;
        }

        /* Custom styles for the button container */
        .button-container {
            display: flex;
            gap: 10px; /* Space between buttons */
            margin-bottom: 20px; /* Space below the button container */
        }

        .table {
            table-layout: auto; /* Allow columns to adjust automatically */
            width: 100%; /* Ensure the table spans the full container width */
        }

        .table th, .table td {
            white-space: nowrap; /* Prevent text from wrapping */
            text-align: left; /* Align content to the left */
            vertical-align: middle; /* Align content vertically */
        }

        .table th.actions-column, .table td.actions-column {
            width: 15%; /* Allocate enough space for the Actions dropdown */
        }

        .dropdown-menu {
            z-index: 1050; /* Ensure dropdown appears above other elements */
        }

        .datatable {
            overflow-x: auto; /* Enable horizontal scrolling if the table is too wide */
        }

        .table-responsive {
            overflow-x: visible; /* Ensure dropdowns are not cut off in smaller containers */
        }

        .filter-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .tag-checkboxes {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        /* Grid View Styles */
        .grid-view {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); /* Dynamically fills the width */
            gap: 20px; /* Even spacing between grid items */
            padding: 10px;
            width: 100%; /* Ensures the grid spans the full width */
            margin: 0; /* Removes unnecessary margin */
            box-sizing: border-box; /* Ensures padding is included in the width */
        }

        .grid-item {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 10px;
            text-align: center;
            transition: transform 0.3s;
        }

        .grid-item:hover {
            transform: translateY(-5px);
        }

        .grid-item img, .grid-item video {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
        }

        .container {
            max-width: 100%; /* Remove any container width restrictions */
            padding-left: 10px;
            padding-right: 10px;
            margin: 0 auto; /* Center the container if necessary */
        }

        /* Updated Grid View */
        .grid-view {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px; /* Even spacing between grid items */
            padding: 10px;
            margin: 0 auto; /* Center the grid container */
        }

        .grid-item {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 10px;
            text-align: center;
            transition: transform 0.3s;
        }

        .grid-item:hover {
            transform: translateY(-5px);
        }

        .grid-item img, .grid-item video {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
        }

        .grid-view {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
        }

        .grid-view .grid-item {
            display: flex;
            flex-direction: column;
            width: 23%;
            margin-bottom: 15px;
            text-align: center;
        }

        .grid-view img, .grid-view video {
            width: 100%;
            max-height: 150px;
            object-fit: cover;
            border-radius: 5px;
            margin-bottom: 10px;
        }

        .grid-view .file-info {
            text-align: center;
        }

        /* File preview overlay */
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

        #file-preview-content {
            max-width: 80%;
            max-height: 80%;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
            display: flex;
            justify-content: center;
            align-items: center;
        }

        /* View Buttons */
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

        /* Table Thumbnails */
        #fileTable img, #fileTable video {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 5px;
            cursor: pointer;
        }



        .suggestions-list {
            position: absolute;
            top: 100%;
            left: 0;
            width: 100%;
            background: white;
            border: 1px solid #ccc;
            max-height: 200px;
            overflow-y: auto;
            display: none;
            z-index: 1000;
        }

        .suggestions-list div {
            padding: 10px;
            cursor: pointer;
        }

        .suggestions-list div:hover {
            background-color: #f0f0f0;
        }


    </style>

    <!-- Bootstrap CSS (optional, if you're using Bootstrap) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

    <!-- FontAwesome (for icons) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

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




    </div><!-- End Page Title -->

    <!-- Search Bar and Filter Options -->
    <div class="search-bar1 mb-5 d-flex align-items-center position-relative">
        <i class="bi bi-search text-secondary"></i>
        <input type="text" class="form-control ms-3" placeholder="Search" id="search-bar">
        <div id="suggestions" class="suggestions-list"></div>
    </div>


    <div class="filter-buttons d-flex gap-2">


        <!-- ✅ Confirmation Modal (Reused for Rename, Delete, Copy, Download) -->
        <div class="modal fade" id="confirmationModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="confirmationModalTitle">Confirm Action</h5>
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



        <!-- ✅ Error Modal (Red OK Button) -->
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




        <!-- Edit Modal -->
        <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editModalLabel">Edit File/Folder</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form id="editForm">
                            <div class="mb-3">
                                <label for="editFilename" class="form-label">Filename</label>
                                <input type="text" class="form-control" id="editFilename" name="filename">
                            </div>
                            <div class="mb-3">
                                <label for="editTag" class="form-label">Tag</label>
                                <input type="text" class="form-control" id="editTag" name="tag">
                            </div>
                            <div class="mb-3">
                                <label for="editDescription" class="form-label">Description</label>
                                <textarea class="form-control" id="editDescription" name="description"></textarea>
                            </div>
                            <input type="hidden" id="editFilePath" name="filepath">
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="saveFileChanges()">Save Changes</button>
                    </div>
                </div>
            </div>
        </div>


        <script>
            document.addEventListener("DOMContentLoaded", function () {
                const searchBar = document.getElementById("search-bar");
                const suggestionsList = document.getElementById("suggestions");

                // Function to fetch and display tag and file name suggestions
                async function fetchSuggestions(query) {
                    if (query.length === 0) {
                        suggestionsList.style.display = "none";
                        return;
                    }

                    try {
                        const response = await fetch(`fetch_tags.php?query=${encodeURIComponent(query)}`);
                        const suggestions = await response.json();

                        suggestionsList.innerHTML = "";
                        if (suggestions.length > 0) {
                            suggestions.forEach(suggestion => {
                                let suggestionItem = document.createElement("div");
                                suggestionItem.textContent = suggestion;
                                suggestionItem.onclick = function () {
                                    searchBar.value = suggestion;
                                    suggestionsList.style.display = "none";
                                };
                                suggestionsList.appendChild(suggestionItem);
                            });
                            suggestionsList.style.display = "block";
                        } else {
                            suggestionsList.style.display = "none";
                        }
                    } catch (error) {
                        console.error("Error fetching suggestions:", error);
                    }
                }

                // Event listener for search input
                searchBar.addEventListener("input", function () {
                    fetchSuggestions(this.value);
                });

                // Hide suggestions when clicking outside
                document.addEventListener("click", function (event) {
                    if (!searchBar.contains(event.target) && !suggestionsList.contains(event.target)) {
                        suggestionsList.style.display = "none";
                    }
                });
            });

        </script>

        <!-- Tags Checkbox Functionality -->
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const tagCheckboxes = document.querySelectorAll('.tag-filter');
                const tableBody = document.querySelector('#fileTable tbody');
                let originalFileList = []; // Backup the original file list on page load

                // Backup the original table rows on page load
                originalFileList = Array.from(tableBody.querySelectorAll('tr')).map(row => row.cloneNode(true));

                // Function to fetch files dynamically (for backend logic)
                function fetchFilesByTags(selectedTags) {
                    fetch('fetch_files_by_tags.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ tags: selectedTags }),
                    })
                        .then((response) => response.json())
                        .then((data) => {
                            if (data.status === 'success') {
                                updateFileTable(data.files); // Update the table with the backend response
                            } else {
                                tableBody.innerHTML = '<tr><td colspan="7">No files found for the selected tags.</td></tr>';
                            }
                        })
                        .catch((error) => {
                            console.error('Error fetching files:', error);
                            tableBody.innerHTML = '<tr><td colspan="7">An error occurred while fetching files.</td></tr>';
                        });
                }

                // Function to update the file table
                function updateFileTable(files) {
                    tableBody.innerHTML = ''; // Clear existing rows

                    if (files.length === 0) {
                        tableBody.innerHTML = '<tr><td colspan="7">No files found for the selected tags.</td></tr>';
                        return;
                    }

                    files.forEach((file) => {
                        const row = document.createElement('tr');
                        row.setAttribute('data-tags', file.tags ? file.tags.join(',') : ''); // Set data-tags attribute for filtering

                        row.innerHTML = `
                    <td><input type="checkbox" class="rowCheckbox"></td>
                    <td>
                        <a href="${file.filepath}" target="_blank">${file.filename}</a>
                    </td>
                    <td>${file.filetype || 'File'}</td>
                    <td>Unknown</td>
                    <td class="file-path-column">
                        <div class="file-path-wrapper">
                            <a href="${file.filepath}" target="_blank">${file.filepath}</a>
                        </div>
                    </td>
                    <td>Creative</td>
                    <td>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-danger dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-cogs"></i> Actions
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                        <a class="dropdown-item" href="javascript:void(0);" onclick="openEditModal('<?php echo addslashes(htmlspecialchars($filepath)); ?>', '<?php echo addslashes(htmlspecialchars($filename)); ?>', '<?php echo addslashes(htmlspecialchars($tag)); ?>', '<?php echo addslashes(htmlspecialchars($description)); ?>')">
        <i class="fas fa-edit"></i> Edit
    </a>

                                    </li>
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="copyMedia('${file.filepath}')">Copy</a></li>
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="downloadMedia('${file.filepath}')">Download</a></li>
                                <li><a class="dropdown-item text-danger" href="javascript:void(0);" onclick="deleteMedia('${file.filepath}', '${file.filename}')">Delete</a></li>
                            </ul>
                        </div>
                    </td>
                `;
                        tableBody.appendChild(row);
                    });
                }

                // Add event listener to each tag checkbox
                tagCheckboxes.forEach((checkbox) => {
                    checkbox.addEventListener('change', function () {
                        const selectedTags = Array.from(tagCheckboxes)
                            .filter((checkbox) => checkbox.checked)
                            .map((checkbox) => checkbox.value);

                        if (selectedTags.length === 0) {
                            // Restore the original table rows if no tags are selected
                            tableBody.innerHTML = '';
                            originalFileList.forEach(row => tableBody.appendChild(row));
                        } else {
                            // Use backend or frontend filtering
                            if (typeof fetchFilesByTags === 'function') {
                                fetchFilesByTags(selectedTags); // Fetch dynamically using backend (optional)
                            } else {
                                // Filter rows directly from original file list for frontend filtering
                                const filteredRows = originalFileList.filter(row => {
                                    const rowTags = row.getAttribute('data-tags') ? row.getAttribute('data-tags').split(',') : [];
                                    return selectedTags.some(tag => rowTags.includes(tag));
                                });

                                tableBody.innerHTML = ''; // Clear the table
                                if (filteredRows.length === 0) {
                                    tableBody.innerHTML = '<tr><td colspan="7">No files found for the selected tags.</td></tr>';
                                } else {
                                    filteredRows.forEach(row => tableBody.appendChild(row)); // Add filtered rows
                                }
                            }
                        }
                    });
                });
            });
        </script>



        <!-- Location Dropdown -->
        <div class="dropdown">
            <!-- <button class="btn btn-outline-secondary location-dropdown no-caret" type="button" id="dropdownLocation" data-bs-toggle="dropdown" aria-expanded="false">
                <span>Location</span>
            </button>
            <ul class="dropdown-menu" aria-labelledby="dropdownLocation">
                <li><a class="dropdown-item" href="#" data-value="Creative"><i class="bi bi-palette me-2" style="color: #4285F4;"></i>Creative</a></li>
            </ul> -->
        </div>
    </div>

    <!-- Button Container for Add New Folder and Upload File -->
    <div class="button-container">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFolderModal">Add New Folder</button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">Upload File</button>
        <!-- <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createAlbumModal">Create Album</button> -->
    </div>

    <!-- Modal for Adding New Folder -->
    <div class="modal fade" id="addFolderModal" tabindex="-1" aria-labelledby="addFolderModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addFolderModalLabel">New Folder</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="folderName" class="form-label">Folder Name</label>
                            <input type="text" class="form-control" id="folderName" name="folderName" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3" placeholder="Enter description for the folder"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <!-- <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button> -->
                        <button type="submit" class="btn w-100" style="background-color: #dc3545; color: white; border: none;">
                            <i class="fas fa-folder-plus me-2"></i> Create Folder
                        </button>

                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- Modal for Uploading File with Progress Bar and Cancel Button -->
    <div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="uploadModalLabel">
                        <i class="fas fa-upload me-2"></i> Upload Files
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="uploadForm" method="POST" enctype="multipart/form-data">

                        <!-- File Input -->
                        <div class="mb-3">
                            <label for="fileToUpload" class="form-label">Select Files</label>
                            <input type="file" class="form-control" id="fileToUpload" name="file[]" multiple required style="display: none;">
                            <div id="fileDisplay" class="form-control" onclick="document.getElementById('fileToUpload').click()">
                                Click here or choose files to select multiple files
                            </div>
                            <small id="fileError" class="form-text text-danger" style="display: none;">Please select at least one file to upload.</small>
                        </div>



                        <!-- Tag Input with Hover Dropdown -->
                        <div class="mb-3 position-relative">
                            <label for="tag" class="form-label"><i class="fas fa-tags me-2"></i> Tag</label>
                            <input type="text" class="form-control" id="tag" name="tag" placeholder="Enter a single tag (no commas or spaces)">
                            <small id="tagError" class="form-text text-danger d-none">Tag is required and must not contain spaces or commas.</small>
                            <div id="tagDropdown" class="dropdown-menu p-2" style="display: none; max-height: 200px; overflow-y: auto;">
                                <!-- Tags will be dynamically inserted here -->
                            </div>
                        </div>

                        <!-- Description Input
                        <div class="mb-3">
                            <label for="description" class="form-label"><i class="fas fa-edit me-2"></i> Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3" placeholder="Enter description for the files"></textarea>
                        </div> -->

                        <!-- Album Selection Dropdown with Icon -->
                        <div class="mb-3">
                            <label for="albumId" class="form-label"><i class="fas fa-images me-2"></i> Select Album</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-folder"></i></span>
                                <select class="form-control" id="albumId" name="albumId">
                                    <option value="">Select Album</option>
                                    <?php
                                    // Query to fetch all albums
                                    $albumQuery = "SELECT id, name FROM albums ORDER BY name ASC";
                                    $albumResult = $conn->query($albumQuery);

                                    if ($albumResult->num_rows > 0) {
                                        while ($album = $albumResult->fetch_assoc()) {
                                            echo '<option value="' . htmlspecialchars($album['id']) . '">' . htmlspecialchars($album['name']) . '</option>';
                                        }
                                    } else {
                                        echo '<option value="">No albums available</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <!-- Upload Button -->
                        <button type="button" class="btn w-100" style="background-color: #dc3545; color: white; border: none;" onclick="startUpload()">
                            <i class="fas fa-cloud-upload-alt me-2"></i> Upload Files
                        </button>

                    </form>

                    <!-- Progress bar and Cancel button -->
                    <div id="progressContainer" style="display: none; margin-top: 20px;">
                        <progress id="uploadProgress" value="0" max="100" style="width: 100%;"></progress>
                        <div id="progressPercentage" class="text-center mt-1">0%</div>
                        <button id="cancelUploadButton" class="btn mt-2 w-100" onclick="cancelUpload()">
                            <i class="fas fa-times me-2"></i> Cancel Upload
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- Modal for Create Album with AJAX setup -->
    <div class="modal fade" id="createAlbumModal" tabindex="-1" aria-labelledby="createAlbumModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createAlbumModalLabel">
                        <i class="fas fa-folder-plus me-2"></i> Create New Album
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="createAlbumForm">
                        <!-- Album Name Input -->
                        <div class="mb-3">
                            <label for="album-name" class="form-label">
                                <i class="fas fa-heading me-2"></i> Album Name
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-folder"></i></span>
                                <input type="text" class="form-control" id="album-name" name="name" placeholder="Enter album name" required>
                            </div>
                        </div>

                        <!-- Description Input (Commented Out) -->
                        <!-- <div class="mb-3">
                            <label for="album-description" class="form-label">
                                <i class="fas fa-edit me-2"></i> Description
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-align-left"></i></span>
                                <textarea class="form-control" id="album-description" name="description" placeholder="Enter album description"></textarea>
                            </div>
                        </div> -->

                        <!-- Save Album Button -->
                        <button type="submit" class="btn w-100" style="background-color: #dc3545; color: white; border: none;">
                            <i class="fas fa-save me-2"></i> Save Album
                        </button>

                    </form>
                </div>
                <!-- <div class="modal-footer">
                    <button type="button" class="btn btn-secondary w-100" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i> Close
                    </button> -->
            </div>
        </div>
    </div>
    </div>


    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>



    <script>


        $(document).ready(function() {
            $('#createAlbumForm').on('submit', function(e) {
                e.preventDefault();  // Prevent default form submission
                var formData = $(this).serialize();  // Serialize the form data

                $.ajax({
                    type: "POST",
                    url: "create_album.php",  // Endpoint for your PHP server-side logic
                    data: formData,
                    dataType: "json",
                    success: function(response) {
                        if (response.status === 'success') {
                            alert('Album Created Successfully!');
                            $('#createAlbumModal').modal('hide');  // Hide the modal after successful submission
                        } else {
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        alert('An error occurred: ' + error);
                    }
                });
            });
        });
    </script>



    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tagInput = document.getElementById('tag');
            const tagDropdown = document.getElementById('tagDropdown');

            // Fetch existing tags dynamically
            fetch('fetch_tags.php')
                .then(response => response.json())
                .then(tags => {
                    tags.forEach(tag => {
                        const tagOption = document.createElement('div');
                        tagOption.className = 'dropdown-item';
                        tagOption.textContent = tag;
                        tagOption.style.cursor = 'pointer';
                        tagOption.addEventListener('click', () => {
                            tagInput.value = tag; // Set the input value to the selected tag
                            tagDropdown.style.display = 'none'; // Hide dropdown after selection
                        });
                        tagDropdown.appendChild(tagOption);
                    });
                })
                .catch(error => console.error('Error fetching tags:', error));

            // Show dropdown on focus
            tagInput.addEventListener('focus', () => {
                tagDropdown.style.display = 'block';
            });

            // Hide dropdown when clicking outside
            document.addEventListener('click', (event) => {
                if (!tagInput.contains(event.target) && !tagDropdown.contains(event.target)) {
                    tagDropdown.style.display = 'none';
                }
            });

            // Hide dropdown when typing in the tag input
            tagInput.addEventListener('input', () => {
                tagDropdown.style.display = tagInput.value.trim() === '' ? 'block' : 'none';
            });
        });

        let currentUpload = null; // Variable to hold the current AJAX request

        // Start the upload
        function startUpload() {
            const formData = new FormData(document.getElementById("uploadForm"));
            const xhr = new XMLHttpRequest();

            xhr.open("POST", window.location.href, true); // Submit to the same PHP file

            // Display progress bar and reset to 0%
            document.getElementById("progressContainer").style.display = "block";
            document.getElementById("uploadProgress").value = 0;

            // Update progress bar
            xhr.upload.addEventListener("progress", (event) => {
                if (event.lengthComputable) {
                    const percentComplete = Math.round((event.loaded / event.total) * 100);
                    document.getElementById("uploadProgress").value = percentComplete;
                }
            });

            // On successful upload
            xhr.addEventListener("load", () => {
                if (xhr.status === 200) {
                    document.getElementById('successModalBody').textContent = "File uploaded successfully";
                    let successModal = new bootstrap.Modal(document.getElementById('successModal'));
                    successModal.show();
                    document.getElementById("progressContainer").style.display = "none";

                    // Reload page when the user clicks "OK" in the success modal
                    document.getElementById('successModal').querySelector('.btn-primary').addEventListener('click', function() {
                        window.location.reload();
                    });
                } else {
                    document.getElementById('errorModalBody').textContent = "Failed to upload file";
                    new bootstrap.Modal(document.getElementById('errorModal')).show();
                }
            });

            // Handle cancellation
            xhr.addEventListener("abort", () => {
                document.getElementById('errorModalBody').textContent = "Upload canceled";
                new bootstrap.Modal(document.getElementById('errorModal')).show();
                document.getElementById("progressContainer").style.display = "none";
            });

            // Track the current upload
            currentUpload = xhr;
            xhr.send(formData);
        }

        // Cancel the upload
        function cancelUpload() {
            if (currentUpload) {
                currentUpload.abort();
                currentUpload = null; // Reset the upload reference
            }
        }

        // Display selected files
        document.getElementById('fileToUpload').addEventListener('change', function() {
            const fileList = this.files;
            const fileDisplay = document.getElementById('fileDisplay');
            fileDisplay.innerHTML = fileList.length > 0 ? Array.from(fileList).map(file => file.name).join('<br>') : "Click here or choose files to select multiple files";
        });

    </script>


    <script>
        // JavaScript to display selected file names in a custom div
        document.getElementById('fileToUpload').addEventListener('change', function() {
            const fileList = this.files;
            const fileDisplay = document.getElementById('fileDisplay');
            fileDisplay.innerHTML = "";  // Clear previous content

            if (fileList.length > 0) {
                const fileNames = Array.from(fileList).map(file => file.name);
                fileDisplay.innerHTML = fileNames.join('<br>');  // Display file names separated by line breaks
            } else {
                fileDisplay.innerHTML = "Click here or choose files to select multiple files";  // Reset prompt if no files
            }
        });
    </script>


    <!-- Breadcrumb Navigation -->
    <div class="breadcrumb-container mb-3">
        <?php
        $breadcrumbs = [];
        $relative_path = str_replace($base_directory, '', $current_directory); // Get relative path from base
        $path_segments = explode('/', trim($relative_path, '/')); // Split into individual folder names

        $path_accumulated = $base_directory;
        echo '<a href="?dir=' . urlencode($base_directory) . '">Home</a>'; // Add "Home" as the root folder

        foreach ($path_segments as $segment) {
            if (!empty($segment)) {
                $path_accumulated .= '/' . $segment;
                echo ' / <a href="?dir=' . urlencode($path_accumulated) . '">' . htmlspecialchars($segment) . '</a>';
            }
        }
        ?>



        <div class="action-button-container" style="display: none;">
            <!-- Delete Selected -->
            <button type="button" class="btn btn-danger" id="deleteSelectedBtn">Delete Selected</button>

            <!-- Move to Trash -->
            <button type="button" class="btn btn-warning" id="moveToTrashBtn">Move to Trash</button>

            <!-- Add Tag -->
            <input type="text" id="tagInput" placeholder="Add tag to file/folder" style="display: none; margin-right: 10px;">
            <button type="button" class="btn btn-primary" id="addTagBtn" style="display: none;">Add Tag</button>

            <!-- Move To Button -->
            <button type="button" class="btn btn-success" id="moveToBtn">Featured:</button>

            <!-- Album Dropdown -->

            <select class="form-select" id="albumSelect" style="display: inline-block; width: auto; margin-right: 10px;">
                <option value="" disabled selected>Select Album</option>
            </select>

            <?php if(strpos($current_directory, '/Featured') !== false) { ?>
                <!-- Dropdown to Select Downloadable Status -->
                <select class="form-select" id="downloadableStatus" style="width: auto; display: inline-block; margin-right: 10px;">
                    <option value="0" selected>View</option>
                    <option value="1">Downloadable</option>
                </select>

                <!-- Update Button -->
                <button type="button" class="btn btn-primary" id="updateDownloadableBtn">Update</button>
            <?php } ?>







        </div>





        <!-- Confirmation Modal -->
        <div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="confirmationModalLabel">Confirm Action</h5>
                    </div>
                    <div class="modal-body" id="confirmationModalBody">
                        Are you sure you want to delete these files/folders?
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary modal-close-btn" data-bs-dismiss="modal">Cancel</button>
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

        <!-- ✅ Error Modal (Fixed X Button) -->
        <div class="modal fade show" id="errorModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Error</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="errorModalBody"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger" data-bs-dismiss="modal">OK</button>
                    </div>
                </div>
            </div>
        </div>


        <script>
            document.addEventListener("DOMContentLoaded", function() {
                // ✅ Get all modals
                const modals = document.querySelectorAll(".modal");

                modals.forEach(modal => {
                    const modalInstance = new bootstrap.Modal(modal); // Initialize modal

                    // ✅ Ensure the X button and OK button only close their respective modal
                    modal.querySelectorAll("[data-bs-dismiss='modal']").forEach(button => {
                        button.addEventListener("click", function () {
                            modalInstance.hide();
                        });
                    });
                });

                console.log("✅ Modal close behavior updated safely.");
            });
        </script>



        <!-- SCRIPT FOR BULK TOGGLE DOWNLOADABLE STATUS -->
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                const updateDownloadableBtn = document.querySelector('#updateDownloadableBtn');
                const downloadableStatus = document.querySelector('#downloadableStatus');
                const rowCheckboxes = document.querySelectorAll('.rowCheckbox');
                const successModal = new bootstrap.Modal(document.getElementById('successModal'));
                const errorModal = new bootstrap.Modal(document.getElementById('errorModal'));
                const confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
                const confirmationModalBody = document.getElementById('confirmationModalBody');
                const confirmActionBtn = document.getElementById('confirmActionBtn');

                function checkSelection() {
                    return Array.from(rowCheckboxes).some(checkbox => checkbox.checked);
                }

                // ✅ Update Button Click Event
                updateDownloadableBtn.addEventListener('click', function () {
                    if (!checkSelection()) {
                        document.getElementById('errorModalBody').textContent = 'No files selected.';
                        errorModal.show();
                        return;
                    }

                    const newStatus = downloadableStatus.value; // 0 = View, 1 = Downloadable

                    // ✅ Get selected files and filenames
                    const selectedFiles = Array.from(rowCheckboxes)
                        .filter(checkbox => checkbox.checked)
                        .map(checkbox => {
                            const row = checkbox.closest('tr');
                            return {
                                fileId: row.getAttribute('data-file-id'), // Ensure data-file-id is correctly set in <tr>
                                filename: row.querySelector('.file-folder-link').textContent.trim() // Extract filename
                            };
                        });

                    if (selectedFiles.length === 0) {
                        console.error("No valid files selected.");
                        return;
                    }

                    // ✅ Show confirmation modal before updating the database
                    confirmationModalBody.textContent = `Are you sure you want to update ${selectedFiles.length} file(s) to "${newStatus === "1" ? 'Downloadable' : 'View'}" status?`;
                    confirmationModal.show();

                    confirmActionBtn.onclick = async function () {
                        confirmationModal.hide();

                        try {
                            const response = await updateDownloadableStatus(selectedFiles, newStatus);
                            if (response.status === "success") {
                                document.getElementById('successModalBody').textContent = `Updated ${selectedFiles.length} file(s) successfully.`;
                                successModal.show();
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                console.error("Update failed:", response);
                                document.getElementById('errorModalBody').textContent = 'Failed to update database.';
                                errorModal.show();
                            }
                        } catch (error) {
                            console.error('Error updating downloadable status:', error);
                            document.getElementById('errorModalBody').textContent = 'Unexpected error.';
                            errorModal.show();
                        }
                    };
                });

                // ✅ Function to send AJAX request to update the database
                async function updateDownloadableStatus(files, status) {
                    return fetch('update_downloadable2.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ files, downloadable: status }) // ✅ Send 0 or 1
                    })
                        .then(response => response.json())
                        .catch(error => {
                            console.error('Error sending request:', error);
                            return { status: 'error', message: 'Network error.' };
                        });
                }
            });








        </script>


        <!-- SCRIPT FOR BULK ADD ALBUM -->

        <script>
            document.addEventListener("DOMContentLoaded", function() {
                const moveBtn = document.querySelector('#moveToBtn');
                const rowCheckboxes = document.querySelectorAll('.rowCheckbox');
                const albumSelect = document.querySelector('#albumSelect');
                const successModalEl = document.getElementById('successModal');
                const errorModalEl = document.getElementById('errorModal');
                const confirmationModalEl = document.getElementById('confirmationModal');
                const confirmationModalBody = document.getElementById('confirmationModalBody');
                const confirmActionBtn = document.getElementById('confirmActionBtn');

                // ✅ Ensure Bootstrap Modals are initialized properly
                const successModal = new bootstrap.Modal(successModalEl);
                const errorModal = new bootstrap.Modal(errorModalEl);
                const confirmationModal = new bootstrap.Modal(confirmationModalEl);

                // ✅ Close modals properly when clicking "X" or "OK"
                document.querySelectorAll("[data-bs-dismiss='modal']").forEach(btn => {
                    btn.addEventListener("click", function () {
                        successModal.hide();
                        errorModal.hide();
                        confirmationModal.hide();
                    });
                });

                // ✅ Function to load albums dynamically
                async function loadAlbums() {
                    try {
                        const response = await fetch("fetch_albums.php");
                        if (!response.ok) throw new Error('Failed to fetch albums');
                        const albums = await response.json();

                        albumSelect.innerHTML = '<option value="" disabled selected>Select Album</option>';
                        albums.forEach(album => {
                            const option = document.createElement("option");
                            option.value = album.id; // Store album ID as value
                            option.textContent = `${album.id} - ${album.name}`; // Display ID and name
                            albumSelect.appendChild(option);
                        });
                    } catch (error) {
                        console.error("Error loading albums:", error);
                        document.getElementById('errorModalBody').textContent = "Failed to load albums: " + error.message;
                        errorModal.show();
                    }
                }

                loadAlbums();

                function checkSelection() {
                    return Array.from(rowCheckboxes).some(checkbox => checkbox.checked);
                }

                moveBtn.addEventListener('click', function() {
                    if (!checkSelection()) {
                        document.getElementById('errorModalBody').textContent = 'No files selected.';
                        errorModal.show();
                        return;
                    }

                    const selectedAlbumId = parseInt(albumSelect.value); // Ensure it's an integer
                    const selectedAlbumName = albumSelect.options[albumSelect.selectedIndex]?.text.split(' - ')[1] || '';

                    if (!selectedAlbumId) {
                        document.getElementById('errorModalBody').textContent = 'No album selected.';
                        errorModal.show();
                        return;
                    }

                    const selectedFiles = Array.from(rowCheckboxes)
                        .filter(checkbox => checkbox.checked)
                        .map(checkbox => {
                            const row = checkbox.closest('tr');
                            return {
                                path: row.getAttribute('data-path'),
                                name: row.querySelector('.file-folder-link').textContent.trim()
                            };
                        });

                    // ✅ Show confirmation modal with proper dismissal
                    confirmationModalBody.textContent = `Are you sure you want to move ${selectedFiles.length} file(s) to the album "${selectedAlbumName}"?`;
                    confirmationModal.show();

                    // ✅ Ensure only one event listener is attached to the button
                    confirmActionBtn.onclick = async function () {
                        confirmationModal.hide();

                        try {
                            const moveResults = await moveFiles(selectedFiles, selectedAlbumName, selectedAlbumId);
                            const successCount = moveResults.filter(result => result.status === 'success').length;
                            const errorCount = moveResults.length - successCount;

                            document.getElementById('successModalBody').textContent = `Move complete. ${successCount} file(s) moved successfully.${errorCount > 0 ? ` ${errorCount} file(s) failed to move.` : ''}`;
                            successModal.show();

                            setTimeout(() => location.reload(), 2000);
                        } catch (error) {
                            console.error('Error during file move:', error);
                            document.getElementById('errorModalBody').textContent = 'An unexpected error occurred during the move: ' + error.message;
                            errorModal.show();
                        }
                    };
                });

                async function moveFiles(files, albumName, albumId) {
                    return Promise.all(files.map(file => {
                        return fetch('moveFile.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                filepath: file.path,
                                filename: file.name,
                                albumName: albumName,
                                albumId: albumId
                            })
                        })
                            .then(response => response.json())
                            .catch(error => {
                                console.error(`Error moving file ${file.name}:`, error);
                                return { status: 'error', message: `Network error while moving file ${file.name}: ${error.message}` };
                            });
                    }));
                }
            });
        </script>






        <!-- SCRIPT FOR BULK DELETE-->
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                // Get references to the elements
                const actionButtonContainer = document.querySelector('.action-button-container');
                const rowCheckboxes = document.querySelectorAll('.rowCheckbox');
                const deleteSelectedBtn = document.querySelector('#deleteSelectedBtn');
                const confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
                const successModal = new bootstrap.Modal(document.getElementById('successModal'));
                const errorModal = new bootstrap.Modal(document.getElementById('errorModal'));
                const confirmActionBtn = document.querySelector('#confirmActionBtn');
                const modalCloseButtons = document.querySelectorAll('.modal-close-btn');

                // Function to toggle the visibility of the action button
                function toggleActionButton() {
                    const isAnyCheckboxSelected = Array.from(rowCheckboxes).some(checkbox => checkbox.checked);
                    actionButtonContainer.style.display = isAnyCheckboxSelected ? 'block' : 'none';
                }

                // Add event listeners to all checkboxes
                rowCheckboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', toggleActionButton);
                });

                // Run once to ensure the button's initial state is correct
                toggleActionButton();

                // Bulk deletion logic with modals
                deleteSelectedBtn.addEventListener('click', async function() {
                    const selectedCheckboxes = Array.from(rowCheckboxes).filter(checkbox => checkbox.checked);

                    if (selectedCheckboxes.length === 0) {
                        document.getElementById('errorModalBody').textContent = 'No files or folders selected for deletion.';
                        errorModal.show();
                        return;
                    }

                    // Collect the names of all selected files/folders
                    const selectedItems = selectedCheckboxes.map(checkbox => {
                        const row = checkbox.closest('tr');
                        return row.querySelector('.file-folder-link').textContent.trim();
                    });

                    document.getElementById('confirmationModalBody').textContent = `Are you sure you want to delete these files/folders?\n\n${selectedItems.join('\n')}`;
                    confirmationModal.show();

                    // ✅ Ensure the modal closes properly when clicking "Cancel" or "X"
                    modalCloseButtons.forEach(button => {
                        button.addEventListener('click', () => {
                            confirmationModal.hide();
                        });
                    });

                    confirmActionBtn.onclick = async function () {
                        confirmationModal.hide();

                        const deletionPromises = selectedCheckboxes.map(checkbox => {
                            const row = checkbox.closest('tr');
                            const filePath = row.getAttribute('data-path');
                            const fileName = row.querySelector('.file-folder-link').textContent.trim();

                            return deleteMedia(filePath, fileName);
                        });

                        try {
                            const results = await Promise.all(deletionPromises);
                            const successCount = results.filter(result => result.status === 'success').length;
                            const errorCount = results.length - successCount;

                            document.getElementById('successModalBody').textContent = `Deletion complete. ${successCount} item(s) deleted successfully.${errorCount > 0 ? ` ${errorCount} item(s) failed to delete.` : ''}`;
                            successModal.show();
                            setTimeout(() => location.reload(), 1500);
                        } catch (error) {
                            console.error('Error during deletion:', error);
                            document.getElementById('errorModalBody').textContent = 'An unexpected error occurred during deletion.';
                            errorModal.show();
                        }
                    };
                });

                async function deleteMedia(filePath, fileName) {
                    try {
                        const response = await fetch('deleteMedia.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({ filepath: filePath, fileName: fileName }),
                        });
                        return await response.json();
                    } catch (error) {
                        console.error(`Error deleting file ${fileName}:`, error);
                        return { status: 'error', message: 'Network error during deletion.' };
                    }
                }
            });


        </script>


        <!-- SCRIPT TRASH-->
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                // Get references to UI elements
                const actionButtonContainer = document.querySelector('.action-button-container');
                const rowCheckboxes = document.querySelectorAll('.rowCheckbox');
                const moveToTrashBtn = document.querySelector('#moveToTrashBtn');
                const confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
                const successModal = new bootstrap.Modal(document.getElementById('successModal'));
                const errorModal = new bootstrap.Modal(document.getElementById('errorModal'));

                // Function to control visibility of the action button based on selection
                function toggleActionButton() {
                    const isAnyCheckboxSelected = Array.from(rowCheckboxes).some(checkbox => checkbox.checked);
                    actionButtonContainer.style.display = isAnyCheckboxSelected ? 'block' : 'none';
                }

                // Attach change event listeners to all checkboxes to manage action button visibility
                rowCheckboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', toggleActionButton);
                });

                // Initial state check for the action button
                toggleActionButton();

                // Event listener for the move-to-trash button
                moveToTrashBtn.addEventListener('click', async function() {
                    const selectedCheckboxes = Array.from(rowCheckboxes).filter(checkbox => checkbox.checked);

                    if (selectedCheckboxes.length === 0) {
                        document.getElementById('errorModalBody').textContent = 'No files or folders selected to move to trash.';
                        errorModal.show();
                        return;
                    }

                    // Display a list of selected items to the user for confirmation
                    const selectedItems = selectedCheckboxes.map(checkbox => {
                        const row = checkbox.closest('tr');
                        return row.querySelector('.file-folder-link').textContent.trim();
                    });

                    document.getElementById('confirmationModalBody').textContent = `The following files/folders are selected to move to trash:\n\n${selectedItems.join('\n')}`;
                    confirmationModal.show();

                    document.querySelector('#confirmActionBtn').addEventListener('click', async () => {
                        confirmationModal.hide();

                        const trashPromises = selectedCheckboxes.map(checkbox => {
                            const row = checkbox.closest('tr');
                            const filePath = row.getAttribute('data-path');
                            const fileName = row.querySelector('.file-folder-link').textContent.trim();

                            return moveToTrash(filePath, fileName);
                        });

                        try {
                            const results = await Promise.all(trashPromises);
                            const successCount = results.filter(result => result.status === 'success').length;
                            const errorCount = results.length - successCount;

                            document.getElementById('successModalBody').textContent = `Move to trash complete. ${successCount} item(s) moved successfully.${errorCount > 0 ? ` ${errorCount} item(s) failed to move.` : ''}`;
                            successModal.show();
                            location.reload();
                        } catch (error) {
                            console.error('Error during move to trash:', error);
                            document.getElementById('errorModalBody').textContent = 'An unexpected error occurred while moving items to trash.';
                            errorModal.show();
                        }
                    });
                });

                // Function to make the server request to move a file/folder to trash
                async function moveToTrash(filePath, fileName) {
                    try {
                        const response = await fetch('moveToTrash.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({ filepath: filePath, fileName: fileName })
                        });

                        if (!response.ok) {
                            throw new Error((await response.json()).message || 'Server error occurred');
                        }
                        return await response.json();
                    } catch (error) {
                        console.error(`Error moving file ${fileName} to trash:`, error);
                        return { status: 'error', message: 'Network error during move to trash.' };
                    }
                }
            });
        </script>




        <!-- SCRIPT ADD TAG-->

        <script>
            document.addEventListener("DOMContentLoaded", function() {
                // Get references to the elements
                const actionButtonContainer = document.querySelector('.action-button-container');
                const rowCheckboxes = document.querySelectorAll('.rowCheckbox');
                const addTagBtn = document.querySelector('#addTagBtn');
                const tagInput = document.querySelector('#tagInput');
                const errorModal = new bootstrap.Modal(document.getElementById('errorModal'));
                const successModal = new bootstrap.Modal(document.getElementById('successModal'));

                // Function to toggle the visibility of the action button and input
                function toggleActionButton() {
                    const isAnyCheckboxSelected = Array.from(rowCheckboxes).some(checkbox => checkbox.checked);
                    actionButtonContainer.style.display = isAnyCheckboxSelected ? 'block' : 'none';
                    tagInput.style.display = isAnyCheckboxSelected ? 'inline-block' : 'none';
                    addTagBtn.style.display = isAnyCheckboxSelected ? 'inline-block' : 'none';
                }

                // Add event listeners to all checkboxes
                rowCheckboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', toggleActionButton);
                });

                // Run once to ensure the button's initial state is correct
                toggleActionButton();

                // Add Tag button logic
                addTagBtn.addEventListener('click', async function() {
                    const selectedCheckboxes = Array.from(rowCheckboxes).filter(checkbox => checkbox.checked);
                    const tag = tagInput.value.trim();

                    if (selectedCheckboxes.length === 0) {
                        document.getElementById('errorModalBody').textContent = 'No files or folders selected to add a tag.';
                        errorModal.show();
                        return;
                    }

                    if (!tag) {
                        document.getElementById('errorModalBody').textContent = 'Please enter a tag.';
                        errorModal.show();
                        return;
                    }

                    // Collect the file paths and names of all selected files/folders
                    const selectedItems = selectedCheckboxes.map(checkbox => {
                        const row = checkbox.closest('tr');
                        return {
                            filePath: row.getAttribute('data-path'),
                            fileName: row.querySelector('.file-folder-link').textContent.trim()
                        };
                    });

                    // Send the tag request for each selected item
                    const tagPromises = selectedItems.map(item => addTagToItem(item.filePath, item.fileName, tag));

                    try {
                        // Wait for all tag requests to complete
                        const results = await Promise.all(tagPromises);

                        // Filter success and failure messages
                        const successCount = results.filter(result => result.status === 'success').length;
                        const errorCount = results.length - successCount;

                        document.getElementById('successModalBody').textContent = `Tagging complete. ${successCount} item(s) tagged successfully.${errorCount > 0 ? ` ${errorCount} item(s) failed to tag.` : ''}`;
                        successModal.show();

                        // Optionally clear the tag input and reload the page
                        tagInput.value = '';
                        location.reload();
                    } catch (error) {
                        console.error('Error during tagging:', error);
                        document.getElementById('errorModalBody').textContent = 'An unexpected error occurred while tagging.';
                        errorModal.show();
                    }
                });

                // Function to handle the add tag request
                async function addTagToItem(filePath, fileName, tag, type) {
                    try {
                        const response = await fetch('addTag.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                filepath: filePath,
                                fileName: fileName,
                                tag: tag,
                                type: type  // This needs to be determined and added
                            }),
                        });

                        if (!response.ok) {
                            throw new Error((await response.json()).message || 'Server error during tagging');
                        }
                        return await response.json();
                    } catch (error) {
                        console.error(`Error adding tag to ${fileName}:`, error);
                        return { status: 'error', message: 'Network error during tagging.' };
                    }
                }
            });
        </script>










        <style>
            .action-button-container {
                margin-top: 10px;
            }
            .btn-danger {
                background-color: #dc3545;
                color: #fff;
                border: none;
                padding: 10px 15px;
                cursor: pointer;
                font-size: 14px;
            }
            .btn-danger:hover {
                background-color: #c82333;
            }
            .btn-warning {
                background-color: #ffc107;
                color: #212529;
                border: none;
                padding: 10px 15px;
                cursor: pointer;
                font-size: 14px;
            }
            .btn-warning:hover {
                background-color: #e0a800;
            }
            .btn-primary {
                background-color: #007bff;
                color: #fff;
                border: none;
                padding: 10px 15px;
                cursor: pointer;
                font-size: 14px;
            }
            .btn-primary:hover {
                background-color: #0056b3;
            }
            #tagInput {
                padding: 5px;
                font-size: 14px;
            }


            /* The switch - the box around the slider */
            .switch {
                position: relative;
                display: inline-block;
                width: 60px;
                height: 34px;
            }

            /* Hide default HTML checkbox */
            .switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            /* The slider */
            .slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #ccc;
                transition: .4s;
            }

            .slider:before {
                position: absolute;
                content: "";
                height: 26px;
                width: 26px;
                left: 4px;
                bottom: 4px;
                background-color: white;
                transition: .4s;
            }

            input:checked + .slider {
                background-color: #2196F3;
            }

            input:checked + .slider:before {
                transform: translateX(26px);
            }

            /* Rounded sliders */
            .slider.round {
                border-radius: 34px;
            }

            .slider.round:before {
                border-radius: 50%;
            }


        </style>


        <div class="view-buttons">
            <div class="btn-group">
                <button class="btn active" onclick="switchToListView()" id="listViewBtn">
                    <i class="fas fa-list"></i>
                </button>
                <button class="btn" onclick="switchToGridView()" id="gridViewBtn">
                    <i class="fas fa-th"></i>
                </button>
            </div>
        </div>

        <div id="fileContainer" class="list-view table-responsive">
            <table class="datatable table table-hover table-striped" id="fileTable">
                <thead>
                <tr>
                    <th><input type="checkbox" id="selectAllCheckbox"></th>
                    <th>File/Folder Name</th>
                    <th>Type</th>
                    <th class="file-path-column">File Path</th>
                    <?php if($isFeaturedDir) { ?>
                        <th>Downloadable</th>
                        <th>View</th>
                    <?php } ?>
                    <th></th>
                    <th>Actions</th>
                </tr>
                </thead>


                <tbody>
                <?php if (empty($_GET['search'])): ?>
                    <?php
                    $items = array_filter(scandir($current_directory), function ($item) use ($current_directory) {
                        return $item !== '.' && $item !== '..' && $item !== '.DS_Store' && file_exists($current_directory . '/' . $item);
                    });

                    foreach ($items as $item):
                        $item_path = $current_directory . '/' . $item;
                        $is_dir = is_dir($item_path);
                        $item_type = $is_dir ? 'folder' : 'file';
                        $web_url = convertFilePathToURL($item_path); // Convert the file path to a web URL

                        // Fetch downloadable and featured statuses from the database
                        $query = "SELECT downloadable, featured FROM album_files WHERE filename = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("s", $item);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $file = $result->fetch_assoc();
                        $downloadable_status = $file['downloadable'] ?? 0; // Default to 0 if not found
                        $featured_status = $file['featured'] ?? 0;         // Default to 0 if not found

                        ?>
                        <tr data-path="<?php echo htmlspecialchars($item_path, ENT_QUOTES, 'UTF-8'); ?>">
                            <td><input type="checkbox" class="rowCheckbox"></td>
                            <td>
                                <?php if ($is_dir): ?>
                                    <a href="?dir=<?php echo urlencode($item_path); ?>" class="file-folder-link"
                                       onclick="recordActivity('<?php echo addslashes($item); ?>', 'folder', '<?php echo htmlspecialchars($item_path); ?>')">
                                        <?php echo htmlspecialchars($item); ?>
                                    </a>
                                <?php else: ?>
                                    <a href="javascript:void(0);" class="file-folder-link"
                                       data-url="<?php echo htmlspecialchars($web_url); ?>"
                                       data-type="<?php echo htmlspecialchars(pathinfo($item, PATHINFO_EXTENSION)); ?>"
                                       onclick="recordActivity('<?php echo addslashes($item); ?>', 'file', '<?php echo htmlspecialchars($item_path); ?>'); openModal('<?php echo htmlspecialchars($web_url); ?>', '<?php echo htmlspecialchars(pathinfo($item, PATHINFO_EXTENSION)); ?>')">
                                        <?php echo htmlspecialchars($item); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $is_dir ? 'Folder' : 'File'; ?></td>
                            <td class="file-path-column">
                                <div class="file-path-wrapper">
                                    <a href="<?php echo htmlspecialchars($web_url); ?>" target="_blank" class="file-path">
                                        <?php echo htmlspecialchars($web_url); ?>
                                    </a>
                                </div>
                            </td>
                            <?php if($isFeaturedDir) { ?>
                                <td>
                                    <!-- Toggle switch for downloadable status -->
                                    <label class="switch">
                                        <input type="checkbox" class="downloadable-toggle" data-file-name="<?php echo htmlspecialchars($item); ?>"
                                            <?php echo ($downloadable_status) ? 'checked' : ''; ?>>
                                        <span class="slider round"></span>
                                    </label>
                                </td>
                                <td>
                                    <!-- Toggle switch for featured status -->
                                    <label class="switch">
                                        <input type="checkbox" class="featured-toggle" data-file-name="<?php echo htmlspecialchars($item); ?>"
                                            <?php echo ($featured_status) ? 'checked' : ''; ?>>
                                        <span class="slider round"></span>
                                    </label>
                                </td>
                            <?php } ?>
                            <td>


                                <script>
                                    $(document).ready(function() {
                                        $(".featured-toggle").change(function () {
                                            let fileName = $(this).data("file-name");
                                            let isChecked = $(this).prop("checked") ? 1 : 0;

                                            $.ajax({
                                                url: "update_featured.php", // New PHP file to handle featured status update
                                                type: "POST",
                                                data: { filename: fileName, featured: isChecked },
                                                success: function (response) {
                                                    console.log("Featured update response:", response);
                                                },
                                                error: function () {
                                                    alert("Error updating featured status.");
                                                }
                                            });
                                        });
                                    });

                                </script>
                            <td>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-danger dropdown-toggle" type="button" id="dropdownActions-<?php echo htmlspecialchars($item); ?>" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fas fa-cogs"></i> Actions
                                    </button>
                                    <ul class="dropdown-menu" aria-labelledby="dropdownActions-<?php echo htmlspecialchars($item); ?>">
                                        <li><a class="dropdown-item" href="javascript:void(0);" onclick="renameMedia('<?php echo htmlspecialchars($item_path); ?>', '<?php echo htmlspecialchars($item); ?>')"><i class="fas fa-i-cursor"></i> Rename</a></li>
                                        <li><a class="dropdown-item" href="javascript:void(0);" onclick="copyMedia('<?php echo htmlspecialchars($item_path); ?>')"><i class="fas fa-copy"></i> Duplicate</a></li>
                                        <li><a class="dropdown-item" href="javascript:void(0);" onclick="downloadMedia('<?php echo htmlspecialchars($item_path); ?>')"><i class="fas fa-download"></i> Download</a></li>
                                        <li><a class="dropdown-item text-danger" href="javascript:void(0);" onclick="deleteMedia('<?php echo htmlspecialchars($item_path); ?>', '<?php echo htmlspecialchars($item); ?>')"><i class="fas fa-trash"></i> Delete</a></li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>

                    <!-- Display search results -->
                    <?php if (!empty($searchResults['folders']) || !empty($searchResults['files'])): ?>
                        <?php foreach ($searchResults['folders'] as $folder): ?>
                            <?php
                            // Fetch the downloadable status for the folder from the database
                            $query = "SELECT downloadable FROM album_files WHERE filename = ?";
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("s", $folder['filename']);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $dbData = $result->fetch_assoc();
                            $downloadable_status = $dbData['downloadable'] ?? 0; // Default to OFF if not found
                            ?>
                            <tr>
                                <td><input type="checkbox" class="rowCheckbox"></td>
                                <td>
                                    <a href="?dir=<?php echo urlencode($folder['filepath']); ?>"
                                       onclick="recordActivity('<?php echo addslashes($folder['filename']); ?>', 'folder')">
                                        <?php echo htmlspecialchars($folder['filename']); ?>
                                    </a>
                                </td>
                                <td>Folder</td>
                                <td>Unknown</td>
                                <td class="file-path-column">
                                    <div class="file-path-wrapper">
                                        <a href="<?php echo htmlspecialchars(convertFilePathToURL($folder['filepath'])); ?>" target="_blank" class="file-path">
                                            <?php echo htmlspecialchars(convertFilePathToURL($folder['filepath'])); ?>
                                        </a>
                                    </div>
                                </td>
                                <td>
                                    <!-- Toggle switch for downloadable status -->
                                    <label class="switch">
                                        <input type="checkbox" class="downloadable-toggle" data-file-name="<?php echo htmlspecialchars($folder['filename']); ?>"
                                            <?php echo ($downloadable_status) ? 'checked' : ''; ?>>
                                        <span class="slider round"></span>
                                    </label>
                                </td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-danger dropdown-toggle" type="button" id="dropdownActions-<?php echo htmlspecialchars($folder['filename']); ?>" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="fas fa-cogs"></i> Actions
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="javascript:void(0);" onclick="renameMedia('<?php echo htmlspecialchars($folder['filepath']); ?>', '<?php echo htmlspecialchars($folder['filename']); ?>')"><i class="fas fa-i-cursor"></i> Rename</a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0);" onclick="copyMedia('<?php echo htmlspecialchars($folder['filepath']); ?>')"><i class="fas fa-copy"></i> Copy</a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0);" onclick="downloadMedia('<?php echo htmlspecialchars($folder['filepath']); ?>')"><i class="fas fa-download"></i> Download</a></li>
                                            <li><a class="dropdown-item text-danger" href="javascript:void(0);" onclick="deleteMedia('<?php echo htmlspecialchars($folder['filepath']); ?>', '<?php echo htmlspecialchars($folder['filename']); ?>')"><i class="fas fa-trash"></i> Delete</a></li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php foreach ($searchResults['files'] as $file): ?>
                            <?php
                            // Fetch the downloadable status for the file from the database
                            $query = "SELECT downloadable FROM album_files WHERE filename = ?";
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("s", $file['filename']);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $dbData = $result->fetch_assoc();
                            $downloadable_status = $dbData['downloadable'] ?? 0; // Default to OFF if not found
                            ?>
                            <tr>
                                <td><input type="checkbox" class="rowCheckbox"></td>
                                <td>
                                    <a href="javascript:void(0);" class="file-folder-link"
                                       data-url="<?php echo htmlspecialchars(convertFilePathToURL($file['filepath'])); ?>"
                                       data-type="<?php echo htmlspecialchars(pathinfo($file['filepath'], PATHINFO_EXTENSION)); ?>"
                                       onclick="openModal('<?php echo htmlspecialchars(convertFilePathToURL($file['filepath'])); ?>', '<?php echo htmlspecialchars(pathinfo($file['filepath'], PATHINFO_EXTENSION)); ?>')">
                                        <?php echo htmlspecialchars($file['filename']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($file['filetype']); ?></td>
                                <td class="file-path-column">
                                    <div class="file-path-wrapper">
                                        <a href="<?php echo htmlspecialchars(convertFilePathToURL($file['filepath'])); ?>" target="_blank" class="file-path">
                                            <?php echo htmlspecialchars(convertFilePathToURL($file['filepath'])); ?>
                                        </a>
                                    </div>
                                </td>
                                <td>
                                    <!-- Toggle switch for downloadable status -->
                                    <label class="switch">
                                        <input type="checkbox" class="downloadable-toggle" data-file-name="<?php echo htmlspecialchars($file['filename']); ?>"
                                            <?php echo ($downloadable_status) ? 'checked' : ''; ?>>
                                        <span class="slider round"></span>
                                    </label>
                                </td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-danger dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="fas fa-cogs"></i> Actions
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="javascript:void(0);" onclick="copyMedia('<?php echo htmlspecialchars($file['filepath']); ?>')"><i class="fas fa-copy"></i> Copy</a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0);" onclick="downloadMedia('<?php echo htmlspecialchars($file['filepath']); ?>')"><i class="fas fa-download"></i> Download</a></li>
                                            <li><a class="dropdown-item text-danger" href="javascript:void(0);" onclick="deleteMedia('<?php echo htmlspecialchars($file['filepath']); ?>', '<?php echo htmlspecialchars($file['filename']); ?>')"><i class="fas fa-trash"></i> Delete</a></li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7">No matching files or folders found.</td></tr>
                    <?php endif; ?>


                <?php endif; ?>
                </tbody>
            </table>
        </div>


        <div id="fileGrid" class="grid-view" style="display: none;">
            <?php foreach ($items as $item): ?>
                <?php
                $item_path = $current_directory . '/' . $item;
                $is_dir = is_dir($item_path);
                $item_type = $is_dir ? 'folder' : 'file';
                $web_url = convertFilePathToURL($item_path);
                ?>
                <div class="grid-item">
                    <?php if ($is_dir): ?>
                        <a href="?dir=<?php echo urlencode($item_path); ?>" class="file-folder-link">
                            <div class="file-thumbnail">[Folder Icon]</div>
                            <p><?php echo htmlspecialchars($item); ?></p>
                        </a>
                    <?php else: ?>
                        <a href="javascript:void(0);" class="file-folder-link" onclick="openModal('<?php echo htmlspecialchars($web_url); ?>', '<?php echo htmlspecialchars(pathinfo($item, PATHINFO_EXTENSION)); ?>')">
                            <div class="file-thumbnail">
                                <?php if (preg_match('/(jpg|jpeg|png|gif)$/i', pathinfo($item, PATHINFO_EXTENSION))): ?>
                                    <img src="<?php echo htmlspecialchars($web_url); ?>" alt="Image">
                                <?php elseif (preg_match('/(mp4|mov)$/i', pathinfo($item, PATHINFO_EXTENSION))): ?>
                                    <video src="<?php echo htmlspecialchars($web_url); ?>" controls></video>
                                <?php else: ?>
                                    <span>No Preview</span>
                                <?php endif; ?>
                            </div>
                            <p><?php echo htmlspecialchars($item); ?></p>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <script>
            $(document).ready(function() {
                $("#search-bar").autocomplete({
                    source: function(request, response) {
                        $.ajax({
                            url: "fetch_tags.php",
                            type: "GET",
                            dataType: "json",
                            data: {
                                term: request.term
                            },
                            success: function(data) {
                                response(data);
                            }
                        });
                    },
                    minLength: 2,
                    select: function(event, ui) {
                        console.log(ui.item.value); // Example action on select
                    }
                });
            });
        </script>


        <script>
            $(document).ready(function () {
                $(".downloadable-toggle").change(function () {
                    let fileName = $(this).data("file-name");
                    let isChecked = $(this).prop("checked") ? 1 : 0;

                    $.ajax({
                        url: "update_downloadable.php",
                        type: "POST",
                        data: { filename: fileName, downloadable: isChecked },
                        success: function (response) {
                            console.log(response);
                        },
                        error: function () {
                            alert("Error updating downloadable status.");
                        }
                    });
                });
            });

        </script>



        <!-- DataTables Initialization -->
        <script>
            $.fn.dataTable.ext.errMode = 'none'; // Disable DataTables warnings

            $(document).ready(function() {
                if (!$.fn.DataTable.isDataTable("#fileTable")) {
                    $('#fileTable').DataTable({
                        "paging": true,
                        "lengthMenu": [10, 25, 50, 100],
                        "pageLength": 10,
                        "ordering": true,
                        "searching": false,
                        "info": true,
                        "autoWidth": false,
                        "destroy": true

                    });
                }

                // Select All Checkbox functionality
                $("#selectAllCheckbox").on("click", function() {
                    $(".rowCheckbox").prop("checked", this.checked);
                });
            });
        </script>

        </script>






        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const selectAllCheckbox = document.getElementById('selectAllCheckbox');
                const rowCheckboxes = document.querySelectorAll('.rowCheckbox');
                const actionButtonContainer = document.querySelector('.action-button-container');

                // Function to toggle the visibility of the action button container
                function toggleActionButtons() {
                    // Check if any checkbox is checked
                    const anyChecked = selectAllCheckbox.checked || Array.from(rowCheckboxes).some(checkbox => checkbox.checked);

                    // Show or hide the action button container
                    actionButtonContainer.style.display = anyChecked ? 'block' : 'none';
                }

                // Event listeners for all checkboxes
                selectAllCheckbox.addEventListener('change', toggleActionButtons);
                rowCheckboxes.forEach(checkbox => checkbox.addEventListener('change', toggleActionButtons));
            });

        </script>

        <script>
            $(document).ready(function() {
                $('#fileTable').DataTable({
                    "order": [[3, "desc"]],
                    "pageLength": 5,
                    "lengthMenu": [5, 10, 25, 50, 100],
                    "dom": 'lfrtip'
                });
            });

            function openModal(fileUrl, fileType) {
                // Implement logic to open file preview in modal
            }

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



        <script>


            document.getElementById('selectAllCheckbox').addEventListener('click', function() {
                // Get all checkboxes with the class 'rowCheckbox'
                const checkboxes = document.querySelectorAll('.rowCheckbox');

                // Set each checkbox's checked state based on the 'selectAllCheckbox' state
                checkboxes.forEach(function(checkbox) {
                    checkbox.checked = document.getElementById('selectAllCheckbox').checked;
                });
            });




            //PUBLISH SCRIPT
            async function publishMedia(filepath) {
                try {
                    const response = await fetch('publishMedia.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ filepath: filepath })
                    });

                    const result = await response.json();

                    if (result.status === 'success') {
                        alert('File published successfully!');
                    } else {
                        alert('Error: ' + result.message);
                    }
                } catch (error) {
                    console.error('Error publishing file:', error);
                    alert('An error occurred while publishing the file.');
                }
            }








            // Function to open the edit modal
            function openEditModal(filepath, filename, tag = '', description = '') {
                // Debugging
                console.log({ filepath, filename, tag, description });

                // Detect if the item is a folder
                const isFolder = filepath.endsWith('/');

                // Populate the modal fields
                const editFilename = document.getElementById('editFilename');
                const editFilePath = document.getElementById('editFilePath');
                const tagField = document.getElementById('editTag');
                const descriptionField = document.getElementById('editDescription');
                const tagContainer = document.getElementById('tagContainer');
                const descriptionContainer = document.getElementById('descriptionContainer');

                editFilename.value = filename;
                editFilePath.value = filepath;

                if (isFolder) {
                    // Only enable editing the folder name
                    tagContainer.style.display = 'none';
                    descriptionContainer.style.display = 'none';
                    tagField.value = '';
                    descriptionField.value = '';
                } else {
                    // Enable editing the file details
                    tagContainer.style.display = 'block';
                    descriptionContainer.style.display = 'block';
                    tagField.value = tag || '';
                    descriptionField.value = description || '';
                }

                // Show the modal
                const editModal = new bootstrap.Modal(document.getElementById('editModal'));
                editModal.show();
            }

            // Function to save file/folder changes
            function saveFileChanges() {
                const filepath = document.getElementById('editFilePath').value;
                const filename = document.getElementById('editFilename').value.trim();
                const tag = document.getElementById('editTag').value.trim();
                const description = document.getElementById('editDescription').value.trim();

                // Validate filename
                if (!filename) {
                    document.getElementById('filenameError').style.display = 'block';
                    return;
                } else {
                    document.getElementById('filenameError').style.display = 'none';
                }

                // Prepare data to send
                const isFolder = filepath.endsWith('/');
                const data = {
                    filepath,
                    filename,
                };

                if (!isFolder) {
                    data.tag = tag;
                    data.description = description;
                }

                // Send AJAX request to update file/folder details
                fetch('update_files_details.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data),
                })
                    .then((response) => response.json())
                    .then((result) => {
                        if (result.status === 'success') {
                            alert('Details updated successfully!');
                            location.reload(); // Refresh the table
                        } else {
                            alert('Error updating details: ' + result.message);
                        }
                    })
                    .catch((error) => {
                        console.error('Error:', error);
                        alert('An error occurred while updating the details.');
                    });
            }
        </script>



        <script>
            // Apply dynamic hover behavior to truncated file paths
            document.addEventListener("DOMContentLoaded", function () {
                const filePathElements = document.querySelectorAll(".file-path");

                filePathElements.forEach((element) => {
                    const isOverflowing = element.scrollWidth > element.clientWidth;

                    // If the text overflows, set the title attribute for the hover effect
                    if (isOverflowing) {
                        element.setAttribute("title", element.textContent.trim());
                    } else {
                        element.removeAttribute("title");
                    }
                });
            });
        </script>

        <script>
            function recordActivity(itemName, itemType, filePath) {
                fetch("record_activity.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify({
                        item_name: itemName,
                        item_type: itemType, // 'file' or 'folder'
                        filepath: filePath
                    })
                })
                    .then(response => {
                        if (response.ok) {
                            console.log("Activity recorded successfully for " + itemName);
                        } else {
                            console.error("Failed to record activity for " + itemName);
                        }
                    })
                    .catch(error => console.error("Error:", error));
            }

        </script>



        <script>
            let debounceTimeout;

            // Reference to the search bar
            const searchBar = document.getElementById('search-bar');

            // Input event with debounce logic
            searchBar.addEventListener('input', function () {
                const searchTerm = this.value.trim();

                clearTimeout(debounceTimeout); // Clear previous timer

                // Only search if the input is greater than 3 characters, or if the input is empty
                if (searchTerm.length >= 3 || searchTerm.length === 0) {
                    debounceTimeout = setTimeout(() => {
                        performSearch(searchTerm);
                    }, 5000); // 5000ms delay
                }
            });

            // Keypress event to trigger search immediately on "Enter"
            searchBar.addEventListener('keypress', function (event) {
                if (event.key === 'Enter') {
                    const searchTerm = this.value.trim();
                    clearTimeout(debounceTimeout); // Clear debounce to prioritize Enter key
                    performSearch(searchTerm); // Trigger the search
                }
            });

            // Function to perform search
            function performSearch(searchTerm) {
                // If search bar is empty, reload without search parameter; else, run the search
                if (searchTerm.length === 0) {
                    window.location.href = window.location.pathname; // Clears search and reloads page
                } else {
                    window.location.href = `?search=${encodeURIComponent(searchTerm)}`;
                }
            }

        </script>



        <!-- Scroll to Top Button -->
        <a href="#" class="back-to-top d-flex align-items-center justify-content-center">
            <i class="bi bi-arrow-up-short"></i>
        </a>
</main><!-- End #main -->

<!-- File Preview Section -->
<div id="file-preview-overlay">
    <button id="close-preview-btn" onclick="closePreview()">&#10005;</button>
    <button id="prev-btn" class="navigation-btn" onclick="navigateFile('prev')">&#8249;</button>
    <button id="next-btn" class="navigation-btn" onclick="navigateFile('next')">&#8250;</button>
    <div id="file-preview-content"></div>
</div>

<!-- Preloader JavaScript -->
<script>
    window.addEventListener("load", function() {
        const preloader = document.getElementById("preloader");
        preloader.style.display = "none"; // Hide the preloader when the page is fully loaded
    });
</script>



<!-- Template Main JS File -->
<!-- <script src="assets/js/main.js"></script> -->

<script>
    // Declare global variables for tracking the current file index and the list of files
    var currentFiles = [];
    var currentIndex = 0;

    // Function to open the preview overlay and display the image or video
    function openModal(fileUrl, fileType) {
        var overlay = document.getElementById("file-preview-overlay");
        var content = document.getElementById("file-preview-content");
        content.innerHTML = ""; // Clear previous content

        // Add cache-busting query string
        const updatedFileUrl = `${fileUrl}?t=${new Date().getTime()}`;

        // Create the appropriate preview element based on file type
        if (fileType.match(/(jpg|jpeg|png|gif|webp)$/i)) {
            var img = document.createElement("img");
            img.src = updatedFileUrl;
            img.className = "preview-media";
            content.appendChild(img);
        } else if (fileType.match(/(mp4|mp3|wav|mov|CR2|xmp)$/i)) {
            var video = document.createElement("video");
            video.src = updatedFileUrl;
            video.className = "preview-media";
            video.controls = true;
            content.appendChild(video);
        } else if (fileType === 'folder') {
            var text = document.createElement("p");
            text.textContent = "This is a folder. Preview is not available.";
            content.appendChild(text);
        }

        overlay.style.display = "flex"; // Show the overlay
    }




    // Function to close the preview overlay
    function closePreview() {
        var overlay = document.getElementById("file-preview-overlay");
        var content = document.getElementById("file-preview-content");

        // Check if there's a video or audio element inside the content and pause it
        var media = content.querySelector("video, audio");
        if (media) {
            media.pause(); // Pause the media
            media.currentTime = 0; // Optional: Reset to the start of the media
        }

        overlay.style.display = "none"; // Hide the overlay
    }

    // Function to navigate to the previous or next file in the list
    function navigateFile(direction) {
        if (direction === 'next' && currentIndex < currentFiles.length - 1) {
            currentIndex++;
        } else if (direction === 'prev' && currentIndex > 0) {
            currentIndex--;
        } else {
            console.log("Navigation limit reached");
            return; // Prevent further execution if at the limits
        }

        const currentFile = currentFiles[currentIndex];
        if (currentFile) {
            console.log("Navigating to:", currentFile); // Log the file being navigated to
            openModal(currentFile.url, currentFile.type);
        } else {
            console.error("No file found for current index:", currentIndex);
        }
    }


    // Helper function to gather file URLs and types
    function getFileList() {
        var fileElements = document.querySelectorAll('.file-folder-link'); // Adjusted selector
        var files = [];

        fileElements.forEach(function (element, index) {
            var fileUrl = element.getAttribute('data-url');
            var fileType = element.getAttribute('data-type');

            if (fileUrl && fileType) {
                files.push({ url: fileUrl, type: fileType });
            } else {
                console.warn(`File link missing attributes: index=${index}`, element);
            }
        });

        console.log("Collected files:", files); // Log files for debugging
        return files;
    }

    // Populate currentFiles
    document.addEventListener('DOMContentLoaded', function () {
        currentFiles = getFileList();
        console.log("Initialized currentFiles:", currentFiles);
    });

    //Set currentIndex When Opening Modal
    document.querySelectorAll('.file-folder-link').forEach(function (element, index) {
        element.addEventListener('click', function () {
            currentIndex = index; // Set the global index
            var fileUrl = element.getAttribute('data-url');
            var fileType = element.getAttribute('data-type');
            openModal(fileUrl, fileType);
        });
    });





    // Helper function to extract filename from file path if needed
    function extractFileName(filePath) {
        return filePath.split('/').pop(); // Extracts the last part of the path as the filename
    }

    // Function to rename a file/folder using modal
    function renameMedia(filePath, fileName) {
        if (!fileName) fileName = extractFileName(filePath); // Ensure filename is extracted

        document.getElementById('confirmationModalTitle').textContent = "Rename File";
        document.getElementById('confirmationModalBody').innerHTML = `
        Enter a new name for "<strong>${fileName}</strong>":
        <input type="text" class="form-control mt-2" id="newFileName" value="${fileName}">
    `;

        let confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
        confirmationModal.show();

        document.getElementById('confirmActionBtn').textContent = "Rename";
        document.getElementById('confirmActionBtn').onclick = function () {
            const newName = document.getElementById('newFileName').value.trim();
            if (!newName || newName === fileName) {
                confirmationModal.hide();
                showErrorModal("You did not change the file name.");
                return;
            }

            fetch('rename_file.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ filePath, newName })
            })
                .then(response => response.json())
                .then(data => {
                    confirmationModal.hide();
                    if (data.success) {
                        showSuccessModal(`"${fileName}" renamed successfully!`);
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showErrorModal(`Error renaming file: ${data.error}`);
                    }
                })
                .catch(() => showErrorModal("An error occurred while renaming the file."));
        };
    }

    // Function to delete a file/folder using modal
    function deleteMedia(filePath, fileName, itemType = 'file') {
        if (!fileName) fileName = extractFileName(filePath);
        document.getElementById('confirmationModalTitle').textContent = "Confirm Delete";
        document.getElementById('confirmationModalBody').innerHTML =
            `Are you sure you want to delete "<strong>${fileName}</strong>"? This action cannot be undone.`;
        let confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
        confirmationModal.show();
        document.getElementById('confirmActionBtn').textContent = "Delete";
        document.getElementById('confirmActionBtn').onclick = async function () {
            confirmationModal.hide(); // Hide modal before proceeding
            try {
                const response = await fetch('deleteMediaFile.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ filepath: filePath })
                });
                const data = await response.json();
                if (data.status === 'success') {
                    // If the item is a folder and is not inside the 'Featured' directory, show "Folder Deleted"
                    let message = `"${fileName}" deleted successfully!`;
                    if (itemType === 'folder' && !filePath.includes('/Featured')) {
                        message = "Folder Deleted";
                    }
                    showSuccessModal(message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showErrorModal(`Error deleting file: ${data.message}`);
                }
            } catch (error) {
                console.error("Error deleting file:", error);
                showErrorModal("An error occurred while deleting the file.");
            }
        };
    }



    // Function to copy a file using modal
    function copyMedia(filePath, fileName) {
        if (!fileName) fileName = extractFileName(filePath);

        document.getElementById('confirmationModalTitle').textContent = "Confirm Duplicate";
        document.getElementById('confirmationModalBody').innerHTML = `Are you sure you want to copy "<strong>${fileName}</strong>"?`;

        let confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
        confirmationModal.show();

        document.getElementById('confirmActionBtn').textContent = "Duplicate";
        document.getElementById('confirmActionBtn').onclick = function () {
            fetch('copy_file.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ filePath })
            })
                .then(response => response.json())
                .then(data => {
                    confirmationModal.hide();
                    if (data.success) {
                        showSuccessModal(`"${fileName}" copied successfully!`);
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showErrorModal(`Error copying file: ${data.error}`);
                    }
                })
                .catch(() => showErrorModal("An error occurred while copying the file."));
        };
    }

    // Function to download a file using modal and show success
    function downloadMedia(filePath, fileName) {
        if (!fileName) fileName = extractFileName(filePath);

        document.getElementById('confirmationModalTitle').textContent = "Confirm Download";
        document.getElementById('confirmationModalBody').innerHTML = `Do you want to download "<strong>${fileName}</strong>"?`;

        let confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
        confirmationModal.show();

        document.getElementById('confirmActionBtn').textContent = "Download";
        document.getElementById('confirmActionBtn').onclick = function () {
            confirmationModal.hide();

            // Create a hidden download link
            let link = document.createElement('a');
            link.href = `download_file.php?file=${encodeURIComponent(filePath)}`;
            link.target = "_blank";
            link.download = fileName;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            // Show success modal after download starts
            showSuccessModal(`"${fileName}" is being downloaded.`);
        };
    }

    // Function to show success modal (Red OK Button)
    function showSuccessModal(message) {
        document.getElementById("successModalBody").innerHTML = message;
        let successModal = new bootstrap.Modal(document.getElementById("successModal"));
        successModal.show();
    }

    // Function to show error modal (Red OK Button)
    function showErrorModal(message) {
        document.getElementById("errorModalBody").innerHTML = message;
        let errorModal = new bootstrap.Modal(document.getElementById("errorModal"));
        errorModal.show();
    }



    document.addEventListener('DOMContentLoaded', function() {
        // Gather all file links on the page
        var files = getFileList();

        // Assign the modal open event to each file link
        var fileElements = document.querySelectorAll('.file-link');
        fileElements.forEach(function (element, index) {
            element.addEventListener('click', function() {
                var fileUrl = element.getAttribute('data-url');
                var fileType = element.getAttribute('data-type');
                openModal(fileUrl, fileType, index, files);
            });
        });

        // Dropdown selection with option replacement and "x" button logic
        document.querySelectorAll('.dropdown-menu a').forEach(item => {
            item.addEventListener('click', function(event) {
                event.preventDefault();
                const parentDropdown = this.closest('.dropdown');
                const dropdownButton = parentDropdown.querySelector('button');
                const span = dropdownButton.querySelector('span');
                const selectedValue = this.getAttribute('data-value');

                // Update the button with the selected option and show "x" button
                span.innerHTML = `${selectedValue} <button class="btn btn-sm btn-outline-secondary ms-2 remove-selection" type="button">&times;</button>`;

                // Close other dropdowns
                closeOtherDropdowns(parentDropdown);
            });
        });

        // Add functionality to reset the dropdown when "x" is clicked
        document.addEventListener('click', function(event) {
            if (event.target.classList.contains('remove-selection')) {
                const parentDropdown = event.target.closest('.dropdown');
                const dropdownButton = parentDropdown.querySelector('button');
                const span = dropdownButton.querySelector('span');

                span.textContent = dropdownButton.id.replace('dropdown', ''); // Reset to original text
            }
        });

        // Function to close other dropdowns
        function closeOtherDropdowns(currentDropdown) {
            document.querySelectorAll('.dropdown-menu').forEach(dropdown => {
                const parentDropdown = dropdown.closest('.dropdown');
                if (parentDropdown !== currentDropdown) {
                    bootstrap.Dropdown.getInstance(dropdown.previousElementSibling)?.hide();
                }
            });
        }
    });
    document.addEventListener('DOMContentLoaded', function() {
        const uploadForm = document.getElementById('uploadForm');
        const fileInput = document.getElementById('fileToUpload');

        // Optional: Drag-and-drop support
        uploadForm.addEventListener('dragover', (event) => {
            event.preventDefault();
            uploadForm.classList.add('dragging');
        });

        uploadForm.addEventListener('dragleave', () => {
            uploadForm.classList.remove('dragging');
        });

        uploadForm.addEventListener('drop', (event) => {
            event.preventDefault();
            uploadForm.classList.remove('dragging');
            fileInput.files = event.dataTransfer.files; // Set the dropped files as input files
        });
    });

</script>



<script src="assets/js/main.js"></script>


</body>

<?php
require 'footer.php';
?>
</html>
