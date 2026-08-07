<?php
require 'head.php';
require "config.php";
require 'login-check.php';





// Allow the script to run indefinitely
set_time_limit(0);



// Function to convert file path to URL
    // Function to convert file path to URL
// Function to convert file path to URL
function convertFilePathToURL($filePath) {
    // Check if the file path is already a URL
    if (filter_var($filePath, FILTER_VALIDATE_URL)) {
        return $filePath; // Return as-is if it's already a valid URL
    }

    // Encode spaces and special characters
    $encodedPath = str_replace(' ', '%20', $filePath);

    // Return the encoded file path
    return $encodedPath;
}

    


// PDO Connection
try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Page title
$pageTitle = 'iFound AI Search';

$album_title = "My Advanced Photo Album";
$photos = [];

// Handle bulk upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['images'])) {
    $upload_dir = 'images/';
    foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
        $filename = basename($_FILES['images']['name'][$key]);
        $target_file = $upload_dir . $filename;
        if (move_uploaded_file($tmp_name, $target_file)) {
            $photos[] = ["filename" => $filename, "caption" => pathinfo($filename, PATHINFO_FILENAME)];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $album_title; ?></title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; }
        .gallery { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; }
        .gallery-item { position: relative; }
        .gallery img { width: 200px; height: 150px; object-fit: cover; border-radius: 10px; }
        .caption { position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%); background: rgba(0, 0, 0, 0.6); color: white; padding: 5px; border-radius: 5px; font-size: 14px; }
    </style>
</head>
<body>
    <h1><?php echo $album_title; ?></h1>
    
    <form action="" method="post" enctype="multipart/form-data">
        <input type="file" name="images[]" multiple required accept="image/*">
        <p>Upload up to 50 images at a time.</p>
        <button type="submit">Upload Photos</button>
    </form>
    
    <div class="gallery">
        <?php foreach ($photos as $photo): ?>
            <div class="gallery-item">
                <img src="images/<?php echo $photo['filename']; ?>" alt="<?php echo $photo['caption']; ?>">
                <div class="caption"> <?php echo $photo['caption']; ?> </div>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>


<?php
// Fetch AI search results
$aiSearchResults = [];
$searchTerm = $_POST['searchTerm'] ?? null;

try {
    if ($searchTerm) {
        $searchTerms = explode(' ', trim($searchTerm)); // Split search terms by spaces
        $placeholders = [];
        $queryParams = [];

        foreach ($searchTerms as $index => $term) {
            $placeholder = ":searchTerm$index";

            // Convert human-readable months to numeric format
            $termLower = strtolower($term);
            $monthMap = [
                'january' => '01', 'february' => '02', 'march' => '03', 'april' => '04', 'may' => '05', 'june' => '06',
                'july' => '07', 'august' => '08', 'september' => '09', 'october' => '10', 'november' => '11', 'december' => '12'
            ];
            if (isset($monthMap[$termLower])) {
                $term = $monthMap[$termLower]; // Replace month names with numbers
            }

            // Allow partial matches on all fields, including new ones
            $placeholders[] = "
                filename LIKE $placeholder 
                OR filepath LIKE $placeholder
                OR filetype LIKE $placeholder 
                OR size LIKE $placeholder
                OR dateupload LIKE $placeholder
                OR detected_objects LIKE $placeholder
                OR classification LIKE $placeholder
                OR pose LIKE $placeholder
                OR gesture LIKE $placeholder
                OR face_gesture LIKE $placeholder
                OR emotion LIKE $placeholder
                OR content_hash LIKE $placeholder
                OR duplicate_group LIKE $placeholder
                OR duplicate_warning LIKE $placeholder
                OR datecreated LIKE $placeholder
            ";
            $queryParams[$placeholder] = "%$term%";
        }

        // Combine search placeholders into the query
        $query = "SELECT * FROM files WHERE " . implode(' OR ', $placeholders) . " ORDER BY dateupload DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($queryParams);
    } else {
        // Default query if no search term is provided
        $stmt = $pdo->query("SELECT * FROM files ORDER BY dateupload DESC");
    }

    $aiSearchResults = $stmt->fetchAll(PDO::FETCH_ASSOC); // Fetch results
} catch (PDOException $e) {
    die("Error fetching AI search results: " . $e->getMessage());
}


// Fetch distinct file types from the database
$fileTypes = [];
try {
    $fileTypeQuery = $pdo->query("
        SELECT DISTINCT filetype 
        FROM files 
        WHERE filetype IN ('jpg', 'jpeg', 'png', 'gif', 'mp4', 'mov', 'avi', 'mkv', 'mp3', 'wav', 'flac')
    ");
    $fileTypes = $fileTypeQuery->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    die("Error fetching file types: " . $e->getMessage());
}


$duplicates = [];
try {
    $duplicatesQuery = $pdo->query("
        SELECT 
            f1.id AS file_id, 
            f1.filename, 
            f1.filepath, 
            f1.filetype, 
            f1.dateupload,
            f1.filehash,
            f1.content_hash,
            COUNT(f2.id) AS duplicate_count
        FROM files AS f1
        INNER JOIN files AS f2 
            ON (f1.filehash = f2.filehash OR f1.content_hash = f2.content_hash) 
            AND f1.id != f2.id
        GROUP BY 
            f1.id, 
            f1.filename, 
            f1.filepath, 
            f1.filetype, 
            f1.dateupload, 
            f1.filehash, 
            f1.content_hash
        HAVING duplicate_count > 0
        ORDER BY f1.dateupload DESC
    ");
    
    $duplicates = $duplicatesQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching duplicates: " . $e->getMessage());
    die("Error fetching duplicates: Please try again later.");
}

$duplicateCount = count($duplicates);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    

    <!-- Include Bootstrap, DataTables, and FontAwesome -->
    <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js"></script>
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
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">
                <!-- AI Search Form -->
                <form method="POST" action="">
                    <div class="input-group mb-3">
                        <input type="text" name="searchTerm" class="form-control" placeholder="Search..." value="<?php echo htmlspecialchars($searchTerm ?? ''); ?>">
                        <button class="btn btn-primary" type="submit">Search</button>
                    </div>
                </form>


                <div>
    <button id="startScan" class="btn btn-success">Start Scan</button>
    <button id="stopScan" class="btn btn-danger" disabled>Stop Scan</button>
    <span id="progress">Progress: 0%</span>
    <div class="progress mt-2 mb-3"> <!-- Added mb-3 for margin below -->
    <div id="progressBar" class="progress-bar" role="progressbar" 
         style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
</div>




<div class="d-flex justify-content-between align-items-center mb-3">
    <!-- Filters (left side) -->
    <div id="filter-container" class="d-flex align-items-center">
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="filter-filetype" id="filterAll" value="all" checked>
            <label class="form-check-label" for="filterAll">All</label>
        </div>
        <?php foreach ($fileTypes as $type): ?>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="filter-filetype" id="filter-<?php echo htmlspecialchars($type); ?>" value="<?php echo htmlspecialchars($type); ?>">
            <label class="form-check-label" for="filter-<?php echo htmlspecialchars($type); ?>">
                <?php echo strtoupper(htmlspecialchars($type)); ?>
            </label>
        </div>
        <?php endforeach; ?>
    </div>
            

    <!-- Buttons (right side) -->
    <div class="d-flex align-items-center">
        <div class="btn-group me-3">
            <button id="list-view-btn" class="btn btn-outline-primary"><i class="fas fa-list"></i></button>
            <button id="grid-view-btn" class="btn btn-outline-secondary"><i class="fas fa-th-large"></i></button>
        </div>
        <button id="toggle-duplicates" class="btn btn-secondary">
        Show Duplicates (<?php echo $duplicateCount; ?>)
        </button>
    </div>
</div>


<!-- Action Buttons -->
<div class="action-button-container" style="display: none;">
    <!-- Delete Selected -->
    <button type="button" class="btn btn-danger" id="deleteSelectedBtn">Delete Selected</button>
    
    <!-- Move to Trash -->
    <button type="button" class="btn btn-warning" id="moveToTrashBtn">Move to Trash</button>
</div>

<div id="list-view" class="table-responsive">
    <table id="fileTable" class="table table-hover table-striped">
        <thead>
            <tr>
                <th><input type="checkbox" id="select-all" title="Select All"></th>
                <th>Thumbnail</th>
                <th>File Name</th>
                <th>Type</th>
                <th>Path</th>
                <th>Date Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($aiSearchResults as $file): ?>
            <tr data-type="<?php echo htmlspecialchars($file['filetype']); ?>" data-path="<?php echo htmlspecialchars($file['filepath']); ?>">
                <td>
                    <input type="checkbox" class="row-checkbox" value="<?php echo htmlspecialchars($file['filepath']); ?>">
                </td>
                <td class="thumbnail">
    <?php
    // Generate thumbnail preview
    $thumbnail = '';
    if (preg_match('/(jpg|jpeg|png|gif)$/i', $file['filetype'])) {
        $thumbnail = "<img src='" . htmlspecialchars(convertFilePathToURL($file['filepath'])) . "' alt='Thumbnail' class='thumbnail' style='width: 60px; height: 60px; object-fit: cover;' onclick=\"openPreview('" . htmlspecialchars(convertFilePathToURL($file['filepath'])) . "', '" . htmlspecialchars($file['filetype']) . "')\">";
    } elseif (preg_match('/(mp4|mov|avi)$/i', $file['filetype'])) {
        $thumbnail = "<video src='" . htmlspecialchars(convertFilePathToURL($file['filepath'])) . "' class='thumbnail' style='width: 60px; height: 60px; object-fit: cover;' muted onclick=\"openPreview('" . htmlspecialchars(convertFilePathToURL($file['filepath'])) . "', '" . htmlspecialchars($file['filetype']) . "')\"></video>";
    } else {
        $thumbnail = "<span>No Preview</span>";
    }
    echo $thumbnail;
    ?>
</td>

                <td><?php echo htmlspecialchars($file['filename']); ?></td>
                <td><?php echo htmlspecialchars($file['filetype']); ?></td>
                <td class="shortened-path"><?php echo htmlspecialchars($file['filepath']); ?></td>
                <td>
                    <?php echo htmlspecialchars($file['datecreated'] ?? 'N/A'); ?>
                </td>
                <td>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-danger dropdown-toggle" type="button" id="dropdownActions-<?php echo htmlspecialchars($file['filename']); ?>" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-cogs"></i> Actions
                        </button>
                        <ul class="dropdown-menu" aria-labelledby="dropdownActions-<?php echo htmlspecialchars($file['filename']); ?>">
                            <li>
                                <a class="dropdown-item" href="javascript:void(0);" onclick="publishMedia('<?php echo addslashes($file['filepath']); ?>')">
                                    <i class="fas fa-cloud-upload-alt"></i> Publish
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="javascript:void(0);" onclick="renameMedia('<?php echo addslashes($file['filepath']); ?>', '<?php echo addslashes($file['filename']); ?>')">
                                    <i class="fas fa-i-cursor"></i> Rename
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="javascript:void(0);" onclick="copyMedia('<?php echo addslashes($file['filepath']); ?>')">
                                    <i class="fas fa-copy"></i> Copy
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="javascript:void(0);" onclick="downloadMedia('<?php echo addslashes($file['filepath']); ?>')">
                                    <i class="fas fa-download"></i> Download
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item text-danger" href="javascript:void(0);" onclick="moveToTrash('<?php echo addslashes($file['filepath']); ?>', '<?php echo addslashes($file['filename']); ?>')">
                                    <i class="fas fa-trash"></i> Move to Trash
                                </a>
                            </li>
                        </ul>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>


<div id="grid-view" class="grid-view d-none">
    <?php foreach ($aiSearchResults as $file): ?>
        <?php 
            $fileURL = htmlspecialchars(convertFilePathToURL($file['filepath']));
            $fileType = strtolower($file['filetype']);
        ?>
        <div class="grid-item" data-type="<?php echo $fileType; ?>" style="position: relative;">
            <?php if (preg_match('/(jpg|jpeg|png|gif)$/i', $fileType)): ?>
                <!-- Image Thumbnail -->
                <img src="<?php echo $fileURL; ?>" alt="Thumbnail" 
                     class="thumbnail" style="width: 100%; max-height: 150px; object-fit: cover; border-radius: 5px; cursor: pointer;"
                     onclick="openPreview('<?php echo $fileURL; ?>', '<?php echo $fileType; ?>')">
                     
            <?php elseif (preg_match('/(mp4|mov|avi)$/i', $fileType)): ?>
                <!-- Video Thumbnail with Play Button Overlay -->
                <div class="video-container" style="position: relative;">
                    <video class="thumbnail" style="width: 100%; max-height: 150px; object-fit: cover; border-radius: 5px; cursor: pointer;" 
                           muted preload="metadata"
                           onclick="openPreview('<?php echo $fileURL; ?>', '<?php echo $fileType; ?>')">
                        <source src="<?php echo $fileURL; ?>" type="video/<?php echo $fileType; ?>">
                    </video>
                    
                    <!-- Play Button -->
                    <div class="play-button" style="
                        position: absolute;
                        top: 50%;
                        left: 50%;
                        transform: translate(-50%, -50%);
                        width: 40px;
                        height: 40px;
                        background: rgba(0, 0, 0, 0.6);
                        border-radius: 50%;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        cursor: pointer;
                    " onclick="openPreview('<?php echo $fileURL; ?>', '<?php echo $fileType; ?>')">
                        <i class="fas fa-play" style="color: white; font-size: 20px;"></i>
                    </div>
                </div>

            <?php else: ?>
                <!-- Fallback Thumbnail for Unsupported Files -->
                <img src="fallback-thumbnail.png" alt="No Preview" class="thumbnail" 
                     style="width: 100%; max-height: 150px; object-fit: cover; border-radius: 5px; opacity: 0.6;">
            <?php endif; ?>

            <div class="file-info">
                <div class="filename"><?php echo htmlspecialchars($file['filename']); ?></div>
                <div class="filetype"><?php echo htmlspecialchars($file['filetype']); ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>


<!-- File Preview Section -->
<div id="file-preview-overlay" style="display: none;">
    <button id="close-preview-btn" class="navigation-btn">&#10005;</button>
    <button id="prev-btn" class="navigation-btn">&#8249;</button>
    <button id="next-btn" class="navigation-btn">&#8250;</button>
    <div id="file-preview-content"></div>
</div>


 <!-- SCRIPT FOR BULK DELETE-->
 <script>
document.addEventListener("DOMContentLoaded", function() {
    // Get references to the elements
    const actionButtonContainer = document.querySelector('.action-button-container');
    const rowCheckboxes = document.querySelectorAll('.row-checkbox');
    const deleteSelectedBtn = document.querySelector('#deleteSelectedBtn');
    const selectAllCheckbox = document.querySelector('#select-all');

    // Function to toggle the visibility of the action button
    function toggleActionButton() {
        const isAnyCheckboxSelected = Array.from(rowCheckboxes).some(checkbox => checkbox.checked);
        if (isAnyCheckboxSelected) {
            actionButtonContainer.style.display = 'block';
            deleteSelectedBtn.textContent = 'Delete Selected'; // Set button text to "Delete Selected"
        } else {
            actionButtonContainer.style.display = 'none';
        }
    }

    // Add event listeners to all checkboxes
    rowCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', toggleActionButton);
    });

    // Handle "Select All" checkbox
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            rowCheckboxes.forEach(checkbox => checkbox.checked = selectAllCheckbox.checked);
            toggleActionButton();
        });
    }

    // Run once to ensure the button's initial state is correct
    toggleActionButton();

    // Bulk deletion logic with alert and confirmation
    deleteSelectedBtn.addEventListener('click', async function() {
        const selectedCheckboxes = Array.from(rowCheckboxes).filter(checkbox => checkbox.checked);

        if (selectedCheckboxes.length === 0) {
            alert('No files or folders selected for deletion.');
            return;
        }

        // Collect the names of all selected files/folders
        const selectedItems = selectedCheckboxes.map(checkbox => {
            const row = checkbox.closest('tr');
            const fileNameElement = row.querySelector('.file-folder-link');
            const fileName = fileNameElement ? fileNameElement.textContent.trim() : 'Unknown'; // Handle missing file-folder-link gracefully
            return fileName;
        });

        // Alert the list of selected items once
        alert(`The following files/folders are selected for deletion:\n\n${selectedItems.join('\n')}`);

        // Create a confirmation message
        const confirmationMessage = `Are you sure you want to delete these files/folders?`;

        if (!confirm(confirmationMessage)) {
            return; // Exit if the user cancels
        }

        // If confirmed, send delete requests for all selected items
        const deletionPromises = selectedCheckboxes.map(checkbox => {
            const row = checkbox.closest('tr');
            const filePath = row.getAttribute('data-path'); // Get the file path
            const fileName = row.querySelector('.file-folder-link') ? row.querySelector('.file-folder-link').textContent.trim() : 'Unknown'; // Get the file or folder name

            // Make an async request to delete the file/folder
            return deleteMedia(filePath, fileName);
        });

        try {
            // Wait for all deletions to complete
            const results = await Promise.all(deletionPromises);

            // Filter success and failure messages
            const successCount = results.filter(result => result.status === 'success').length;
            const errorCount = results.length - successCount;

            // Alert success message once
            alert(`Deletion complete. ${successCount} item(s) deleted successfully.${errorCount > 0 ? ` ${errorCount} item(s) failed to delete.` : ''}`);

            // Optionally reload the page or update the UI
            location.reload();
        } catch (error) {
            console.error('Error during deletion:', error);
            alert('An unexpected error occurred during deletion.');
        }
    });

    // Function to handle the deletion request
    async function deleteMedia(filePath, fileName) {
        try {
            const response = await fetch('deleteMedia.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ filepath: filePath, fileName: fileName }),
            });

            return await response.json(); // Parse the JSON response
        } catch (error) {
            console.error(`Error deleting file ${fileName}:`, error);
            return { status: 'error', message: 'Network error during deletion.' };
        }
    }
});

</script>

<script>
   $(document).ready(function () {
    let currentFiles = []; // Array to store the list of files (url, type)
    let currentIndex = 0;  // Index to track the currently previewed file

    // Function to close the preview modal
    function closePreview() {
        $('#file-preview-overlay').hide();
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

    // Function to open the modal and preview the file
    function openModal(fileUrl, fileType) {
        const $content = $('#file-preview-content');
        $content.empty(); // Clear previous content

        // Handle different file types
        if (fileType.match(/(jpg|jpeg|png|gif)$/i)) {
            const img = $('<img>').attr('src', fileUrl).addClass('preview-media').css({
                width: '100%',
                maxHeight: '80vh',
                objectFit: 'contain'
            });
            $content.append(img);
        } else if (fileType.match(/(mp4|avi|mov|mkv)$/i)) {
            const video = $('<video>').attr({
                src: fileUrl,
                controls: true
            }).addClass('preview-media').css({
                width: '100%',
                maxHeight: '80vh'
            });
            $content.append(video);
        } else {
            const message = $('<p>').text("Preview not available for this file type.");
            $content.append(message);
        }

        $('#file-preview-overlay').css('display', 'flex'); // Show the overlay
    }

    // Function to initialize and load files for navigation
    function initializeFilePreview(files, startIndex = 0) {
        if (!Array.isArray(files) || files.length === 0) {
            console.error("No files available for preview");
            return;
        }

        currentFiles = files; // Set the global files array
        currentIndex = startIndex; // Set the starting index
        const startFile = currentFiles[currentIndex];

        if (startFile) {
            openModal(startFile.url, startFile.type); // Open the first file in the list
        }
    }

    // Event listeners for the next and previous buttons
    $('#prev-btn').on('click', function () {
        navigateFile('prev');
    });

    $('#next-btn').on('click', function () {
        navigateFile('next');
    });

    // Event listener for the close button
    $('#close-preview-btn').on('click', function () {
        closePreview();
    });

    // Export the initializeFilePreview function for external use
    window.initializeFilePreview = initializeFilePreview;

    // Example usage: Dynamically load files when a preview is initiated
    $('.thumbnail img').on('click', function () {
        const allThumbnails = $('.thumbnail img'); // Select all thumbnail images
        const files = [];

        allThumbnails.each(function (index) {
            const fileUrl = $(this).attr('src');
            const fileType = fileUrl.split('.').pop(); // Get the file extension
            files.push({ url: fileUrl, type: fileType });

            // Check if the current thumbnail is the clicked one
            if (this === event.target) {
                currentIndex = index;
            }
        });

        initializeFilePreview(files, currentIndex); // Initialize preview
    });
});


    </script>


    </section>
</main>

<div id="file-preview-overlay" style="display: none;">
    <div id="file-preview-content"></div>
    <span id="file-preview-close" onclick="closePreview()">×</span>
</div>






<script>
let progressInterval;  // Interval for polling scan progress
let fetchInterval;     // Interval for fetching scan results

// Start Scan Function
function startScan() {
    console.log("Start Scan button clicked"); // Debugging log
    $("#startScan").hide();      // Hide Start Scan button
    $("#stopScan").prop("disabled", false).show(); // Enable and show Stop Scan button

    $.ajax({
        url: 'start_scan.php',
        type: 'POST',
        success: function (response) {
            if (response.status === 'success') {
                alert("Scan started successfully.");
                pollProgress();       // Start polling scan progress
                fetchLiveResults();   // Start fetching scan results
            } else {
                alert(response.message || "Failed to start scan.");
                resetButtons();
            }
        },
        error: function () {
            alert("Failed to start scan. Please try again.");
            resetButtons();
        }
    });
}


// Stop Scan Function
function stopScan() {
    $("#stopScan").prop("disabled", true); // Temporarily disable Stop button

    $.ajax({
        url: 'stop_scan.php',
        type: 'POST',
        dataType: 'json',
        success: function (response) {
            if (response.status === 'success') {
                alert("Scan stopped successfully.");
                clearInterval(progressInterval); // Stop progress polling
                clearInterval(fetchInterval);    // Stop fetching results
                
                resetProgressBar();              // Reset the progress bar
                resetButtons();                  // Reset buttons (enable Start, disable Stop)
            } else {
                alert(response.message);
                $("#stopScan").prop("disabled", false); // Re-enable Stop button if stop fails
            }
        },
        error: function () {
            alert("Failed to stop scan. Please try again.");
            $("#stopScan").prop("disabled", false); // Re-enable Stop button on error
        }
    });
}

// Poll Scan Progress
function pollProgress() {
    progressInterval = setInterval(function () {
        $.ajax({
            url: 'scan_progress.php',
            type: 'GET',
            dataType: 'json',
            success: function (data) {
                if (data.status === 'running') {
                    const progress = parseFloat(data.progress).toFixed(2);
                    updateProgressBar(progress);

                    if (progress >= 100) {
                        clearInterval(progressInterval);
                        clearInterval(fetchInterval);
                        updateProgressBar(100);
                        alert("Scan completed successfully!");
                        resetButtons();
                    }
                } else if (data.status === 'completed') {
                    clearInterval(progressInterval);
                    clearInterval(fetchInterval);
                    updateProgressBar(100);
                    alert("Scan completed successfully!");
                    resetButtons();
                }
            },
            error: function () {
                console.error("Error polling scan progress.");
                resetButtons();
            }
        });
    }, 2000); // Poll every 2 seconds
}

function fetchLiveResults() {
    // Immediately fetch results once
    fetchResults();

    // Set interval to fetch results dynamically
    fetchInterval = setInterval(fetchResults, 3000);
}

// Function to fetch results
function fetchResults() {
    $.ajax({
        url: 'fetch_scan_results.php',  // Endpoint to get latest results
        type: 'GET',
        dataType: 'json',
        success: function (response) {
            if (response.status === 'success') {
                const files = response.files;

                // Update list and grid views dynamically
                updateListView(files);
                updateGridView(files);
            } else {
                console.error("Error fetching live results:", response.message);
            }
        },
        error: function () {
            console.error("Error fetching live results.");
        }
    });
}

// Reset Buttons and UI
function resetButtons() {
    $("#stopScan").hide();
    $("#startScan").show();
}

// Update Progress Bar
function updateProgressBar(progress) {
    $("#progress").text(`Progress: ${progress}%`);
    $("#progressBar").css("width", `${progress}%`).attr("aria-valuenow", progress);
}

// Function to reset the progress bar
function resetProgressBar() {
    $("#progress").text("Progress: 0%"); // Reset text
    $("#progressBar")
        .css("width", "0%")             // Reset visual width
        .attr("aria-valuenow", 0);      // Reset accessibility value
}

// Update List View
function updateListView(files) {
    const tbody = $("#table-body");
    tbody.empty();
    files.forEach(file => {
        const row = `
            <tr>
                <td>
                    <input type="checkbox" class="row-checkbox" value="${file.fileurl}">
                </td>
                <td>
                    <img src="${file.fileurl}" alt="Thumbnail" style="max-width: 50px; cursor: pointer;"
                        onclick="openPreview('${file.fileurl}', '${file.filetype}')">
                </td>
                <td>${file.filename}</td>
                <td>${file.filetype}</td>
                <td>${file.fileurl}</td>
                <td>${file.dateupload}</td>
            </tr>`;
        tbody.append(row);
    });
}

// Update Grid View
function updateGridView(files) {
    const gridContainer = $("#grid-view");
    gridContainer.empty();
    files.forEach(file => {
        const item = `
            <div class="grid-item">
                <img src="${file.fileurl}" alt="${file.filename}" onclick="openPreview('${file.fileurl}', '${file.filetype}')"
                    style="max-width: 100%; cursor: pointer;">
                <div class="file-info">
                    <div>${file.filename}</div>
                    <div>${file.filetype}</div>
                </div>
            </div>`;
        gridContainer.append(item);
    });
}



// Event Listeners
$("#startScan").on("click", startScan);
$("#stopScan").on("click", stopScan);
</script>





<!-- JavaScript -->
<script>
    $(document).ready(function () {
         // Initialize the DataTable with responsive features
    const table = $('#fileTable').DataTable({
        paging: true,
        searching: true,
        responsive: true,
        lengthChange: true,
        pageLength: 10,
        order: [[4, 'desc']],
        autoWidth: false, // Prevent fixed column widths
    });


    // Listen for window resize and redraw the table
    $(window).on('resize', function () {
        table.columns.adjust().responsive.recalc();
    });

        const tableBody = $('#table-body');
        const listView = $('#list-view');
        const gridView = $('#grid-view');

     // Toggle List/Grid view
    $('#list-view-btn').on('click', function () {
        listView.show();
        gridView.addClass('d-none');
        $(this).addClass('btn-primary').removeClass('btn-outline-secondary');
        $('#grid-view-btn').addClass('btn-outline-secondary').removeClass('btn-primary');
    });


    $('#grid-view-btn').on('click', function () {
        listView.hide();
        gridView.removeClass('d-none');
        $(this).addClass('btn-primary').removeClass('btn-outline-secondary');
        $('#list-view-btn').addClass('btn-outline-secondary').removeClass('btn-primary');
    });
      

          // Handle the change event of the radio buttons
    $('input[name="filter-filetype"]').on('change', function () {
        const selectedType = $(this).val();

        if (selectedType === 'all') {
            // Show all rows
            $('tr, .grid-item').show();
        } else {
            // Hide all rows and only show those that match the selected type
            $('tr, .grid-item').hide();
            $(`tr[data-type="${selectedType}"], .grid-item[data-type="${selectedType}"]`).show();
        }
    });

});

$(document).ready(function () {
    // Toggle duplicates
    let showingDuplicates = false;

    $('#toggle-duplicates').on('click', function () {
        const btn = $(this);
        const tableBody = $('#fileTable tbody'); // Main table body selector

        if (!showingDuplicates) {
            // Fetch duplicates dynamically via AJAX
            $.ajax({
                url: 'fetch_duplicates.php', // Endpoint to fetch duplicates
                type: 'GET',
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        tableBody.html(''); // Clear existing rows
                        response.duplicates.forEach((file) => {
                            // Append each duplicate row with the proper format
                            tableBody.append(`
                                <tr data-type="${file.filetype}" data-path="${file.filepath}">
                                    <td>
                                        <input type="checkbox" class="row-checkbox" value="${file.filepath}">
                                    </td>
                                    <td class="thumbnail">
                                        ${generateThumbnail(file.file_url, file.filetype)}
                                    </td>
                                    <td>${file.filename}</td>
                                    <td>${file.filetype}</td>
                                    <td class="shortened-path">${file.filepath}</td>
                                    <td>${file.datecreated || 'N/A'}</td>
                                    <td>
                                        <button class="btn btn-danger btn-sm" onclick="moveToTrash('${file.filepath}', '${file.filename}')">
                                            <i class="fas fa-trash"></i> Trash
                                        </button>
                                    </td>
                                </tr>
                            `);
                        });

                        // Update button text to toggle back
                        btn.text('Hide Duplicates');
                        showingDuplicates = true;
                    } else {
                        alert('No duplicates found.');
                        btn.text(`Show Duplicates (${response.duplicate_count || 0})`);
                        showingDuplicates = false;
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Error fetching duplicates:', error);
                    alert('Failed to fetch duplicates. Please try again.');
                }
            });
        } else {
            // Reload the page to reset the table
            location.reload();
        }
    });

    // Function to generate a thumbnail for images and videos
    function generateThumbnail(fileUrl, fileType) {
        if (fileType.match(/(jpg|jpeg|png|gif)$/i)) {
            return `<img src="${fileUrl}" alt="Thumbnail" class="thumbnail" 
                         style="width: 60px; height: 60px; object-fit: cover;" 
                         onclick="openModal('${fileUrl}', '${fileType}')">`;
        } else if (fileType.match(/(mp4|mov|avi)$/i)) {
            return `<video src="${fileUrl}" class="thumbnail" 
                           style="width: 60px; height: 60px; object-fit: cover;" 
                           muted onclick="openModal('${fileUrl}', '${fileType}')"></video>`;
        } else {
            return `<span>No Preview</span>`;
        }
    }
});




    function openPreview(fileUrl, fileType) {
    const overlay = document.getElementById('file-preview-overlay');
    const content = document.getElementById('file-preview-content');
    content.innerHTML = '';

    if (fileType.match(/(jpg|jpeg|png|gif)$/i)) {
        const img = document.createElement('img');
        img.src = fileUrl;
        img.style.width = '100%';
        img.style.maxHeight = '80vh';
        img.style.objectFit = 'contain';
        content.appendChild(img);
    } else if (fileType.match(/(mp4|avi|mov|mkv)$/i)) {
        const video = document.createElement('video');
        video.src = fileUrl;
        video.controls = true;
        video.style.width = '100%';
        video.style.maxHeight = '80vh';
        content.appendChild(video);
    }

    overlay.style.display = 'flex';
}

let currentFiles = []; // Array to store the list of files (url, type)
let currentIndex = 0;  // Index to track the currently previewed file

// Function to open the modal and preview the file
function openModal(fileUrl, fileType) {
    const overlay = document.getElementById('file-preview-overlay');
    const content = document.getElementById('file-preview-content');
    content.innerHTML = ""; // Clear previous content

    // Handle different file types
    if (fileType.match(/(jpg|jpeg|png|gif)$/i)) {
        const img = document.createElement('img');
        img.src = fileUrl;
        img.alt = "Preview Image";
        img.className = "preview-media";
        content.appendChild(img);
    } else if (fileType.match(/(mp4|mov)$/i)) {
        const video = document.createElement('video');
        video.src = fileUrl;
        video.controls = true;
        video.className = "preview-media";
        content.appendChild(video);
    } else if (fileType.match(/(mp3|wav)$/i)) {
        const audio = document.createElement('audio');
        audio.src = fileUrl;
        audio.controls = true;
        audio.className = "preview-media";
        content.appendChild(audio);
    } else {
        const message = document.createElement('p');
        message.textContent = "Preview not available for this file type.";
        content.appendChild(message);
    }

    overlay.style.display = 'flex'; // Show the overlay
}

// Function to close the preview modal
function closePreview() {
    document.getElementById('file-preview-overlay').style.display = 'none';
}


// Function to move a file to trash
function moveToTrash(filePath, fileName) {
    if (!confirm(`Are you sure you want to move "${fileName}" to Trash?`)) {
        return; // User canceled the action
    }

    // Send AJAX request to move the file
    $.ajax({
        url: 'moveToTrash.php', // The PHP endpoint
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ filepath: filePath, fileName: fileName }),
        success: function (response) {
            try {
                const jsonResponse = typeof response === 'string' ? JSON.parse(response) : response;

                if (jsonResponse.status === 'success') {
                    alert('File moved to trash successfully!');
                    // Optionally remove the row from the table or refresh the page
                    $(`tr[data-filepath="${filePath}"]`).remove(); // Example to remove the table row dynamically
                } else {
                    alert('Error: ' + jsonResponse.message);
                }
            } catch (e) {
                console.error('Error parsing response:', e);
                alert('An unexpected error occurred.');
            }
        },
        error: function (xhr) {
            console.error('AJAX Error:', xhr.responseText);
            alert('An error occurred while moving the file to trash.');
        }
    });
}


// Publish Media
async function publishMedia(filePath) {
    try {
        const response = await fetch('publishMedia.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ filepath: filePath.trim() })
        });

        const result = await response.json();

        if (result.status === 'success') {
            alert('File published successfully!');
            location.reload(); // Reload to update the list
        } else {
            alert('Error publishing file: ' + result.message);
        }
    } catch (error) {
        console.error('Error publishing file:', error);
        alert('An error occurred while publishing the file.');
    }
}

// Rename Media
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
            const row = document.querySelector(`tr[data-type] input[value="${filePath}"]`).closest('tr');
            row.querySelector('td:nth-child(3)').textContent = newName; // Update file name in table
        } else {
            alert('Error renaming file: ' + result.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred while renaming the file.');
    }
}

// Copy Media
function copyMedia(filePath) {
    fetch('copy_file.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ filePath: filePath }) // Use filePath directly without decoding
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok.');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            alert('File copied successfully!');
            location.reload(); // Reload to reflect the new copied file
        } else {
            alert('Error copying file: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error copying file:', error);
        alert('An error occurred while copying the file.');
    });
}


function downloadMedia(filePath) {
    const downloadUrl = `download_file.php?file=${encodeURIComponent(filePath)}`;
    window.location.href = downloadUrl; // Directly initiate the download
}



// Delete Media
async function deleteMedia(filePath, fileName) {
    if (!confirm(`Are you sure you want to delete "${fileName}"? This action cannot be undone.`)) return;

    try {
        const response = await fetch('deleteMedia.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ filepath: filePath, fileName: fileName })
        });
        const result = await response.json();
        if (result.status === 'success') {
            alert('File deleted successfully!');
            // Dynamically remove row from table
            const row = document.querySelector(`tr[data-type] input[value="${filePath}"]`).closest('tr');
            if (row) row.remove();
        } else {
            alert('Error deleting file: ' + result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred while deleting the file.');
    }
}



// Checkbox Select All

$("#select-all").on("click", function () {
    $(".file-checkbox").prop("checked", this.checked);
});

$(document).ready(function () {
    const $bulkActions = $('#bulk-actions'); // Bulk actions container
    const $selectAll = $('#select-all'); // Select all checkbox
    const $rowCheckboxes = $('.row-checkbox'); // Individual row checkboxes
    const $bulkDelete = $('#bulk-delete'); // Bulk delete button
    const $bulkDownload = $('#bulk-download'); // Bulk download button

    // Function to toggle bulk actions visibility
    function toggleBulkActions() {
        const anyChecked = $('.row-checkbox:checked').length > 0;
        $bulkActions.toggle(anyChecked); // Show or hide bulk actions
    }

    // Handle "Select All" checkbox
    $selectAll.on('change', function () {
        const isChecked = $(this).is(':checked');
        $rowCheckboxes.prop('checked', isChecked); // Check/uncheck all
        toggleBulkActions();
    });

    // Handle individual row checkbox change
    $(document).on('change', '.row-checkbox', function () {
        const allChecked = $('.row-checkbox:checked').length === $rowCheckboxes.length;
        $selectAll.prop('checked', allChecked); // Update "Select All" checkbox
        toggleBulkActions();
    });

    // Bulk Delete Action
    $bulkDelete.on('click', function () {
        const selectedFiles = $('.row-checkbox:checked').map(function () {
            return $(this).val(); // Collect file paths
        }).get();

        if (selectedFiles.length === 0) {
            alert('No files selected.');
            return;
        }

        if (confirm(`Are you sure you want to delete ${selectedFiles.length} file(s)?`)) {
            $.ajax({
                url: 'bulkActionsHandler.php',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ action: 'delete', selectedFiles: selectedFiles }),
                success: function (response) {
                    try {
                        const jsonResponse = typeof response === 'string' ? JSON.parse(response) : response;

                        if (jsonResponse.status === 'success') {
                            alert('Files deleted successfully!');
                            // Remove rows dynamically from table
                            selectedFiles.forEach(filePath => {
                                $(`tr[data-path="${filePath}"]`).remove();
                            });
                            toggleBulkActions(); // Hide bulk actions
                        } else {
                            alert('Error: ' + jsonResponse.message);
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e);
                        alert('Unexpected error occurred.');
                    }
                },
                error: function (xhr) {
                    console.error('AJAX Error:', xhr.responseText);
                    alert('An error occurred while deleting the files.');
                }
            });
        }
    });

    // Bulk Download Action
$bulkDownload.on('click', function () {
    const selectedFiles = $('.row-checkbox:checked').map(function () {
        return $(this).val(); // Collect file paths
    }).get();

    if (selectedFiles.length === 0) {
        alert('No files selected.');
        return;
    }

    $.ajax({
        url: 'bulkActionsHandler.php',
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ action: 'download', selectedFiles: selectedFiles }),
        success: function (response) {
            try {
                const jsonResponse = typeof response === 'string' ? JSON.parse(response) : response;

                if (jsonResponse.status === 'success' && jsonResponse.zipLink) {
                    window.open(jsonResponse.zipLink, '_blank'); // Open ZIP file link
                } else {
                    alert('Error: ' + jsonResponse.message);
                }
            } catch (e) {
                console.error('Error parsing response:', e);
                alert('Unexpected error occurred.');
            }
        },
        error: function (xhr) {
            console.error('AJAX Error:', xhr.responseText);
            alert('An error occurred while generating the download file.');
        }
    });
});


    // Initially hide the bulk actions
    $bulkActions.hide();
});



</script>
<script src="assets/js/main.js"></script>

</body>
</html>
