<?php
require 'head.php';
require "config.php";
require 'login-check.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);





// Allow the script to run indefinitely
set_time_limit(0);



// Function to dynamically convert file paths to accessible URLs
function convertFilePathToURL($filePath) {
    // Check if the file path is already a valid URL
    if (filter_var($filePath, FILTER_VALIDATE_URL)) {
        return $filePath;
    }

    // Define base paths dynamically with your fixed IP
    $basePaths = [
        '/Applications/XAMPP/xamppfiles/htdocs/testcreative' => 'http://172.16.152.47/testcreative',
        '/var/www/html/testcreative' => 'http://172.16.152.47/testcreative'
    ];

    foreach ($basePaths as $localPath => $baseURL) {
        if (strpos($filePath, $localPath) === 0) {
            $relativePath = substr($filePath, strlen($localPath));
            $relativePath = ltrim($relativePath, '/'); // Remove leading slash
            return $baseURL . '/' . str_replace(' ', '%20', $relativePath);
        }
    }

    return str_replace(' ', '%20', $filePath);
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

    .preview-container {
    position: relative;
    max-width: 90%;
    max-height: 90%;
}

#preview-image {
    width: 100%;
    height: auto;
    display: block;
}

#bounding-boxes {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none; /* Prevent interaction */
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

/* 📌 Container for Table Selection */
.table-selector {
    display: flex;
    justify-content: left;
    gap: 10px;
    margin-bottom: 15px;
}

/* 📌 Table Selection Buttons */
.table-button {
    padding: 10px 15px;
    border: none;
    background-color: #9A1B2F;
    color: white;
    font-weight: bold;
    border-radius: 5px;
    cursor: pointer;
    transition: background 0.3s ease;
}

.table-button:hover {
    background-color: #7a1525;
}

.table-button.active {
    background-color: #66101D;
}


   /* 🔹 Auto-Suggest Styles */
   .suggestion-box {
        position: absolute;
        top: 100%;
        left: 0;
        width: 100%;
        background: white;
        border: 1px solid #ddd;
        max-height: 200px;
        overflow-y: auto;
        display: none;
        z-index: 1000;
    }

    .suggestion-box div {
        padding: 8px;
        cursor: pointer;
        border-bottom: 1px solid #eee;
    }

    .suggestion-box div:hover {
        background-color: #f0f0f0;
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
            <!-- ✅ AI Search Form with Auto-Suggest -->
            <form method="POST" action="">
                <div class="input-group mb-3 position-relative">
                    <input type="text" id="search-bar" name="searchTerm" class="form-control" placeholder="Search..." 
                           value="<?php echo htmlspecialchars($searchTerm ?? ''); ?>" autocomplete="off">
                    <button class="btn btn-primary" type="submit">Search</button>
                    
                    <!-- ✅ Auto-Suggestions Dropdown -->
                    <div id="suggestions" class="suggestion-box"></div>
                </div>
            </form>




        <div class="table-selector">
            <button id="showDetectedObjects" class="table-button">
                <i class="fas fa-search"></i> Detected Objects
            </button>
            <button id="showEmotions" class="table-button">
                <i class="fas fa-smile"></i> Emotions
            </button>
            <button id="showDuplicates" class="table-button">
                <i class="fas fa-clone"></i> Duplicates
            </button>

              <button id="showGeneralEvents" class="table-button">
                <i class="fas fa-events"></i> General Events
            </button>
        </div>




<!-- Sync Files Button -->
<button id="syncFiles" class="btn btn-primary" style="display: none;">Sync Files</button>

<span id="progress">Progress: 0%</span>
<div class="progress mt-2 mb-3">
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
       
    </div>
</div>

<!-- Bulk Action Buttons -->
<div class="action-button-container d-flex gap-2 mb-3 d-none">
    <!-- Bulk Delete -->
    <button type="button" class="btn btn-danger d-flex align-items-center" id="deleteSelectedBtn">
        <i class="fas fa-trash-alt me-2"></i> Delete Selected
    </button>

    <!-- Move to Trash -->
    <button type="button" class="btn btn-warning d-flex align-items-center" id="moveToTrashBtn">
        <i class="fas fa-trash me-2"></i> Move to Trash
    </button>

    <!-- Bulk Download (NEW) -->
    <button type="button" class="btn btn-primary d-flex align-items-center" id="downloadSelectedBtn">
        <i class="fas fa-download me-2"></i> Download Selected
    </button>
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
    $fileURL = convertFilePathToURL($file['filepath']); // Convert local path to HTTP URL
    $fileType = htmlspecialchars($file['filetype']);

    if (preg_match('/(jpg|jpeg|png|gif)$/i', $fileType)) {
        // ✅ Image Preview
        echo "<img src='" . htmlspecialchars($fileURL) . "' 
                     alt='Thumbnail' 
                     class='thumbnail' 
                     style='width: 60px; height: 60px; object-fit: cover; cursor: pointer;'
                     onclick=\"openPreview('" . htmlspecialchars($fileURL) . "', '$fileType')\">";
    } elseif (preg_match('/(mp4|mov|avi)$/i', $fileType)) {
        // ✅ Video with Play Button
        echo "<div class='video-thumbnail' style='position: relative; width: 60px; height: 60px; cursor: pointer;'
                onclick=\"openPreview('$fileURL', '$fileType')\">
                <video src='" . htmlspecialchars($fileURL) . "' 
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
        // ✅ No Preview Available
        echo "<span>No Preview</span>";
    }
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
    <div class="grid-item" data-type="<?php echo htmlspecialchars($file['filetype']); ?>">
        <?php
        $fileURL = convertFilePathToURL($file['filepath']); // Ensure conversion
        $fileType = htmlspecialchars($file['filetype']);

        if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $file['filepath'])) {
            echo "<img src='" . htmlspecialchars($fileURL) . "' 
                     alt='Thumbnail' 
                     class='thumbnail'
                     style='cursor: pointer; width: 100%; height: auto; object-fit: cover;'
                     onclick=\"openPreview('" . htmlspecialchars($fileURL) . "', '$fileType')\">";
        } elseif (preg_match('/\.(mp4|mov|avi)$/i', $file['filepath'])) {
            echo "<div class='video-thumbnail' style='position: relative; cursor: pointer;' onclick=\"openPreview('$fileURL', '$fileType')\">
                    <video src='" . htmlspecialchars($fileURL) . "' muted style='width: 100%; object-fit: cover;'></video>
                    <div class='play-button' style='position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 40px; height: 40px; background: rgba(0, 0, 0, 0.5); border-radius: 50%; display: flex; justify-content: center; align-items: center;'>
                        <i class='fas fa-play' style='color: white; font-size: 20px;'></i>
                    </div>
                  </div>";
        } else {
            echo "<span>No Preview</span>";
        }
        ?>
        <div class="file-info">
            <div><?php echo htmlspecialchars($file['filename']); ?></div>
            <div><?php echo htmlspecialchars($file['filetype']); ?></div>
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



 
</script>
<!-- SCRIPT FOR BULK DELETE, MOVE TO TRASH, & DOWNLOAD -->
<script>
$(document).ready(function () {
    const actionButtonContainer = $('.action-button-container'); // Bulk action buttons container
    const deleteSelectedBtn = $('#deleteSelectedBtn'); // Bulk Delete
    const moveToTrashBtn = $('#moveToTrashBtn'); // Bulk Move to Trash
    const downloadSelectedBtn = $('#downloadSelectedBtn'); // Bulk Download
    const selectAllCheckbox = $('#select-all'); // Select All Checkbox
    let selectedFiles = [];
    let actionType = '';

    // ✅ Function to toggle bulk action buttons
    function toggleBulkActionButtons() {
        const checkedCount = $('.row-checkbox:checked').length;
        if (checkedCount > 0) {
            actionButtonContainer.removeClass('d-none').fadeIn();
        } else {
            actionButtonContainer.fadeOut(function () {
                $(this).addClass('d-none');
            });
        }
    }

    // ✅ Attach event listener to all checkboxes (including dynamically added ones)
    $(document).on('change', '.row-checkbox', function () {
        toggleBulkActionButtons();
    });

    // ✅ Select All Checkbox Functionality
    selectAllCheckbox.on('change', function () {
        $('.row-checkbox').prop('checked', this.checked);
        toggleBulkActionButtons();
    });

    // ✅ Open Confirmation Modal Before Performing an Action
    function openConfirmationModal(action) {
        selectedFiles = getSelectedFiles();
        actionType = action;

        if (selectedFiles.length === 0) {
            showErrorModal("No files selected.");
            return;
        }

        let modalTitle = "";
        let modalMessage = "";

        if (action === "delete") {
            modalTitle = "Confirm Permanent Deletion";
            modalMessage = `Are you sure you want to permanently delete these files? <br><br>
                            <strong>${selectedFiles.map(f => f.filename).join("<br>")}</strong>`;
        } else if (action === "move_to_trash") {
            modalTitle = "Confirm Move to Trash";
            modalMessage = `Are you sure you want to move these files to trash? <br><br>
                            <strong>${selectedFiles.map(f => f.filename).join("<br>")}</strong>`;
        } else if (action === "download") {
            modalTitle = "Confirm Download";
            modalMessage = `You are about to download these files: <br><br>
                            <strong>${selectedFiles.map(f => f.filename).join("<br>")}</strong>`;
        }

        $("#confirmationModalLabel").html(modalTitle);
        $("#confirmationModalBody").html(modalMessage);
        $("#confirmationModal").modal("show");
    }

    // ✅ Execute Action After Confirmation
    $("#confirmActionBtn").on("click", async function () {
        $("#confirmationModal").modal("hide");
        await performBulkAction(actionType);
    });

    // ✅ Bulk Delete Functionality
    deleteSelectedBtn.on('click', function () {
        openConfirmationModal("delete");
    });

    // ✅ Bulk Move to Trash
    moveToTrashBtn.on('click', function () {
        openConfirmationModal("move_to_trash");
    });

    // ✅ Bulk Download
    downloadSelectedBtn.on('click', function () {
        openConfirmationModal("download");
    });

    // ✅ Perform Bulk Action via AJAX
    async function performBulkAction(action) {
        try {
            const response = await fetch('bulkActionsHandler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: action, selectedFiles })
            });

            const result = await response.json();

            if (result.status === 'success') {
                showSuccessModal(`${result.successCount} file(s) processed successfully.`);

                if (action === "download") {
                    window.location.href = result.downloadUrl;
                } else {
                    // ✅ Remove files from UI instead of reloading
                    selectedFiles.forEach(file => {
                        $(`tr[data-path="${file.filepath}"]`).remove();
                    });

                    // ✅ Hide bulk action buttons if no more selected items
                    toggleBulkActionButtons();
                }
            } else {
                showErrorModal(`Error: ${result.message}`);
            }
        } catch (error) {
            console.error(`Error during ${action}:`, error);
            showErrorModal("An unexpected error occurred.");
        }
    }

    // ✅ Function to get selected files
    function getSelectedFiles() {
        return $('.row-checkbox:checked').map(function () {
            const row = $(this).closest('tr');
            const filePath = row.attr('data-path');
            const fileNameElement = row.find('.file-folder-link');
            const fileName = fileNameElement.length ? fileNameElement.text().trim() : filePath.split('/').pop();
            return { filepath: filePath, filename: fileName };
        }).get();
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
});


</script>





<script>
  $(document).ready(function () {
    let currentFiles = []; // Store the list of files (url, type)
    let currentIndex = 0;  // Track the currently previewed file

    // ✅ Function to initialize the file preview with navigation
    function initializeFilePreview(files, startIndex) {
        if (!files.length) {
            console.error("No files available for preview.");
            return;
        }

        currentFiles = files;
        currentIndex = startIndex;

        openModal(currentFiles[currentIndex].url, currentFiles[currentIndex].type);
    }

    // ✅ Function to open the modal and preview the file
    function openModal(fileUrl, fileType) {
        const overlay = $("#file-preview-overlay");
        const content = $("#file-preview-content");
        content.html(""); // Clear previous content

        if (fileType.match(/(jpg|jpeg|png|gif)$/i)) {
            content.append(`<img src="${fileUrl}" class="preview-media" style="width: 100%; max-height: 80vh; object-fit: contain;">`);
        } else if (fileType.match(/(mp4|mov|avi)$/i)) {
            content.append(`<video src="${fileUrl}" controls class="preview-media" style="width: 100%; max-height: 80vh;"></video>`);
        } else {
            content.append(`<p>Preview not available for this file type.</p>`);
        }

        overlay.fadeIn();
    }

    // ✅ Function to navigate files
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

    // ✅ Collect files from both List View & Grid View
    function collectFiles() {
        currentFiles = []; // Reset array to prevent duplicates

        // ✅ Collect files from Grid View
        $(".grid-item .thumbnail").each(function (index) {
            const fileUrl = $(this).attr("src");
            const fileType = $(this).closest(".grid-item").attr("data-type");

            if (fileUrl && fileType) {
                currentFiles.push({ url: fileUrl, type: fileType });

                $(this).off("click").on("click", function () {
                    initializeFilePreview(currentFiles, index);
                });
            }
        });

        // ✅ Collect files from List View (Table View)
        $("#fileTable .thumbnail").each(function (index) {
            const fileUrl = $(this).attr("src");
            const fileType = $(this).closest("tr").find("td:nth-child(4)").text().trim(); // Get file type from table

            if (fileUrl && fileType) {
                currentFiles.push({ url: fileUrl, type: fileType });

                $(this).off("click").on("click", function () {
                    initializeFilePreview(currentFiles, index);
                });
            }
        });

        console.log("Files collected:", currentFiles); // Debugging log
    }

    // ✅ Event Listeners
    $("#prev-btn").on("click", function () {
        navigateFile("prev");
    });

    $("#next-btn").on("click", function () {
        navigateFile("next");
    });

    $("#close-preview-btn").on("click", function () {
        $("#file-preview-overlay").fadeOut();
    });

    // ✅ Ensure files are collected when switching views
    $("#list-view-btn, #grid-view-btn").click(function () {
        collectFiles();
    });

    // ✅ Collect files initially
    collectFiles();
});

    </script>


    </section>
</main>

<div id="file-preview-overlay" style="display: none;">
    <div id="file-preview-content"></div>
    <span id="file-preview-close">×</span>
</div>






<script>
$(document).ready(function () {
    checkFilesTable(); // ✅ Ensure sync button visibility is checked on page load
    restoreProgressBarState(); // ✅ Ensure progress bar state is correct on page load

    $("#syncFiles").on("click", function () {
        startSync();
    });
});

// ✅ Function to check if files exist using check_files.php
function checkFilesTable() {
    $.ajax({
        url: "check_files.php",
        type: "GET",
        dataType: "json",
        success: function (response) {
            if (response.files_exist) {
                $("#syncFiles").hide(); // ✅ Hide Sync button if files exist
            } else {
                $("#syncFiles").show(); // ✅ Show Sync button if no files exist
            }
        },
        error: function () {
            console.error("Error checking files table.");
        }
    });
}

// ✅ Start Sync Function (Triggers pollProgress)
function startSync() {
    console.log("Sync Files button clicked");

    $("#syncFiles").hide(); // ✅ Hide Sync button permanently
    $("#progress, #progressBar, .progress").show(); // ✅ Show progress bar
    sessionStorage.setItem("progressVisible", "true"); // ✅ Store progress bar state

    $.ajax({
        url: 'sync_files.php',
        type: 'POST',
        dataType: 'json',
        success: function (response) {
            if (response.status === 'success') {
                alert("Sync started successfully!");
                pollProgress(); // ✅ Start polling progress
            } else {
                alert(response.message || "Failed to start sync.");
            }
        },
        error: function () {
            alert("Failed to sync files. Please try again.");
        }
    });
}

// ✅ Poll Scan Progress (Uses scan_progress.php)
function pollProgress() {
    let progressInterval = setInterval(function () {
        $.ajax({
            url: 'scan_progress.php',
            type: 'GET',
            dataType: 'json',
            success: function (data) {
                if (data.status === 'running') {
                    const progress = parseFloat(data.progress).toFixed(2);
                    updateProgressBar(progress);
                } else if (data.status === 'completed' || parseFloat(data.progress) >= 100) {
                    clearInterval(progressInterval);
                    updateProgressBar(100);
                    alert("Scan completed successfully!");

                    // ✅ Hide progress bar after scan completes
                    $("#progress, #progressBar, .progress").fadeOut();
                    sessionStorage.removeItem("progressVisible"); // ✅ Remove progress bar state

                    // ✅ Check if files exist and restore sync button if needed
                    checkFilesTable();
                }
            },
            error: function () {
                console.error("Error polling scan progress.");
            }
        });
    }, 2000);
}

// ✅ Function to update progress bar
function updateProgressBar(progress) {
    $("#progress").text(`Progress: ${progress}%`);
    $("#progressBar").css("width", `${progress}%`).attr("aria-valuenow", progress);
}

// ✅ Restore Progress Bar State After Refresh
function restoreProgressBarState() {
    let progressVisible = sessionStorage.getItem("progressVisible");

    if (progressVisible === "true") {
        $("#progress, #progressBar, .progress").show(); // ✅ Keep progress bar visible
        pollProgress(); // ✅ Resume polling if scan is ongoing
    } else {
        $("#progress, #progressBar, .progress").hide(); // ✅ Keep progress bar hidden if no scan
    }
}



// ✅ Function to Fetch Data AFTER Sync Completes
function fetchScanResults() {
    $.ajax({
        url: 'fetch_scan_results.php', // ✅ Fetch data after sync
        type: 'GET',
        dataType: 'json',
        success: function (response) {
            if (response.status === 'success') {
                appendToListView(response.files); // ✅ Append to Main Table
                appendToGridView(response.files); // ✅ Append to Grid View
            } else {
                console.error("Error fetching scan results:", response.message);
            }
        },
        error: function () {
            console.error("Error fetching scan results.");
        }
    });
}




// ✅ Append Data to Main List-Table
function appendToListView(files) {
    const tbody = $("#fileTable tbody");

    files.forEach(file => {
        const filePath = file.filepath;
        const row = `
            <tr data-type="${file.filetype}" data-path="${filePath}">
                <td><input type="checkbox" class="row-checkbox" value="${filePath}"></td>
                <td><img src="${file.fileurl}" style="max-width: 50px; cursor: pointer;" 
                         onclick="openPreview('${file.fileurl}', '${file.filetype}')"></td>
                <td>${file.filename}</td>
                <td>${file.filetype}</td>
                <td class="shortened-path">${filePath}</td>
                <td>${file.dateupload || 'N/A'}</td>
                <td>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-danger dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-cogs"></i> Actions
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="javascript:void(0);" onclick="renameMedia('${filePath}', '${file.filename}')">
                                <i class="fas fa-i-cursor"></i> Rename</a></li>
                            <li><a class="dropdown-item" href="javascript:void(0);" onclick="copyMedia('${filePath}')">
                                <i class="fas fa-copy"></i> Copy</a></li>
                            <li><a class="dropdown-item" href="javascript:void(0);" onclick="downloadMedia('${filePath}')">
                                <i class="fas fa-download"></i> Download</a></li>
                            <li><a class="dropdown-item text-danger" href="javascript:void(0);" onclick="moveToTrash('${filePath}', '${file.filename}')">
                                <i class="fas fa-trash"></i> Move to Trash</a></li>
                        </ul>
                    </div>
                </td>
            </tr>`;

        tbody.append(row);
    });

    // ✅ Refresh DataTable if needed
    if ($.fn.DataTable.isDataTable("#fileTable")) {
        $("#fileTable").DataTable().destroy();
    }
    $("#fileTable").DataTable();
}

// ✅ Append Data to Grid View
function appendToGridView(files) {
    const gridContainer = $("#grid-view");

    files.forEach(file => {
        const item = `
            <div class="grid-item" data-path="${file.filepath}">
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
</script>



<script>
document.addEventListener("DOMContentLoaded", function () {
    const searchBar = document.getElementById("search-bar");
    const suggestionsList = document.getElementById("suggestions");

    // ✅ Fetch and Display Suggestions
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
                        triggerSearch(); // ✅ Auto-search on selection
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

    // ✅ Auto-Fetch Suggestions on Input
    searchBar.addEventListener("input", function () {
        fetchSuggestions(this.value);
    });

    // ✅ Hide Suggestions When Clicking Outside
    document.addEventListener("click", function (event) {
        if (!searchBar.contains(event.target) && !suggestionsList.contains(event.target)) {
            suggestionsList.style.display = "none";
        }
    });

    // ✅ Auto-Trigger Search on Suggestion Click
    function triggerSearch() {
        document.querySelector("form").submit();
    }
});
</script>


<!-- JavaScript -->
<script>
$(document).ready(function () {
    let currentFilter = "all"; // Store independent filter state
    let showingType = ""; // Track the current dataset
    let originalHTML = $('#fileTable tbody').html(); // Store the original table HTML
    let originalColumns = $('#fileTable thead tr').html(); // Store the original table header

    let table = $('#fileTable').DataTable({
        paging: true,
        searching: true,
        responsive: true,
        lengthChange: true,
        pageLength: 10,
        order: [[4, 'desc']],
        autoWidth: false,
    });

    // ✅ Independent List/Grid View Toggle
    $('#list-view-btn').on('click', function () {
        $('#list-view').show();
        $('#grid-view').addClass('d-none');
        $(this).addClass('btn-primary').removeClass('btn-outline-secondary');
        $('#grid-view-btn').addClass('btn-outline-secondary').removeClass('btn-primary');
    });

    $('#grid-view-btn').on('click', function () {
        $('#list-view').hide();
        $('#grid-view').removeClass('d-none');
        $(this).addClass('btn-primary').removeClass('btn-outline-secondary');
        $('#list-view-btn').addClass('btn-outline-secondary').removeClass('btn-primary');
    });

    // ✅ Independent File Type Filtering
    $('input[name="filter-filetype"]').on('change', function () {
        currentFilter = $(this).val();
        applyFilter();
    });

    function applyFilter() {
        $('#fileTable tbody tr').each(function () {
            const rowType = $(this).attr("data-type");
            $(this).toggle(currentFilter === "all" || rowType === currentFilter);
        });
    }

    // ✅ Function to Append Data Without Resetting Pagination, Filters, and Views
    function toggleData(type, btnId, endpoint, responseKey) {
        const btn = $(btnId);
        const tableBody = $('#fileTable tbody');
        const tableHead = $('#fileTable thead tr');

        if (showingType !== type) {
            $.ajax({
                url: endpoint,
                type: 'GET',
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        table.clear().destroy();
                        tableBody.html('');

                        // ✅ Remove old dynamic columns before adding a new one
                        tableHead.html(originalColumns); // Reset the table header
                        let extraColumnHeader = "";
                        if (type === "Objects") {
                            extraColumnHeader = '<th class="dynamic-column">Detected Objects</th>';
                        } else if (type === "Emotions") {
                            extraColumnHeader = '<th class="dynamic-column">Emotions</th>';
                        } else if (type === "Duplicates") {
                            extraColumnHeader = '<th class="dynamic-column">Original File</th>';
                        }

                        if (extraColumnHeader) {
                            tableHead.find('th:last').before(extraColumnHeader);
                        }

                        response[responseKey].forEach((file) => {
                            const fileURL = convertFilePathToURL(file.filepath);
                            const fileType = file.filetype;
                            const truncatedPath = file.filepath.length > 50 ? file.filepath.substring(0, 50) + "..." : file.filepath;

                            let thumbnailHTML = fileType.match(/(jpg|jpeg|png|gif)$/i)
                                ? `<img src="${fileURL}" class="thumbnail" 
                                    style="width: 60px; height: 60px; object-fit: cover; cursor: pointer;"
                                    onclick="openPreview('${fileURL}', '${fileType}')">`
                                : fileType.match(/(mp4|mov|avi)$/i)
                                ? `<video src="${fileURL}" class="thumbnail" muted 
                                    style="width: 60px; height: 60px; object-fit: cover;"
                                    onclick="openPreview('${fileURL}', '${fileType}')"></video>`
                                : `<span>No Preview</span>`;

                            let extraColumnData = type === "Objects"
                                ? (Array.isArray(file.detected_objects) ? file.detected_objects.join(", ") : file.detected_objects || 'N/A')
                                : type === "Emotions"
                                ? file.emotion || 'N/A'
                                : type === "Duplicates"
                                ? file.original_filename || 'N/A'
                                : "";

                            let actionsColumn = type === "Duplicates"
                                ? `<button class="btn btn-danger btn-sm" onclick="deleteMedia('${file.filepath}', '${file.filename}')">
                                    <i class="fas fa-trash-alt"></i> Delete
                                   </button>`
                                : `<div class="dropdown">
                                        <button class="btn btn-sm btn-danger dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                            <i class="fas fa-cogs"></i> Actions
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="javascript:void(0);" onclick="renameMedia('${file.filepath}', '${file.filename}')">
                                                <i class="fas fa-i-cursor"></i> Rename</a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0);" onclick="copyMedia('${file.filepath}')">
                                                <i class="fas fa-copy"></i> Copy</a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0);" onclick="downloadMedia('${file.filepath}')">
                                                <i class="fas fa-download"></i> Download</a></li>
                                            <li><a class="dropdown-item text-danger" href="javascript:void(0);" onclick="moveToTrash('${file.filepath}', '${file.filename}')">
                                                <i class="fas fa-trash"></i> Move to Trash</a></li>
                                        </ul>
                                   </div>`;

                            tableBody.append(`
                                <tr data-type="${file.filetype}" data-path="${file.filepath}">
                                    <td><input type="checkbox" class="row-checkbox" value="${file.filepath}"></td>
                                    <td>${thumbnailHTML}</td>
                                    <td>${file.filename}</td>
                                    <td>${file.filetype}</td>
                                    <td class="shortened-path">${truncatedPath}</td>
                                    <td>${file.datecreated || 'N/A'}</td>
                                    <td>${extraColumnData}</td>
                                    <td>${actionsColumn}</td>
                                </tr>
                            `);
                        });

                        table = $('#fileTable').DataTable({
                            paging: true,
                            searching: true,
                            responsive: true,
                            lengthChange: true,
                            pageLength: 10,
                            order: [[4, 'desc']],
                            autoWidth: false,
                        });

                        applyFilter();

                        btn.text(`Hide ${type}`);
                        showingType = type;
                    } else {
                        alert(`No ${type.toLowerCase()} found.`);
                        btn.text(`Show ${type}`);
                        showingType = "";
                    }
                },
                error: function () {
                    console.error(`Error fetching ${type.toLowerCase()}.`);
                }
            });
        } else {
            // ✅ Fix: Restore the original main table dataset when hiding toggled data
            table.clear().destroy();
            $('#fileTable thead tr').html(originalColumns); // Restore original headers
            $('#fileTable tbody').html(originalHTML); // Restore original table content

            table = $('#fileTable').DataTable({
                paging: true,
                searching: true,
                responsive: true,
                lengthChange: true,
                pageLength: 10,
                order: [[4, 'desc']],
                autoWidth: false,
            });

            showingType = "";
            btn.text(`Show ${type}`);
        }
    }

    // ✅ Toggle Buttons
    $('#showDuplicates').on('click', function () {
        toggleData('Duplicates', '#showDuplicates', 'fetch_duplicates.php', 'duplicates');
    });

    $('#showDetectedObjects').on('click', function () {
        toggleData('Objects', '#showDetectedObjects', 'fetch_detected_objects.php', 'files');
    });

    $('#showEmotions').on('click', function () {
        toggleData('Emotions', '#showEmotions', 'fetch_emotion.php', 'files');
    });

    function convertFilePathToURL(filePath) {
    return filePath.replace('/Applications/XAMPP/xamppfiles/htdocs', 'http://172.16.152.47');
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


///OPEN MODAL FOR GRID VIEW

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




function moveToTrash(filePath, fileName) {
    if (!confirm(`Are you sure you want to move "${fileName}" to Trash?`)) return;

    $.ajax({
        url: 'moveToTrash.php',
        type: 'POST',
        data: JSON.stringify({ filepath: decodeURIComponent(filePath), fileName: fileName }),
        contentType: 'application/json',
        dataType: 'json',
        success: function (data) {
            if (data.status === 'success') {
                alert('File moved to trash successfully!');

                // ✅ Remove the file row dynamically (No reload)
                $(`tr[data-path="${filePath}"]`).remove();
            } else {
                alert('Error: ' + data.message);
            }
        },
        error: function (xhr) {
            console.error('Error moving file to trash:', xhr.responseText);
            alert('An unexpected error occurred.');
        }
    });
}




function renameMedia(filePath, fileName) {
    const newName = prompt('Enter the new name for the file:', fileName);
    if (!newName) return;

    $.ajax({
        url: 'rename_file.php',
        type: 'POST',
        data: JSON.stringify({ filePath: decodeURIComponent(filePath), newName: newName }),
        contentType: 'application/json',
        dataType: 'json',
        success: function (result) {
            if (result.success) {
                alert('File renamed successfully!');

                // ✅ Update the UI dynamically (No reload)
                let row = $(`tr[data-path="${filePath}"]`);
                row.find("td:nth-child(3)").text(newName); // Update file name column
            } else {
                alert('Error renaming file: ' + result.error);
            }
        },
        error: function (xhr) {
            console.error('Error:', xhr.responseText);
            alert('An error occurred while renaming the file.');
        }
    });
}

function copyMedia(filePath) {
    $.ajax({
        url: 'copy_file.php',
        type: 'POST',
        data: JSON.stringify({ filePath: decodeURIComponent(filePath) }),
        contentType: 'application/json',
        dataType: 'json',
        success: function (response) {
            if (response.success) {
                alert('File copied successfully!');
                // ✅ No need to reload, follows your logic exactly
            } else {
                alert('Error copying file: ' + response.message);
            }
        },
        error: function (xhr) {
            console.error('Error copying file:', xhr.responseText);
            alert('An error occurred while copying the file.');
        }
    });
}








function downloadMedia(filePath) {
    const downloadUrl = `download_file.php?file=${encodeURIComponent(filePath)}`;
    window.location.href = downloadUrl; // Directly initiate the download
}




// Delete Media
async function deleteMedia(filePath, fileName, isTrash = false) {
    let message = isTrash
        ? `Are you sure you want to permanently delete "${fileName}" from TRASH?`
        : `Are you sure you want to delete "${fileName}"?`;

    if (!confirm(message)) return;

    try {
        const response = await fetch('delete_file.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, // ✅ Fix content type
            body: new URLSearchParams({ filepath: filePath }) // ✅ Ensure correct form data format
        });

        const textResponse = await response.text(); // ✅ Read response as text first

        // ✅ Handle non-JSON response correctly
        if (textResponse.trim() === "success") {
            alert("File deleted successfully!");
            
            // ✅ Remove file from UI dynamically
            const row = document.querySelector(`tr[data-path="${filePath}"]`);
            if (row) row.remove();
            
        } else {
            alert("Error deleting file: " + textResponse);
        }

    } catch (error) {
        console.error("Error:", error);
        alert("An error occurred while deleting the file.");
    }
}



</script>
<script src="assets/js/main.js"></script>

</body>
</html>
