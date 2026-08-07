<?php
session_start(); // Ensure this is the very first line with no whitespace above

require "config.php";

// Define base directory
$base_directory = '/Volumes/creative/categorizesample';
$current_directory = isset($_GET['dir']) ? urldecode($_GET['dir']) : $base_directory;

// Capture search term from query parameters
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';

// Function to search the directory recursively
function searchDirectory($directory, $searchTerm)
{
    $results = [];
    if (!is_dir($directory)) {
        return $results; // Return empty if the directory does not exist
    }

    $items = scandir($directory);
    if ($items === false) {
        return $results; // Return empty if scanning fails
    }

    foreach ($items as $item) {
        if ($item == '.' || $item == '..') continue;

        $itemPath = $directory . '/' . $item;
        if (is_dir($itemPath)) {
            $results = array_merge($results, searchDirectory($itemPath, $searchTerm));
        } else {
            if (stripos($item, $searchTerm) !== false) {
                $results[] = ['path' => $itemPath, 'type' => 'File', 'name' => $item];
            }
        }
    }
    return $results;
}

// Function to search in the database
function searchDatabase($conn, $searchTerm)
{
    $stmt = $conn->prepare("SELECT * FROM files WHERE filename LIKE ? OR description LIKE ?");
    $likeTerm = '%' . $searchTerm . '%';
    $stmt->bind_param("ss", $likeTerm, $likeTerm);
    $stmt->execute();
    $result = $stmt->get_result();
    $files = [];
    while ($row = $result->fetch_assoc()) {
        $files[] = ['path' => $row['filepath'], 'type' => $row['filetype'], 'name' => $row['filename']];
    }
    $stmt->close();
    return $files;
}

// Handle the search query
$searchResults = [];
if (!empty($searchTerm)) {
    // Search both in the directory and database if a search term is provided
    $directoryResults = searchDirectory($base_directory, $searchTerm);
    $dbResults = searchDatabase($conn, $searchTerm);
    $searchResults = array_merge($directoryResults, $dbResults);
}

// Handle file upload logic
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file'])) {
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
                $stmt = $conn->prepare("INSERT INTO files (filename, filepath, filetype, size, dateupload, description) VALUES (?, ?, ?, ?, NOW(), ?)");
                if ($stmt === false) {
                    die("Error preparing statement: " . $conn->error);
                }

                $stmt->bind_param("sssis", $fileName, $filePath, $fileType, $fileSize, $description);

                if ($stmt->execute()) {
                    $_SESSION['alert'] = "File uploaded and database entry created successfully.";
                } else {
                    $_SESSION['alert'] = "Error uploading file to database: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $_SESSION['alert'] = "Error moving uploaded file.";
            }
        } else {
            $_SESSION['alert'] = "Error uploading file. Error code: " . $fileError;
        }
    }

    // Redirect after processing upload to prevent form resubmission on refresh
    header("Location: ?dir=" . urlencode($current_directory));
    exit();
}

// Redirect to search results or display
if (!empty($searchResults)) {
    foreach ($searchResults as $result) {
        echo "File Name: " . $result['name'] . " - Path: " . $result['path'] . " - Type: " . $result['type'] . "<br>";
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

      <!-- Search Bar -->
    <form method="GET" action="">
        <input class="datatable-input" placeholder="Search..." type="search" name="search" title="Search within table" aria-controls="fileTable" value="<?php echo htmlspecialchars($searchTerm); ?>">
        <button type="submit">Search</button>
    </form>

   
    

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
                <div class="modal-header">
                    <h5 class="modal-title" id="addFolderModalLabel">New Folder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addFolderForm" method="POST" action="add_folder.php">
                        <div class="mb-3">
                            <label for="folderName" class="form-label">Folder Name</label>
                            <input type="text" class="form-control" id="folderName" name="folderName" required>
                        </div>
                        <input type="hidden" name="currentDir" id="currentDir" value="<?php echo htmlspecialchars($current_directory); ?>">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" form="addFolderForm" class="btn btn-primary">Create Folder</button>
                </div>
            </div>
        </div>
    </div>

   <!-- Modal for Uploading File -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uploadModalLabel">Upload Files</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="uploadForm" action="" method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="fileToUpload" class="form-label">Select Files</label>
                        <!-- Hidden file input field -->
                        <input type="file" class="form-control" id="fileToUpload" name="file[]" multiple required style="display: none;">
                        
                        <!-- Styled div to display selected files -->
                        <div id="fileDisplay" class="form-control" onclick="document.getElementById('fileToUpload').click()">
                            Click here or choose files to select multiple files
                        </div>
                        <small class="form-text text-muted">
                            Hold down the <strong>Shift</strong> or <strong>Ctrl (Cmd on Mac)</strong> key to select multiple files.
                        </small>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" placeholder="Enter description for the files"></textarea>
                    </div>
                    <input type="hidden" name="currentDir" value="<?php echo htmlspecialchars($current_directory); ?>">
                    <button type="submit" class="btn btn-primary">Upload Files</button>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" form="uploadForm" class="btn btn-primary">Upload Files</button>
            </div>
        </div>
    </div>
</div>

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
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </div>

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
