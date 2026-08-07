<?php
require 'head.php';
require 'login-check.php';
require "config.php";

// Set the base directory path
$base_directory = '/Volumes/creative/categorizesample/FU-Events';
$current_directory = isset($_GET['dir']) ? urldecode($_GET['dir']) : $base_directory;


// Function to convert file path to URL
function convertFilePathToURL($filePath) {
    $baseDirectory = '/Volumes';
    $baseURL = 'http://172.16.152.45:8000';

    // Replace the base directory with the base URL
    $relative_path = str_replace($baseDirectory, $baseURL, $filePath);

    // Encode special characters in the URL
    return str_replace(' ', '%20', $relative_path); // Ensure proper URL encoding
}

// Function to fetch recent file paths
function getRecentFilePaths($conn) {
    // Define allowed file extensions
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'mp3', 'wav', 'mov'];

    // Create a string of allowed extensions for SQL IN clause
    $allowedExtensionsSQL = "'" . implode("', '", $allowedExtensions) . "'";

    // Update the query to filter based on file extensions and order by timestamp
    $query = "SELECT item_name, item_type, filepath, timestamp 
              FROM recent 
              WHERE item_type = 'file' AND LOWER(SUBSTRING_INDEX(filepath, '.', -1)) IN ($allowedExtensionsSQL)
              ORDER BY timestamp DESC
              LIMIT 10";

    $result = $conn->query($query);
    if (!$result) {
        die("Query Failed: " . $conn->error);
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}

// Fetch recent files
$recentFiles = getRecentFilePaths($conn);

// Close the database connection

?>

<!DOCTYPE html>
<html lang="en">  
      <title>Recent</title>


<body>

<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>
    <style>
        body {
            font-family: Arial, sans-serif;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
        }
        th {
            background-color: #f2f2f2;
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
            z-index: 1000;
        }
        .preview-media {
            max-width: 90vw;
            max-height: 90vh;
            object-fit: contain;
        }
        .hidden { 
            display: none; /* Hide elements with this class */
        }
        /* General table styling for a clean, borderless look */
.table {
    border-collapse: collapse; /* Removes spacing between cells */
    width: 100%; /* Full-width table */
}

.table th,
.table td {
    border: none; /* Remove all borders */
    padding: 8px 12px; /* Add consistent padding */
    text-align: left; /* Align text to the left */
}

.table thead th {
    font-weight: bold;
    text-transform: uppercase;
    font-size: 12px;
    color: #6c757d; /* Subtle gray for headers */
}

.table tbody tr:hover {
    background-color: #f8f9fa; /* Add hover effect for rows */
}

/* Styling for the ellipsis button */
.action-icon-btn {
    background: none; /* No background */
    border: none; /* No border */
    padding: 0; /* Remove padding */
    margin: 0; /* Remove margin */
    cursor: pointer; /* Pointer cursor for interactivity */
    display: flex;
    align-items: center;
    justify-content: center;
}

.action-icon-btn svg {
    color: #5f6368; /* Subtle gray for the icon */
    transition: color 0.2s ease;
}

.action-icon-btn:hover svg {
    color: #202124; /* Slightly darker gray on hover */
}

.action-icon-btn:focus {
    outline: none; /* Remove focus outline */
}



    </style>
</head>
<body>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>Recent Files</h1>
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="home.php">Home</a></li>
            <li class="breadcrumb-item active">My Folder</li>
        </ol>
    </div>

        <div class="container">
            <h3 class="hidden">Current Directory: <?php echo htmlspecialchars($current_directory); ?></h3> <!-- Hidden element -->
            <h3 class="hidden">Recent Files</h3> <!-- Hidden element -->
            <table class="table">
                <thead>
                    <tr>
                        <th>File/Folder Name</th>
                        <th>Type</th>
                        <th>Filepath</th>
                        <th>Timestamp</th>
                        
                    </tr>
                </thead>
                <tbody id="recent-files-body">
    <?php if (empty($recentFiles)): ?>
        <tr>
            <td colspan="5">No recent files or folders found.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($recentFiles as $item): ?>
            <tr>
                <td>
                    <a href="javascript:void(0);" 
                       class="file-folder-link" 
                       data-type="<?php echo htmlspecialchars($item['item_type']); ?>" 
                       data-path="<?php echo htmlspecialchars($item['filepath']); ?>"
                       ondblclick="handleFileFolderClick('<?php echo htmlspecialchars(convertFilePathToURL($item['filepath'])); ?>', event)">
                        <?php echo htmlspecialchars($item['item_name']); ?>
                    </a>
                </td>
                <td><?php echo htmlspecialchars($item['item_type']); ?></td>
                <td>
                    <a href="<?php echo htmlspecialchars(convertFilePathToURL($item['filepath'])); ?>" target="_blank">
                        <?php echo htmlspecialchars(convertFilePathToURL($item['filepath'])); ?>
                    </a>
                </td>
                <td><?php echo htmlspecialchars($item['timestamp']); ?></td>
                <td>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-danger dropdown-toggle" type="button" id="dropdownActions-<?php echo htmlspecialchars($item['item_name']); ?>" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-cogs"></i> Actions
                        </button>
                        <ul class="dropdown-menu" aria-labelledby="dropdownActions-<?php echo htmlspecialchars($item['item_name']); ?>">
                            <li>
                                <a class="dropdown-item" href="javascript:void(0);" onclick="renameMedia('<?php echo htmlspecialchars($item['filepath']); ?>', '<?php echo htmlspecialchars($item['item_name']); ?>')">
                                    <i class="fas fa-i-cursor"></i> Rename
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="javascript:void(0);" onclick="copyMedia('<?php echo htmlspecialchars($item['filepath']); ?>')">
                                    <i class="fas fa-copy"></i> Copy
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?php echo htmlspecialchars(convertFilePathToURL($item['filepath'])); ?>" download>
                                    <i class="fas fa-download"></i> Download
                                </a>
                            </li>
                        </ul>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
</tbody>

            </table>
        </div>
    </main>


<!-- File Preview Section -->
<div id="file-preview-overlay">
    <button id="close-preview-btn" onclick="closePreview()">&#10005;</button>
    <div id="file-preview-content"></div>
</div>

<script>
    // Function to open the preview overlay and display the image or video
    function openModal(fileUrl) {
        var overlay = document.getElementById("file-preview-overlay");
        var content = document.getElementById("file-preview-content");
        content.innerHTML = ""; // Clear previous content

        var img = document.createElement("img");
        img.src = fileUrl;
        img.className = "preview-media";

        img.onload = function() {
            content.appendChild(img); // Only add the image if it loads successfully
            overlay.style.display = "flex"; // Show the overlay
        };

        img.onerror = function() {
            overlay.style.display = "none"; // Hide the overlay if image fails to load
        };

        // If the file is a video, create a video element
        var ext = fileUrl.split('.').pop().toLowerCase(); // Get file extension
        if (ext.match(/(mp4|mp3|wav|mov)$/i)) {
            var video = document.createElement("video");
            video.src = fileUrl;
            video.className = "preview-media";
            video.controls = true;
            content.appendChild(video);
            overlay.style.display = "flex"; // Show the overlay
        }
    }

    // Function to close the preview overlay
    function closePreview() {
        var overlay = document.getElementById("file-preview-overlay");
        overlay.style.display = "none"; // Hide the overlay
    }

    // Handle double-click for file/folder links
    document.querySelectorAll('.file-folder-link').forEach(function(link) {
        link.addEventListener('dblclick', function() {
            var type = this.getAttribute('data-type');
            var path = this.getAttribute('data-path');
            
            if (type === 'folder') {
                window.location.href = '?dir=' + encodeURIComponent(path); // Navigate to the folder
            } else if (type === 'file') {
                openModal(path); // Open preview for the file
            }
        });
    });

    function handleFileFolderClick(filepath, event) {
    event.preventDefault(); // Prevent default link behavior
    event.stopPropagation(); // Stop the event from bubbling up
    openModal(filepath);
}

function handleAction(action, filepath) {
    const data = new FormData();
    data.append('action', action);
    data.append('filepath', filepath);

    fetch('ellipses-recent.php', {
        method: 'POST',
        body: data,
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }
        return response.json();
    })
    .then(result => {
        if (result.status === 'success') {
            alert(result.message);
        } else {
            alert('Error: ' + result.message);
        }
    })
    .catch(error => {
        console.error('Error:', error); // Log detailed error
        alert('An error occurred.');
    });
}


function renameMedia(filePath, fileName) {
    const newName = prompt("Enter the new name for the file:", fileName);
    if (newName) {
        fetch('rename_file.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ filePath: filePath, newName: newName })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert("File renamed successfully!");
                location.reload();
            } else {
                alert("Error renaming file: " + data.error);
            }
        })
        .catch(error => console.error("Error:", error));
    }
}

function copyMedia(filePath) {
    // Send the filePath to the backend to handle the copy
    fetch('copy_file.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ filePath: filePath })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert("File copied successfully!");
            location.reload(); // Reload the page to show the duplicated file
        } else {
            alert("Error copying file: " + data.error);
        }
    })
    .catch(error => console.error("Error:", error));
}
function downloadMedia(filePath) {
    // Create a URL for the file download
    const downloadUrl = `download_file.php?file=${encodeURIComponent(filePath)}`;

    // Redirect to the download URL
    window.location.href = downloadUrl;
}


    // AJAX Function to fetch recent files
    function fetchRecentFiles() {
        // Fetch recent files directly from the server
        fetch(window.location.href + '?fetch_recent_files=true') // Use current URL with query
            .then(response => response.json())
            .then(data => {
                const recentFilesBody = document.getElementById('recent-files-body');
                recentFilesBody.innerHTML = ''; // Clear existing content

                if (data.length === 0) {
                    recentFilesBody.innerHTML = '<tr><td colspan="5">No recent files or folders found.</td></tr>';
                } else {
                    data.forEach(item => {
                        const row = `<tr>
                            <td>
                                <a href="javascript:void(0);" class="file-folder-link" data-type="${item.item_type}" data-path="${item.filepath}">
                                    ${item.item_name}
                                </a>
                            </td>
                            <td>${item.item_type}</td>
                            <td>${item.filepath}</td>
                            <td>${item.timestamp}</td>
                            <td>
                                <button class="btn btn-info" onclick="openModal('${item.filepath}')">Preview</button>
                            </td>
                        </tr>`;
                        recentFilesBody.innerHTML += row;
                    });
                }
            })
            .catch(error => console.error('Error fetching recent files:', error));
    }

    // Polling to fetch recent files every 5 seconds
    setInterval(fetchRecentFiles, 5000);
</script>

<?php
require 'footer.php'
?>

<a href="#" class="back-to-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>



</body>

</html>
