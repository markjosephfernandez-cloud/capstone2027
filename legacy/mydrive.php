<?php
session_start(); // Ensure this is the very first line with no whitespace above
require "config.php";
// Handle file upload and redirect logic
// Set the base directory path
$base_directory = '/Volumes/creative/categorizesample';
$current_directory = isset($_GET['dir']) ? urldecode($_GET['dir']) : $base_directory;

// Function to perform search on both files and folders
function searchFilesAndFolders($conn, $searchTerm) {
    $searchTerm = '%' . $conn->real_escape_string($searchTerm) . '%';

    // Prepare SQL queries to search in both tables
    $fileQuery = $conn->prepare("SELECT * FROM files WHERE filename LIKE ? OR filepath LIKE ? ")    ;
    $fileQuery->bind_param("ss", $searchTerm, $searchTerm);
    $fileQuery->execute();
    $fileResults = $fileQuery->get_result();

    $folderQuery = $conn->prepare("SELECT * FROM folders WHERE filename LIKE ? OR filepath LIKE ?");
    $folderQuery->bind_param("ss", $searchTerm, $searchTerm);
    $folderQuery->execute();
    $folderResults = $folderQuery->get_result();

    // Combine results
    $results = [
        'files' => $fileResults->fetch_all(MYSQLI_ASSOC),
        'folders' => $folderResults->fetch_all(MYSQLI_ASSOC),
    ];

    // Close statements
    $fileQuery->close();
    $folderQuery->close();

    return $results;
}

// Capture search term from URL if present
$searchResults = [];
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];
    $searchResults = searchFilesAndFolders($conn, $searchTerm);
}


// Database connection parameters
$servername = "localhost";
$username = "root";
$password = "capstone2425";
$dbname = "greyhoundhub";
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file'])) {
    $response = ['status' => 'success', 'message' => ''];
    $fileCount = count($_FILES['file']['name']);
    $description = isset($_POST['description']) ? $_POST['description'] : NULL;

    for ($i = 0; $i < $fileCount; $i++) {
        $fileName = $_FILES['file']['name'][$i];
        $fileTmpPath = $_FILES['file']['tmp_name'][$i];
        $fileType = $_FILES['file']['type'][$i];
        $fileSize = $_FILES['file']['size'][$i];
        $fileError = $_FILES['file']['error'][$i];
        $filePath = $current_directory . '/' . basename($fileName);

        // Check for upload errors
        if ($fileError === UPLOAD_ERR_OK) {
            if (move_uploaded_file($fileTmpPath, $filePath)) {
                // Insert file details into the database
                $stmt = $conn->prepare("INSERT INTO files(filename, filepath, filetype, size, dateupload, description) VALUES (?, ?, ?, ?, NOW(), ?)");
                if ($stmt === false) {
                    $response['status'] = 'error';
                    $response['message'] .= "Error preparing statement for $fileName. ";
                    continue;
                }

                $stmt->bind_param("sssis", $fileName, $filePath, $fileType, $fileSize, $description);

                if (!$stmt->execute()) {
                    $response['status'] = 'error';
                    $response['message'] .= "Error uploading $fileName to database. ";
                }
                $stmt->close();
            } else {
                $response['status'] = 'error';
                $response['message'] .= "Error moving $fileName to directory. ";
            }
        } else {
            $response['status'] = 'error';
            $response['message'] .= "Upload error code $fileError for $fileName. ";
        }
    }

    // Return response for AJAX
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
                // Insert folder details into the database
                $stmt = $conn->prepare("INSERT INTO folders(filename, filepath, size, datecreated, description) VALUES (?, ?, ?, NOW(), ?)");
                $size = 0; // Size is 0 for folders
                $stmt->bind_param("ssis", $folderName, $folderPath, $size, $description);

                if ($stmt->execute()) {
                    $_SESSION['alert'] = "Folder created and database entry added successfully.";
                } else {
                    $_SESSION['alert'] = "Error adding folder to database: " . $stmt->error;
                }
                $stmt->close();
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
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Directory Listing</title>
    <link href="assets/img/favicon.png" rel="icon">
    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        /* Your existing styles */
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
    </style>
</head>

<body>



<main id="main" class="main">
    <div class="pagetitle">
        <h1 id="pageTitle">Directory Listing</h1>
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="home.php">Home</a></li>
            <li class="breadcrumb-item active" aria-current="page">My Folder</li>
        </ol>
    </div><!-- End Page Title -->

    <!-- Search Bar and Filter Options -->
    <div class="search-bar1 mb-5 d-flex align-items-center">
        <i class="bi bi-search text-secondary"></i>
        <input type="text" class="form-control ms-3" placeholder="Search in Drive" id="search-bar">
    </div>

    <div class="filter-buttons d-flex gap-2">
        <!-- Type Dropdown -->
        <div class="dropdown">
            <button class="btn btn-outline-secondary type-dropdown no-caret" type="button" id="dropdownType" data-bs-toggle="dropdown" aria-expanded="false">
                <span>Type</span>
            </button>
            <ul class="dropdown-menu" aria-labelledby="dropdownType">
                <li><a class="dropdown-item" href="#" data-value="Photos & Images"><i class="bi bi-image me-2" style="color: #F4B400;"></i>Photos & Images</a></li>
                <li><a class="dropdown-item" href="#" data-value="Audio"><i class="bi bi-file-music me-2" style="color: #34A853;"></i>Audio</a></li>
                <li><a class="dropdown-item" href="#" data-value="Video"><i class="bi bi-file-earmark-play me-2" style="color: #DB4437;"></i>Video</a></li>
                <li><a class="dropdown-item" href="#" data-value="Folder"><i class="bi bi-folder-fill me-2" style="color: #4285F4;"></i>Folder</a></li>
            </ul>
        </div>

        <!-- People Dropdown -->
        <div class="dropdown">
            <button class="btn btn-outline-secondary people-dropdown no-caret" type="button" id="dropdownPeople" data-bs-toggle="dropdown" aria-expanded="false">
                <span>People</span>
            </button>
            <ul class="dropdown-menu" aria-labelledby="dropdownPeople">
                <li><a class="dropdown-item" href="#" data-value="Foundation University"><i class="bi bi-building me-2" style="color: #4285F4;"></i>Foundation University</a></li>
                <li><a class="dropdown-item" href="#" data-value="Anyone with the link"><i class="bi bi-link me-2" style="color: #F4B400;"></i>Anyone with the link</a></li>
            </ul>
        </div>

        <!-- Modified Dropdown -->
        <div class="dropdown">
            <button class="btn btn-outline-secondary modified-dropdown no-caret" type="button" id="dropdownModified" data-bs-toggle="dropdown" aria-expanded="false">
                <span>Modified</span>
            </button>
            <ul class="dropdown-menu" aria-labelledby="dropdownModified">
                <li><a class="dropdown-item" href="#" data-value="Today"><i class="bi bi-clock me-2" style="color: #34A853;"></i>Today</a></li>
                <li><a class="dropdown-item" href="#" data-value="Last Week"><i class="bi bi-calendar me-2" style="color: #DB4437;"></i>Last Week</a></li>
                <li><a class="dropdown-item" href="#" data-value="Last Month"><i class="bi bi-calendar2-week me-2" style="color: #4285F4;"></i>Last Month</a></li>
                <li><a class="dropdown-item" href="#" data-value="This year (2024)"><i class="bi bi-calendar3 me-2" style="color: #F4B400;"></i>This year (2024)</a></li>
                <li><a class="dropdown-item" href="#" data-value="Last Year (2023)"><i class="bi bi-calendar-check me-2" style="color: #DB4437;"></i>Last Year (2023)</a></li>
            </ul>
        </div>

        <!-- Location Dropdown -->
        <div class="dropdown">
            <button class="btn btn-outline-secondary location-dropdown no-caret" type="button" id="dropdownLocation" data-bs-toggle="dropdown" aria-expanded="false">
                <span>Location</span>
            </button>
            <ul class="dropdown-menu" aria-labelledby="dropdownLocation">
                <li><a class="dropdown-item" href="#" data-value="Creative"><i class="bi bi-palette me-2" style="color: #4285F4;"></i>Creative</a></li>
            </ul>
        </div>
    </div>

    <!-- Button Container for Add New Folder and Upload File -->
    <div class="button-container">
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addFolderModal">Add New Folder</button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">Upload File</button>
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
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Create Folder</button>
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
                <h5 class="modal-title" id="uploadModalLabel">Upload Files</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="uploadForm" method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="fileToUpload" class="form-label">Select Files</label>
                        <input type="file" class="form-control" id="fileToUpload" name="file[]" multiple required style="display: none;">
                        <div id="fileDisplay" class="form-control" onclick="document.getElementById('fileToUpload').click()">
                            Click here or choose files to select multiple files
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" placeholder="Enter description for the files"></textarea>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="startUpload()">Upload Files</button>
                </form>
                <!-- Progress bar and Cancel button -->
                <div id="progressContainer" style="display: none;">
                    <progress id="uploadProgress" value="0" max="100" style="width: 100%;"></progress>
                    <button id="cancelUploadButton" class="btn btn-danger mt-2" onclick="cancelUpload()">Cancel Upload</button>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
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
            alert("File uploaded successfully");
            document.getElementById("progressContainer").style.display = "none";
            window.location.reload(); // Refresh page to show uploaded files
        } else {
            alert("Failed to upload file");
        }
    });

    // Handle cancellation
    xhr.addEventListener("abort", () => {
        alert("Upload canceled");
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

    <!-- Toggle Button for List/Grid View -->
    <div class="d-flex justify-content-end mb-4 mt-4">
        <button id="toggle-view-btn" class="btn btn-outline-secondary">
            <i class="bi bi-grid-3x3-gap-fill"></i> Switch to Grid View
        </button>
    </div>
    <!-- New Container for File List -->
    <div class="container">
        <section class="section">
            <div class="row">
                <div class="col-lg-12">

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
                </div>



                <div class="table-responsive">
    <table class="datatable table table-hover list-view" id="fileTable">
        <thead>
            <tr>
                <th><input type="checkbox" id="selectAll"></th>
                <th>File/Folder Name</th>
                <th>Type</th>
                <th>Owner</th>
                <th>Filepath</th>
                <th>Location</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($_GET['search'])): ?>
                <!-- Show all items in the current directory if search is empty -->
                <?php
                // Get all items in the current directory
                $items = scandir($current_directory);
                $items = array_diff($items, ['.', '..']); // Remove '.' and '..' from the listing

                foreach ($items as $item) {
                    $item_path = $current_directory . '/' . $item;
                    $is_dir = is_dir($item_path); // Check if the item is a directory

                    // Create the correct URL for the file or folder
                    $web_url = str_replace($base_directory, '/creative/categorizesample', $item_path);

                    if ($is_dir) {
                        // For folders
                        echo '<tr>';
                        echo '<td><input type="checkbox" class="rowCheckbox"></td>';
                        echo '<td><a href="?dir=' . urlencode($item_path) . '">' . htmlspecialchars($item) . '</a></td>';
                        echo '<td>Folder</td>';
                        echo '<td>Unknown</td>';
                        echo '<td>' . htmlspecialchars($item_path) . '</td>';
                        echo '<td>Creative</td>';
                        echo '<td><button class="btn btn-info" disabled>View</button></td>';
                        echo '</tr>';
                    } else {
                        // For files, check the file extension
                        $file_extension = strtolower(pathinfo($item, PATHINFO_EXTENSION));

                        // Use the modal for file previews and add data attributes
                        echo '<tr>';
                        echo '<td><input type="checkbox" class="rowCheckbox"></td>';
                        echo '<td><a href="javascript:void(0);" class="file-link" data-url="' . htmlspecialchars($web_url) . '" data-type="' . $file_extension . '">' . htmlspecialchars($item) . '</a></td>';
                        echo '<td>File</td>';
                        echo '<td>Unknown</td>';
                        echo '<td>' . htmlspecialchars($item_path) . '</td>';
                        echo '<td>Creative</td>';
                        echo '<td><button class="btn btn-info" onclick="openModal(\'' . htmlspecialchars($web_url) . '\', \'' . $file_extension . '\')">Preview</button></td>';
                        echo '</tr>';
                    }
                }
                ?>
            <?php else: ?>
                <!-- Show search results if search term is present -->
                <?php if (!empty($searchResults['folders']) || !empty($searchResults['files'])): ?>
                    <?php foreach ($searchResults['folders'] as $folder): ?>
                        <tr>
                            <td><input type="checkbox" class="rowCheckbox"></td>
                            <td><a href="?dir=<?php echo urlencode($folder['filepath']); ?>"><?php echo htmlspecialchars($folder['filename']); ?></a></td>
                            <td>Folder</td>
                            <td>Unknown</td>
                            <td><?php echo htmlspecialchars($folder['filepath']); ?></td>
                            <td>Creative</td>
                            <td><button class="btn btn-info" disabled>View</button></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php foreach ($searchResults['files'] as $file): ?>
                        <tr>
                            <td><input type="checkbox" class="rowCheckbox"></td>
                            <td><?php echo htmlspecialchars($file['filename']); ?></td>
                            <td><?php echo htmlspecialchars($file['filetype']); ?></td>
                            <td>Database</td>
                            <td><?php echo htmlspecialchars($file['filepath']); ?></td>
                            <td>Creative</td>
                            <td><button class="btn btn-info" onclick="openModal('<?php echo htmlspecialchars($file['filepath']); ?>', '<?php echo htmlspecialchars($file['filetype']); ?>')">Preview</button></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7">No matching files or folders found.</td></tr>
                <?php endif; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
let debounceTimeout;

document.getElementById('search-bar').addEventListener('input', function() {
    const searchTerm = this.value.trim();

    clearTimeout(debounceTimeout); // Clear previous timer

    // Only search if the input is greater than 3 characters, or if the input is empty
    if (searchTerm.length >= 3 || searchTerm.length === 0) {
        debounceTimeout = setTimeout(() => {
            // If search bar is empty, reload without search parameter; else, run the search
            if (searchTerm.length === 0) {
                window.location.href = window.location.pathname; // Clears search and reloads page
            } else {
                window.location.href = `?search=${encodeURIComponent(searchTerm)}`;
            }
        }, 500); // 500ms delay
    }
});
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

<!-- Vendor JS Files -->
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/chart.js/chart.umd.js"></script>
<script src="assets/vendor/quill/quill.js"></script>
<script src="assets/vendor/simple-datatables/simple-datatables.js"></script>

<!-- Template Main JS File -->
<script src="assets/js/main.js"></script>

<!-- Script to toggle between List View and Grid View -->
<script>
    document.getElementById('toggle-view-btn').addEventListener('click', function() {
        const listView = document.getElementById('list-view');
        const gridView = document.getElementById('grid-view');
        const toggleButton = document.getElementById('toggle-view-btn');
        
        // Toggle between list and grid view
        if (listView.classList.contains('d-none')) {
            // Switch to List View
            listView.classList.remove('d-none');
            gridView.classList.add('d-none');
            toggleButton.innerHTML = '<i class="bi bi-grid-3x3-gap-fill"></i> Switch to Grid View';
        } else {
            // Switch to Grid View
            listView.classList.add('d-none');
            gridView.classList.remove('d-none');
            toggleButton.innerHTML = '<i class="bi bi-list"></i> Switch to List View';
        }
    });
</script>

<script>
// Declare global variables for tracking the current file index and the list of files
var currentFiles = [];
var currentIndex = 0;

// Function to open the preview overlay and display the image or video
function openModal(fileUrl, fileType, index, files) {
    // Set the global files array and the current index
    currentFiles = files;
    currentIndex = index;

    var overlay = document.getElementById("file-preview-overlay");
    var content = document.getElementById("file-preview-content");
    content.innerHTML = ""; // Clear previous content

    // Create an image or video element based on the file type
    if (fileType.match(/(jpg|jpeg|png|gif)$/i)) {
        var img = document.createElement("img");
        img.src = fileUrl;
        img.className = "preview-media";
        content.appendChild(img);
    } else if (fileType.match(/(mp4|mp3|wav|mov)$/i)) {
        var video = document.createElement("video");
        video.src = fileUrl;
        video.className = "preview-media";
        video.controls = true;
        content.appendChild(video);
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
    }

    // Load the new file based on the updated currentIndex
    var currentFile = currentFiles[currentIndex];
    openModal(currentFile.url, currentFile.type, currentFiles);
}

// Helper function to gather file URLs and types
function getFileList() {
    var fileElements = document.querySelectorAll('.file-link');
    var files = [];

    fileElements.forEach(function (element, index) {
        var fileUrl = element.getAttribute('data-url');
        var fileType = element.getAttribute('data-type');
        files.push({ url: fileUrl, type: fileType });
    });

    return files;
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



<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
<?php include 'header.php'; ?><?php include 'sidebar.php'; ?>
