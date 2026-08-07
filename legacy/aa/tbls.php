<?php
require "config.php";
// Get the current PHP file name
$currentPage = basename($_SERVER['PHP_SELF'], ".php");

// Set page title dynamically based on the file name
switch ($currentPage) {
    case 'recent':
        $pageTitle = 'Recent Files';
        break;
    case 'starred':
        $pageTitle = 'Starred Files';
        break;
    case 'trash':
        $pageTitle = 'Trash';
        break;
    case 'media':
        $pageTitle = 'Media';
        break;
    case 'home':
        $pageTitle = 'Home';
        break;
    default:
        $pageTitle = 'Files'; // Default title
        break;
}


// Initialize results array
$aiSearchResults = [];

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['searchTerm'])) {
    // Get the search term from the form
    $searchTerm = escapeshellarg($_POST['searchTerm']); // Sanitize input for shell

    // Run the Python script with the search term
    $command = "python3 yolo_detect.py " . escapeshellarg($searchTerm);
    $output = shell_exec($command);

    // Decode JSON output from yolo_detect.py
    $aiSearchResults = json_decode($output, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "Error decoding JSON from yolo_detect.py: " . json_last_error_msg();
        $aiSearchResults = [];
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <link href="assets/css/style.css" rel="stylesheet">

    <style>
        /* Grid view container */
        .grid-view {
            display: none; /* Hide grid view by default */
        }
    </style>
</head>

<body>

<main id="main" class="main">
    <div class="pagetitle">
        <h1 id="pageTitle"><?php echo $pageTitle; ?></h1>
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">Home</a></li>
            <li class="breadcrumb-item active" id="breadcrumbTitle"><?php echo $pageTitle; ?></li>
        </ol>
    </div><!-- End Page Title -->


     <!-- Search Form -->
     <form method="POST" action="">
        <div class="input-group mb-3">
            <input type="text" name="searchTerm" class="form-control" placeholder="Search for objects (e.g., 'car', 'person')" required>
            <div class="input-group-append">
                <button class="btn btn-primary" type="submit">Search</button>
            </div>
        </div>
    </form>


<!-- Buttons to switch between list and grid views -->
<div class="button-group">
    <div class="d-flex justify-content-end">
        <!-- List Button -->
        <button class="btn mx-2 p-2" onclick="toggleView('list')" id="list-view-btn">
            <i class="fas fa-bars"></i> <!-- Font Awesome List Icon -->
        </button>
        <!-- Grid Button -->
        <button class="btn p-2" onclick="toggleView('grid')" id="grid-view-btn">
            <i class="fas fa-th"></i> <!-- Font Awesome Grid Icon -->
        </button>
    </div>
</div>




    <section class="section">
        <div class="row">
            <div class="col-lg-12">
                <!-- List View (already in your code) -->
                <div class="table-responsive list-view" id="list-view">
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
                        // Set the base directory path
                        $base_directory = '/Volumes/creative/';
                        $current_directory = isset($_GET['dir']) ? urldecode($_GET['dir']) : $base_directory;

                        // Ensure the current directory is valid
                        if (!is_dir($current_directory) || strpos(realpath($current_directory), realpath($base_directory)) !== 0) {
                            $current_directory = $base_directory; // Default to base directory if invalid
                        }

                        // If not in the base directory, display a back button
                        if ($current_directory !== $base_directory) {
                            $parent_directory = dirname($current_directory);
                            echo '<tr><td colspan="7"><a href="?dir=' . urlencode($parent_directory) . '">← Back to ' . htmlspecialchars(basename($parent_directory)) . '</a></td></tr>';
                        }

                        // Get all items in the current directory
                        $items = scandir($current_directory);
                        $items = array_diff($items, ['.', '..']); // Remove '.' and '..' from the listing

                        foreach ($items as $item) {
                            $item_path = $current_directory . '/' . $item;
                            $is_dir = is_dir($item_path); // Check if the item is a directory
                        
                            // Create the correct URL for the file or folder
                            $web_url = str_replace($base_directory, '/creative/', $item_path);
                        
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

                <!-- Grid View -->
                <div class="row grid-view" id="grid-view">
    <?php
    foreach ($items as $item) {
        $item_path = $current_directory . '/' . $item;
        $is_dir = is_dir($item_path);
        
        // Create the correct URL for the file or folder
        $web_url = str_replace($base_directory, '/creative/', $item_path);

        // Start the grid item
        echo '<div class="col-md-3 mb-4 grid-item">';
        echo '<a href="' . ($is_dir ? '?dir=' . urlencode($item_path) : 'javascript:void(0);" class="file-link" onclick="openModal(\'' . htmlspecialchars($web_url) . '\', \'' . strtolower(pathinfo($item, PATHINFO_EXTENSION)) . '\')') . '">';

        // Check if it's a directory or a file
        if ($is_dir) {
            // Grid item for folders
            echo '<div class="grid-thumbnail">';
            echo '<i class="fas fa-folder fa-3x"></i>';
            echo '</div>';
        } else {
            // Grid item for files
            $file_extension = strtolower(pathinfo($item, PATHINFO_EXTENSION));

            if (in_array($file_extension, ['jpg', 'jpeg', 'png'])) {
                // Display thumbnail for image files
                echo '<div class="grid-thumbnail">';
                echo '<img src="' . htmlspecialchars($web_url) . '" alt="' . htmlspecialchars($item) . '">';
                echo '</div>';
            } elseif (in_array($file_extension, ['mov', 'mp4', 'avi'])) {
                // Display thumbnail for video files
                echo '<div class="grid-thumbnail">';
                echo '<i class="fas fa-video fa-3x"></i>'; // Video icon
                echo '</div>';
            } else {
                // Display a generic file icon for non-image/video files
                echo '<div class="grid-thumbnail">';
                echo '<i class="fas fa-file-alt fa-3x"></i>';
                echo '</div>';
            }
        }

        // Display the file/folder name
        echo '<span>' . htmlspecialchars($item) . '</span>';
        echo '</a>'; // Close the anchor tag
        echo '</div>'; // Close the grid item
    }
    ?>
</div>


            </div>
        </div>
    </section>
</main><!-- End #main -->

<!-- File Preview Section -->
<div id="file-preview-overlay">
    <button id="close-preview-btn" onclick="closePreview()">&#10005;</button>
    <button id="prev-btn" class="navigation-btn" onclick="navigateFile('prev')">&#8249;</button>
  <button id="next-btn" class="navigation-btn" onclick="navigateFile('next')">&#8250;</button>
    <div id="file-preview-content"></div>
</div>

<script>
// Declare global variables for tracking the current file index and the list of files
var currentFiles = [];
var currentIndex = 0;

// Function to toggle between list and grid view
function toggleView(viewType) {
    const listView = document.getElementById('list-view');
    const gridView = document.getElementById('grid-view');

    if (viewType === 'list') {
        listView.style.display = 'block';
        gridView.style.display = 'none';
    } else {
        listView.style.display = 'none';
        gridView.style.display = 'flex';
    }
}

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
    openModal(currentFile.url, currentFile.type, currentIndex, currentFiles);
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
});
</script>


<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>