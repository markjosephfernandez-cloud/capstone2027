<?php
    require 'head.php';
    require 'login-check.php';
    require 'config.php';


    // Handle file upload and redirect logic
    // Set the base directory path
    $base_directory = '/Volumes/creative/';
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

    // Get and filter the directory contents
    $items = scandir($current_directory);

    // Exclude unwanted files like .DS_Store
    $items = array_filter($items, function ($item) use ($current_directory) {
        return $item !== '.' && $item !== '..' && $item !== '.DS_Store' && file_exists($current_directory . '/' . $item);
    });


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

        // Prepare SQL queries to search in files and folders
        $fileQuery = $conn->prepare("SELECT * FROM files WHERE filename LIKE ? OR filepath LIKE ?");
        $fileQuery->bind_param("ss", $searchTerm, $searchTerm);
        $fileQuery->execute();
        $fileResults = $fileQuery->get_result();

        $folderQuery = $conn->prepare("SELECT * FROM folders WHERE filename LIKE ? OR filepath LIKE ?");
        $folderQuery->bind_param("ss", $searchTerm, $searchTerm);
        $folderQuery->execute();
        $folderResults = $folderQuery->get_result();

        // Find tags matching the search term
        $tagQuery = $conn->prepare("SELECT tag_id FROM tag WHERE tag LIKE ? OR type LIKE ?");
        $tagQuery->bind_param("ss", $searchTerm, $searchTerm);
        $tagQuery->execute();
        $tagResults = $tagQuery->get_result();

        // If matching tags are found, fetch associated files
        $taggedFiles = [];
        if ($tagResults->num_rows > 0) {
            while ($tagRow = $tagResults->fetch_assoc()) {
                $tagId = $tagRow['tag_id'];
                $relatedFilesQuery = $conn->prepare("SELECT * FROM files WHERE tag_id = ?");
                $relatedFilesQuery->bind_param("i", $tagId);
                $relatedFilesQuery->execute();
                $relatedFiles = $relatedFilesQuery->get_result()->fetch_all(MYSQLI_ASSOC);
                $taggedFiles = array_merge($taggedFiles, $relatedFiles);
                $relatedFilesQuery->close();
            }
        }

        // Merge file results with tagged files
        $allFiles = array_merge($fileResults->fetch_all(MYSQLI_ASSOC), $taggedFiles);

        // Combine results
        $results = [
            'files' => $allFiles, // Files now include those associated with matching tags
            'folders' => $folderResults->fetch_all(MYSQLI_ASSOC),
        ];

        // Close statements
        $fileQuery->close();
        $folderQuery->close();
        $tagQuery->close();

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
        $response = ['status' => 'success', 'message' => ''];
        $fileCount = count($_FILES['file']['name']);
        $description = isset($_POST['description']) ? $_POST['description'] : NULL;
        $tag = isset($_POST['tag']) ? trim($_POST['tag']) : NULL;

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
                    // Determine the file type
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

                        // Insert or retrieve the tag
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
                                    } else {
                                        $response['status'] = 'error';
                                        $response['message'] .= "Failed to insert the tag for $fileName.";
                                        continue;
                                    }
                                }
                                $checkTagStmt->close();
                            } else {
                                $response['status'] = 'error';
                                $response['message'] .= "Failed to check the tag for $fileName.";
                                continue;
                            }
                        }

                        // Insert file into the database
                        $stmt = $conn->prepare("INSERT INTO files (filename, filepath, filetype, size, dateupload, description, tag_id) VALUES (?, ?, ?, ?, NOW(), ?, ?)");
                        if ($stmt) {
                            $stmt->bind_param("sssisi", $fileName, $filePath, $fileType, $fileSize, $description, $tag_id);

                            if (!$stmt->execute()) {
                                $response['status'] = 'error';
                                $response['message'] .= "Failed to upload $fileName to the database.";
                            }
                            $stmt->close();
                        } else {
                            $response['status'] = 'error';
                            $response['message'] .= "Failed to prepare the statement for $fileName.";
                        }
                    } else {
                        $response['status'] = 'error';
                        $response['message'] .= "Unsupported file type for $fileName.";
                    }
                } else {
                    $response['status'] = 'error';
                    $response['message'] .= "Failed to move $fileName to the directory.";
                }
            } else {
                $response['status'] = 'error';
                $response['message'] .= "Error during upload for $fileName (Error code: $fileError).";
            }
        }

        // Return response
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
    <?php include 'head.php'; ?>
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
            .file-path-wrapper {
                max-width: 300px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .file-path {
                text-decoration: none;
                color: #007bff;
            }
            .file-path:hover {
                text-decoration: underline;
            }
            .datatable-search .datatable-input
            {
                display:none;
            }
        </style>
    </head>

    <body>
    <?php include 'header.php'; ?><?php include 'sidebar.php'; ?>


    <main id="main" class="main">
        
        </div><!-- End Page Title -->

        <!-- Search Bar and Filter Options -->
        <div class="search-bar1 mb-5 d-flex align-items-center">
            <i class="bi bi-search text-secondary"></i>
            <input type="text" class="form-control ms-3" placeholder="Search in Drive" id="search-bar">
        </div>

        <div class="filter-buttons d-flex gap-2">
        
            

        

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
                            <label for="tag" class="form-label">Tag</label>
                            <input type="text" class="form-control" id="tag" name="tag" placeholder="Enter a single tag (no commas or spaces)">
                            <small id="tagError" class="form-text text-danger" style="display: none;">Tag is required and must not contain spaces or commas.</small>
                            <div id="tagDropdown" class="dropdown-menu p-2" style="display: none; max-height: 200px; overflow-y: auto;">
                                <!-- Tags will be dynamically inserted here -->
                            </div>
                        </div>

                        <!-- Description Input -->
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3" placeholder="Enter description for the files"></textarea>
                        </div>

                        <!-- Upload Button -->
                        <button type="button" class="btn btn-primary" onclick="startUpload()">Upload Files</button>
                    </form>

                    <!-- Progress bar and Cancel button -->
                    <div id="progressContainer" style="display: none; margin-top: 20px;">
                        <progress id="uploadProgress" value="0" max="100" style="width: 100%;"></progress>
                        <div id="progressPercentage" class="text-center mt-1">0%</div>
                        <button id="cancelUploadButton" class="btn btn-danger mt-2" onclick="cancelUpload()">Cancel Upload</button>
                    </div>
                </div>
            </div>
        </div>
    </div>



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
    <table class="datatable table table-hover table-striped" id="fileTable">
        <thead>
            <tr>
                <th><input type="checkbox" id="selectAllCheckbox"></th>
                <th>File/Folder Name</th>
                <th>Type</th>
               
                <th class="file-path-column">File Path</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($_GET['search'])): ?>
                <?php
                // Ensure `.DS_Store` is excluded consistently
                $items = array_filter(scandir($current_directory), function ($item) use ($current_directory) {
                    return $item !== '.' && $item !== '..' && $item !== '.DS_Store' && file_exists($current_directory . '/' . $item);
                });

                foreach ($items as $item):
                    $item_path = $current_directory . '/' . $item;
                    $is_dir = is_dir($item_path);
                    $item_type = $is_dir ? 'folder' : 'file';
                    $web_url = convertFilePathToURL($item_path); // Convert the file path to a web URL
                ?>
                    <tr>
                        <td><input type="checkbox" class="rowCheckbox"></td>
                        <td>
                            <?php if ($is_dir): ?>
                                <!-- Folder -->
                                <a href="?dir=<?php echo urlencode($item_path); ?>" class="file-folder-link" 
                                   onclick="recordActivity('<?php echo addslashes($item); ?>', 'folder', '<?php echo htmlspecialchars($item_path); ?>')">
                                    <?php echo htmlspecialchars($item); ?>
                                </a>
                            <?php else: ?>
                                <!-- File -->
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
                        <td>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-danger dropdown-toggle" type="button" id="dropdownActions-<?php echo htmlspecialchars($item); ?>" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-cogs"></i> Actions
                                </button>
                                <ul class="dropdown-menu" aria-labelledby="dropdownActions-<?php echo htmlspecialchars($item); ?>">
                                    <li>
                                        <a class="dropdown-item" href="javascript:void(0);" onclick="renameMedia('<?php echo htmlspecialchars($item_path); ?>', '<?php echo htmlspecialchars($item); ?>')">
                                            <i class="fas fa-i-cursor"></i> Rename
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="javascript:void(0);" onclick="copyMedia('<?php echo htmlspecialchars($item_path); ?>')">
                                            <i class="fas fa-copy"></i> Copy
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="javascript:void(0);" onclick="downloadMedia('<?php echo htmlspecialchars($item_path); ?>')">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item text-danger" href="javascript:void(0);" onclick="deleteMedia('<?php echo htmlspecialchars($item_path, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($item, ENT_QUOTES, 'UTF-8'); ?>')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <?php if (!empty($searchResults['folders']) || !empty($searchResults['files'])): ?>
                    <?php foreach ($searchResults['folders'] as $folder): ?>
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
                            <td>Creative</td>
                            <td>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-danger dropdown-toggle" type="button" id="dropdownActions-<?php echo htmlspecialchars($folder['filename']); ?>" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fas fa-cogs"></i> Actions
                                    </button>
                                    <ul class="dropdown-menu" aria-labelledby="dropdownActions-<?php echo htmlspecialchars($folder['filename']); ?>">
                                        <li>
                                            <a class="dropdown-item" href="javascript:void(0);" onclick="renameMedia('<?php echo htmlspecialchars($folder['filepath']); ?>', '<?php echo htmlspecialchars($folder['filename']); ?>')">
                                                <i class="fas fa-i-cursor"></i> Rename
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="javascript:void(0);" onclick="copyMedia('<?php echo htmlspecialchars($folder['filepath']); ?>')">
                                                <i class="fas fa-copy"></i> Copy
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="javascript:void(0);" onclick="downloadMedia('<?php echo htmlspecialchars($folder['filepath']); ?>')">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item text-danger" href="javascript:void(0);" onclick="deleteMedia('<?php echo htmlspecialchars($folder['filepath'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($folder['filename'], ENT_QUOTES, 'UTF-8'); ?>')">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php foreach ($searchResults['files'] as $file): ?>
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
                            <td>Database</td>
                            <td class="file-path-column">
                                <div class="file-path-wrapper">
                                    <a href="<?php echo htmlspecialchars(convertFilePathToURL($file['filepath'])); ?>" target="_blank" class="file-path">
                                        <?php echo htmlspecialchars(convertFilePathToURL($file['filepath'])); ?>
                                    </a>
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
                                            <a class="dropdown-item" href="javascript:void(0);" onclick="copyMedia('<?php echo htmlspecialchars($file['filepath']); ?>')"><i class="fas fa-copy"></i> Copy</a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="javascript:void(0);" onclick="downloadMedia('<?php echo htmlspecialchars($file['filepath']); ?>')"><i class="fas fa-download"></i> Download</a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item text-danger" href="javascript:void(0);" onclick="deleteMedia('<?php echo htmlspecialchars($file['filepath']); ?>', '<?php echo htmlspecialchars($file['filename']); ?>')"><i class="fas fa-trash"></i> Delete</a>
                                        </li>
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





    <script>


document.getElementById('selectAllCheckbox').addEventListener('click', function() {
    // Get all checkboxes with the class 'rowCheckbox'
    const checkboxes = document.querySelectorAll('.rowCheckbox');
    
    // Set each checkbox's checked state based on the 'selectAllCheckbox' state
    checkboxes.forEach(function(checkbox) {
        checkbox.checked = document.getElementById('selectAllCheckbox').checked;
    });
});




    function publishMedia(fileName, filePath) {
        if (confirm("Are you sure you want to publish this item?")) {
            var data = {
                filename: fileName.trim(), // File name only
                filepath: filePath.trim()  // Full file path
            };

            console.log("DEBUG: Data being sent to server:", data); // Log the data being sent

            $.ajax({
                url: './actions/publish.php',
                type: 'POST',
                data: data,
                success: function(response) {
                    console.log("Response from server:", response); // Log raw response
                    try {
                        var jsonResponse = JSON.parse(response); // Parse JSON response
                        if (jsonResponse.status === 'success') {
                            alert('File has been successfully published!');
                            location.reload(); // Reload the page to reflect the changes
                        } else {
                            alert('Error: ' + jsonResponse.message);
                        }
                    } catch (e) {
                        console.error("Error parsing response:", e);
                        alert("Error: Failed to parse response. Check the console for details.");
                    }
                },
                error: function(xhr, status, error) {
                    console.error("Publishing failed:", status, error);
                    alert('Error: Could not publish the item. Check console for details.');
                }
            });
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
        if (fileType.match(/(jpg|jpeg|png|gif)$/i)) {
            var img = document.createElement("img");
            img.src = updatedFileUrl;
            img.className = "preview-media";
            content.appendChild(img);
        } else if (fileType.match(/(mp4|mp3|wav|mov)$/i)) {
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


    function deleteMedia(filePath, fileName) {
        console.log("Attempting to delete:", filePath, fileName); // Debugging log

        if (!confirm(`Are you sure you want to delete "${fileName}"? This action cannot be undone.`)) {
            return;
        }

        // Send a POST request using fetch with JSON data
        fetch('deleteMedia.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ filepath: filePath, fileName: fileName })
        })
        .then(response => response.json()) // Parse the JSON response
        .then(data => {
            if (data.status === 'success') {
                alert('File deleted successfully!');
                location.reload(); // Refresh the page to update the file listing
            } else {
                alert('Error: ' + (data.message || 'Unable to delete file.'));
            }
        })
        .catch(error => {
            console.error("Error:", error); // Debugging log
            alert('An error occurred while trying to delete the file.');
        });
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




    </body>
    <?php
    require 'footer.php';
    ?>
    </html>