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


// Global PHP function to shorten filenames
function shortenFileName($filename, $maxLength = 30) {
    if (!$filename) return '';
    if (strlen($filename) <= $maxLength) {
        return $filename;
    }
    return substr($filename, 0, $maxLength - 3) . '...';
}

// If a filename is provided via GET, return a JSON response with the shortened version.
if (isset($_GET['filename'])) {
    $filename = $_GET['filename'];
    $shortened = shortenFileName($filename, 30);
    echo json_encode(array(
        "original" => $filename,
        "shortened" => $shortened
    ));
    exit;
}

// PDO Connection
try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}





// Page title
$pageTitle = 'Detection';

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
                    OR emotion LIKE $placeholder
                    OR content_hash LIKE $placeholder
                    OR datecreated LIKE $placeholder

                ";
                $queryParams[$placeholder] = "%$term%";
            }

            // Combine search placeholders into the query
            $query = "SELECT * FROM files 
          WHERE (is_deleted IS NULL OR is_deleted = 0)
          AND (" . implode(' OR ', $placeholders) . ") 
          ORDER BY dateupload DESC";

            $stmt = $pdo->prepare($query);
            $stmt->execute($queryParams);
        } else {
            // Default query if no search term is provided
            $stmt = $pdo->query("SELECT * FROM files WHERE is_deleted IS NULL OR is_deleted = 0 ORDER BY dateupload DESC");

        }

        $aiSearchResults = $stmt->fetchAll(PDO::FETCH_ASSOC); // Fetch results
    } catch (PDOException $e) {
        die("Error fetching AI search results: " . $e->getMessage());
    }



$fileTypes = [];
try {
    $fileTypeQuery = $pdo->query("
    SELECT DISTINCT LOWER(filetype) AS filetype
      FROM files
     WHERE (is_deleted IS NULL OR is_deleted = 0)
       AND filetype IS NOT NULL 
       AND filetype != ''
       AND LOWER(filetype) IN (
            'jpg','jpeg','png','gif',
            'mp4','mov','avi','mkv',
            'mp3','wav','flac'
       )
     ORDER BY filetype ASC
");


    $fileTypes = $fileTypeQuery->fetchAll(PDO::FETCH_COLUMN);

    // Optional: Remove duplicates and empty values (double-check safety)
    $fileTypes = array_filter(array_unique($fileTypes));
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js"></script>
    <style>
        #dynamic-filter-container {
            display: block !important;  /* Force block instead of flex */
            padding: 10px 0;  /* Adds padding around the container */
            margin: 1rem 0;  /* Keeps the margin as specified */
        }

        #dynamic-filter-container label.btn {
            display: inline-block;
            margin-right: 8px;
            margin-bottom: 6px;
            font-size: 0.75rem;  /* Keeps the button text small */
            padding: 0.25rem 0.75rem;  /* Adds a bit of padding for button size */
            transition: all 0.2s ease;  /* Smooth transition for hover and active states */
        }

        #dynamic-filter-container label.btn:hover {
            filter: brightness(1.1);
            cursor: pointer;
        }

        #dynamic-filter-container .btn-check:checked + label.btn {
            border: 2px solid #333;
            transform: scale(1.05);
        }

        /* Ensure that the checkboxes have consistent behavior and size */
        #dynamic-filter-container input[type="checkbox"] {
            display: none;
        }

        #dynamic-filter-container .btn-check:checked + label {
            background-color: #333 !important;
            color: white !important;
        }





        /* Enlarge the toggle switch and label text in .random-switch-color */
        .random-switch-color .form-check-input {
            transform: scale(1.0);
            transform-origin: left center;
        }

        .random-switch-color .form-check-label {
            font-size: 1.1rem;  /* Or whatever size you want */
            margin-left: 0.5rem; /* Adds a small gap between the switch and the text */
        }






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


        /* ✅ Header for Close (Left) & Download (Right) */
        #file-preview-header {
            position: absolute;
            top: 10px;
            left: 10px;
            right: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: calc(100% - 20px);
            z-index: 1100;
        }

        /* ✅ Close Button (Left) */
        #close-preview-btn {
            font-size: 28px;
            background: none;
            color: white;
            border: none;
            cursor: pointer;
        }

        /* ✅ Download Button (Right) - Adjusted to match Close button */
        #download-file-btn {
            font-size: 14px;
            padding: 8px 14px;
            border-radius: 5px;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            border: none;
            cursor: pointer;
            position: absolute;
            right: 20px; /* ✅ Keeps it on the right */
            top: 10px;   /* ✅ Lowered to match the Close button */
            display: flex;
            align-items: center;
            gap: 5px; /* ✅ Adds space between icon and text */
        }

        /* ✅ Hover Effects */
        #download-file-btn:hover {
            background: rgba(255, 255, 255, 0.2); /* ✅ Slight transparency on hover */
            color: white;
            transition: background 0.3s ease;
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
/* 📌 General Table Button Styles */
/* 📌 General Table Button Styles */
/* 📌 General Table Button Styles */
.table-selector {
    display: flex;
    gap: 10px;
}

.table-button {
    display: inline-flex;
    align-items: center;
    padding: 12px 18px;
    border: none;
    font-weight: bold;
    color: white;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.3s ease, transform 0.1s, filter 0.3s;
    font-size: 14px;
}

/* 📌 Ensuring Icons Stay Visible */
.table-button i {
    margin-right: 8px;
    transition: color 0.3s ease;
}

/* 📌 Button Colors */
#showDetectedObjects {
    background-color: #007bff; /* Blue */
}

#showEmotions {
    background-color: #28a745; /* Green */
}

#showDuplicates {
    background-color: #dc3545; /* Red */
}

/* 📌 Hover Effects */
.table-button:hover {
    transform: scale(1.05);
    opacity: 0.9;
}

/* 📌 Ensuring Active Button Retains Its Color & Icons */
.table-button.active {
    filter: brightness(1.2);
}

/* ✅ **Fix: Ensuring Icons Don't Disappear When Toggled** */
.table-button.active i {
    color: inherit !important;
    visibility: visible !important;
}



   /* 🔹 Auto-Suggest Styles */
   .position-relative {
    position: relative;  /* Ensures that the child elements can be positioned absolutely relative to this container */
}

#suggestions {
    position: absolute;
    top: 100%;  /* Positions the top of the suggestions list right below the search bar */
    left: 0;
    width: 100%;  /* Makes the suggestions list as wide as the search bar */
    background: white;
    z-index: 1000;  /* Ensures it appears on top of other content but below the search bar */
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);  /* Optional: Adds a slight shadow for better visibility */
    max-height: 300px;  /* Limits the height of the suggestions box */
    overflow-y: auto;  /* Adds a scrollbar if the content is taller than max-height */
}

#suggestions div {
    padding: 8px 16px;  /* Adds padding inside each suggestion for better readability */
    cursor: pointer;  /* Changes the mouse cursor to indicate clickable items */
}

#suggestions div:hover {
    background-color: #f0f0f0;  /* Changes background on hover for visual feedback */
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
    <div id="suggestions" class="suggestions" style="display:none; position: absolute; width: 100%; background: white; z-index: 1000;"></div>
</div>

            </form>



            <div class="table-selector">
    <button id="showDetectedObjects" class="table-button detected-objects">
        <i class="fas fa-search"></i> Detected Objects
    </button>
    <button id="showEmotions" class="table-button emotions">
        <i class="fas fa-smile"></i> Emotions
    </button>
    <button id="showDuplicates" class="table-button duplicates">
        <i class="fas fa-clone"></i> Duplicates
    </button>
</div>


<!-- Sync Files Button -->
<button id="syncFiles" class="btn btn-primary" style="display: none;">Sync Files</button>

<span id="progress">Processing: 0%</span>
<div class="progress mt-2 mb-3">
    <div id="progressBar" class="progress-bar" role="progressbar"
         style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
</div>






<!-- Dynamic Filter Container for Objects/Emotions only (initially hidden) -->

<div id="dynamic-filter-container"
     class="d-flex flex-wrap"
     style="gap: 1rem; margin: 1rem 0;">
    <!-- The dynamically generated switches will be appended here -->
</div>






            <div class="d-flex justify-content-between align-items-center mb-3">
    <!-- Filters (left side) -->
    <div id="filter-container" class="d-flex align-items-center">
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="filter-filetype" id="filterAll" value="all" checked>
            <label class="form-check-label" for="filterAll">All</label>
        </div>

        <div id="default-filter">
        <?php foreach ($fileTypes as $type): ?>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="filter-filetype" id="filter-<?php echo htmlspecialchars($type); ?>" value="<?php echo htmlspecialchars($type); ?>">
            <label class="form-check-label" for="filter-<?php echo htmlspecialchars($type); ?>">
                <?php echo strtoupper(htmlspecialchars($type)); ?>
            </label>
        </div>
        <?php endforeach; ?>
    </div>
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
        <i class="fas fa-trash-alt me-2"></i> Delete Permanently
    </button>

    <!-- Move to Trash -->
    <button type="button" class="btn btn-warning d-flex align-items-center" id="moveToTrashBtn">
        <i class="fas fa-trash me-2"></i> Move to Trash
    </button>

    <!-- Bulk Download (NEW) -->
    <button type="button" class="btn btn-primary d-flex align-items-center" id="downloadSelectedBtn">
        <i class="fas fa-download me-2"></i> Download 
    </button>
</div>






<!-- ✅ New File Notification Modal -->
<div id="newFileModal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">📂 New Files Detected</h5>
            </div>
            <div class="modal-body">
                <p>There are <strong id="newFileCount">0</strong> new files detected.</p>
                <p>Would you like to sync now?</p>
            </div>
            <div class="modal-footer">
                <button id="syncNowBtn" class="btn btn-primary">Sync Now</button>
                <button id="laterBtn" type="button" class="btn btn-secondary close-modal-btn" data-dismiss="modal">Later</button>
            </div>
        </div>
    </div>
</div>









<!-- ✅ Scan Completion Modal -->
<div id="scanCompleteModal" class="modal fade" tabindex="-1" aria-labelledby="scanCompleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="scanCompleteModalLabel">
                    <i class="fas fa-check-circle text-success"></i> Scan Completed
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>The scan has been completed successfully.</p>
            </div>
            <div class="modal-footer">
                <button type="button" id = "startpoll" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
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




<!-- ✅ Modal Warning Users Not to Refresh -->
<div id="refreshWarningModal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">⚠️ Scanning In Progress</h5>
            </div>
            <div class="modal-body">
                <p>⚠️ Please do not refresh the page! The scanning process is still running. Wait until the process is complete.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>



<!-- ✅ Sync Started Modal -->
<div id="syncStartedModal" class="modal fade" tabindex="-1" aria-labelledby="syncStartedModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="syncStartedModalLabel">
                    <i class="fas fa-sync-alt text-primary"></i> Sync Started
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>The file synchronization process has started. Please wait...</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>










            <div id="list-view" class="table-responsive">
    <table id="fileTable" class="table table-hover table-striped">
        <thead>
            <tr>
                <th><input type="checkbox" id="select-all" title="Select All"></th>
                <th>File</th>
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


                <td title="<?php echo htmlspecialchars($file['filename']); ?>">
                    <?php echo shortenFileName(htmlspecialchars($file['filename']), 30); ?>
                </td>

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
                <i class="fas fa-copy"></i> Duplicate
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



<!-- 📌 File Preview Overlay -->
<div id="file-preview-overlay" style="display: none;">
    <!-- ✅ Header with Download Button -->
    <div id="file-preview-header">
        <button id="download-file-btn" class="btn btn-primary">
            <i class="fas fa-download"></i> Download
        </button>
        <button id="close-preview-btn" class="navigation-btn">&#10005;</button>
    </div>

    <!-- ✅ Navigation Buttons -->
    <button id="prev-btn" class="navigation-btn">&#8249;</button>
    <button id="next-btn" class="navigation-btn">&#8250;</button>

    <div id="file-preview-content"></div>
</div>





<script>


$(document).ready(function () {
    checkFileStatus(); // ✅ Run only once on page load

    function checkFileStatus() {
        $.ajax({
            url: "check_files.php",
            type: "GET",
            dataType: "json",
            success: function (response) {
                if (response.files_exist) {
                    // ✅ Show all buttons if any file exists
                    $("#showDetectedObjects").show();
                    $("#showEmotions").show();
                    $("#showDuplicates").show();
                } else {
                    // ✅ Hide all buttons if no files exist
                    $("#showDetectedObjects").hide();
                    $("#showEmotions").hide();
                    $("#showDuplicates").hide();
                }
            },
            error: function () {
                console.error("Error fetching file status.");
            }
        });
    }
});

// Global function to prevent page refresh while scanning is in progress.
// It uses your refresh warning modal (#refreshWarningModal) to notify the user.
function setupRefreshPrevention() {
    console.log("Setting up refresh prevention...");

    // Block refresh keys (F5, Ctrl+R on Windows, Cmd+R on Mac)
    $(document).on("keydown.preventRefresh", function(event) {
        if (
            event.which === 116 || // F5 key
            (event.ctrlKey && event.which === 82) || // Ctrl+R (Windows)
            (event.metaKey && event.which === 82)    // Cmd+R (Mac)
        ) {
            event.preventDefault();
            $("#refreshWarningModal").modal("show");
            return false;
        }
    });

    // Block browser refresh or close using beforeunload event
    window.addEventListener("beforeunload", beforeUnloadHandler);

    // Block right-click context menu
    $(document).on("contextmenu.preventRefresh", function(event) {
        event.preventDefault();
        $("#refreshWarningModal").modal("show");
        return false;
    });

    // Block back/forward navigation using popstate event.
    window.addEventListener("popstate", popstateHandler);

    // Push a new state so that the back button doesn't leave the page.
    history.pushState(null, null, location.href);
}

function beforeUnloadHandler(event) {
    // Prevent the default behavior and show the modal.
    event.preventDefault();
    event.returnValue = ""; // Required for Chrome.
    $("#refreshWarningModal").modal("show");
    return "";
}

function popstateHandler(event) {
    // Immediately push state back and show modal.
    history.pushState(null, null, location.href);
    $("#refreshWarningModal").modal("show");
}

// Global function to remove refresh prevention once scanning is complete.
function removeRefreshPrevention() {
    console.log("Removing refresh prevention...");
    $(document).off("keydown.preventRefresh");
    $(document).off("contextmenu.preventRefresh");
    window.removeEventListener("beforeunload", beforeUnloadHandler);
    window.removeEventListener("popstate", popstateHandler);
}




</script>



<script>
$(document).ready(function () {
    $(".table-button").on("click", function () {
        const isActive = $(this).hasClass("active");

        $(".table-button").removeClass("active"); // Remove active class from all

        if (!isActive) {
            $(this).addClass("active"); // Add active class only if not already active
        } else {
            $(this).removeClass("active"); // Allow toggling off
        }
    });
});






</script>

            <script>
                $(document).ready(function () {
                    let intervalId;
                    let scanningInProgress = false;
                    let newFilesDetected = false;

                    checkNewUploads();
                    // Polls for new uploads based on last_scanned = NULL
                    function checkNewUploads() {
                        console.log(">> checkNewUploads() called - Scanning new files...");
                        console.debug(">> scanningInProgress:", scanningInProgress);
                        console.debug(">> newFilesDetected:", newFilesDetected);

                        if (scanningInProgress) {
                            console.log(">> Skipping polling because scanning is in progress.");
                            return;
                        }
                        console.log ('CHARLIE');
                        $.ajax({
                            url: "get_new_uploads.php",
                            type: "GET",
                            dataType: "json",
                            success: function (response) {
                                console.log(">> Polling response received:", response);
                                if (response.new_files > 0) {
                                    console.log(`>> Detected ${response.new_files} new uploads.`);
                                    $("#newFileCount").text(response.new_files);
                                    $("#newFileModal").modal("show");

                                    // Optional: Refresh the table with new entries
                                    // reloadTable();

                                    // Stop polling once new files are detected
                                    if (intervalId) {
                                        clearInterval(intervalId);
                                        console.log(">> Interval cleared.");
                                    }
                                    newFilesDetected = true;
                                } else {
                                    console.log(">> No new files detected.");
                                }
                            },
                            error: function (xhr, status, error) {
                                console.error(">> AJAX error during checkNewUploads:", status, error);
                                console.error(">> xhr.responseText:", xhr.responseText);
                            }
                        });
                    }



                    // Starts polling for new uploads every 10 seconds.
                    // Start polling for new uploads every 10 seconds.
                    function startPollingUploads() {
                        if (intervalId) clearInterval(intervalId); // Avoid multiple intervals
                        intervalId = setInterval(checkNewUploads, 10000);
                        console.log(">> Polling for new uploads started...");
                    }


                    // Refresh the main table (AJAX reload or DataTables support)
                    function reloadTable() {
                        console.log("🔄 Reloading the table...");
                        // If you're using DataTables, replace the line below:
                        // $('#yourTableId').DataTable().ajax.reload(null, false);

                        // If manual reload (make sure to adjust fetch endpoint and target)
                        $.ajax({
                            url: 'fetch_files.php', // You need to create this PHP if not existing
                            method: 'GET',
                            success: function (data) {
                                $('#yourTableBody').html(data); // Replace <tbody> content
                            }
                        });
                    }

                    // Scan progress polling
                    function pollProgress() {
                        sessionStorage.setItem("scanningInProgress", "true");

                        let progressInterval = setInterval(function () {
                            $.ajax({
                                url: 'scan_progress.php',
                                type: 'GET',
                                dataType: "json",
                                success: function (data) {
                                    if (data.status === "running") {
                                        const progress = parseFloat(data.progress).toFixed(2);
                                        updateProgressBar(progress);
                                    } else if (data.status === "completed" || parseFloat(data.progress) >= 100) {
                                        clearInterval(progressInterval);
                                        updateProgressBar(100);
                                        console.log("✅ Scanning complete.");

                                        scanningInProgress = false;
                                        newFilesDetected = false;

                                        $("#scanCompleteModal").modal("show");
                                        $("#progress, #progressBar, .progress").fadeOut();
                                        sessionStorage.removeItem("progressVisible");
                                    }
                                },
                                error: function () {
                                    console.error("Error polling scan progress.");
                                }
                            });
                        }, 2000);
                    }

                    // Update the progress bar
                    function updateProgressBar(value) {
                        $("#progress").text(`Progress: ${value}%`);
                        $("#progressBar").css("width", `${value}%`).attr("aria-valuenow", value);
                    }

                    // Prevent refresh while scanning
                    $(document).on("keydown", function (event) {
                        if (sessionStorage.getItem("scanningInProgress") === "true") {
                            if (event.which === 116 || (event.ctrlKey && event.which === 82) || (event.metaKey && event.which === 82)) {
                                event.preventDefault();
                                $("#refreshWarningModal").modal("show");
                                return false;
                            }
                        }
                    });

                    window.addEventListener("beforeunload", function (event) {
                        if (sessionStorage.getItem("scanningInProgress") === "true") {
                            event.preventDefault();
                            event.returnValue = "";
                            $("#refreshWarningModal").modal("show");
                            return false;
                        }
                    });

                    $(document).on("contextmenu", function (event) {
                        if (sessionStorage.getItem("scanningInProgress") === "true") {
                            event.preventDefault();
                            $("#refreshWarningModal").modal("show");
                            return false;
                        }
                    });

                    history.pushState(null, null, location.href);
                    window.onpopstate = function () {
                        if (sessionStorage.getItem("scanningInProgress") === "true") {
                            history.pushState(null, null, location.href);
                            $("#refreshWarningModal").modal("show");
                        }
                    };

                    // Prevent accidental dismissal of Scan Complete modal
                    $("#scanCompleteModal").modal({
                        backdrop: 'static',
                        keyboard: false
                    });

                    // When the OK button is clicked in the Scan Completed modal, start polling.
                    $(document).on("click", "#startpoll", function (e) {
                        e.preventDefault();
                        console.log(">> OK button (#startpoll) clicked. Starting polling for new uploads.");
                        $("#scanCompleteModal").modal("hide");
                        scanningInProgress = false;
                        newFilesDetected = false;
                        // Persist polling state in sessionStorage so that if the page refreshes, polling continues.
                        sessionStorage.setItem("startPolling", "true");
                        startPollingUploads();
                    });


                    // On document ready, if the polling flag is set, restart polling.
                    if (sessionStorage.getItem("startPolling") === "true") {
                        console.log(">> startPolling flag detected in sessionStorage. Restarting polling...");
                        scanningInProgress = false;
                        newFilesDetected = false;
                        startPollingUploads();
                    }



                    // ✅ Attach event handler to the "Sync Now" button inside New File Modal
                    $(document).on("click", "#syncNowBtn", function () {
                        console.log(">> ✅ Sync Now button clicked.");
                        $("#newFileModal").modal("hide");  // ✅ Close the modal
                        startSync();                      // ✅ Start the Sync process
                    });

// ✅ Attach event handler to the "Later" button inside New File Modal
                    $(document).on("click", "#laterBtn", function () {
                        console.log(">> ⏳ User clicked 'Later'. Closing modal...");
                        $("#newFileModal").modal("hide");  // ✅ Simply hide the modal
                    });



                });
            </script>













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
   // ✅ Perform Bulk Action (Delete, Move to Trash, Download)
async function performBulkAction(action) {
    try {
        const response = await fetch('bulkActionsHandler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: action, selectedFiles })
        });

        const result = await response.json();

        if (result.status === 'success') {
            if (action === "download") {
                if (result.downloadUrl) {
                    window.location.href = result.downloadUrl;

                    // ✅ Fix: Show correct filenames instead of "undefined"
                    const fileNames = selectedFiles.map(f => f.filename).join("<br>");
                    showSuccessModal(`${selectedFiles.length} file(s) downloaded successfully.<br><br><strong>${fileNames}</strong>`);
                } else {
                    showErrorModal("Error: No download URL returned.");
                }
            } else {
                // ✅ Fix: Ensure correct file count in messages
                const fileNames = selectedFiles.map(f => f.filename).join("<br>");
                showSuccessModal(`${result.successCount} file(s) ${action.replace("_", " ")} successfully.<br><br><strong>${fileNames}</strong>`);

                // ✅ Remove deleted/moved files from UI dynamically
                selectedFiles.forEach(file => {
                    $(`tr[data-path="${file.filepath}"]`).remove();
                });

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




<!--BUG HERE CANT COLLECT FILES AND ORDER NEXT AND PREV
SWITCHING TO DEFAULT TABLE, OBJECTS, EMOTIONS AND DUPLICATES--->
<script>
    $(document).ready(function () {
        let currentFiles = []; // Store files
        let currentIndex = 0;  // Track current file index

        // ✅ Function to detect which dataset is active
        function getActiveDataset() {
            let dataset = "Default"; // Default dataset

            if ($("#showDetectedObjects").hasClass("active")) dataset = "Objects";
            if ($("#showEmotions").hasClass("active")) dataset = "Emotions";
            if ($("#showDuplicates").hasClass("active")) dataset = "Duplicates";

            //console.log(`🔄 [getActiveDataset] Active dataset: ${dataset}`);
            return dataset;
        }

        // ✅ Function to collect files based on the active dataset
        // Global variable to store the dataset used when files were last collected
        let currentDataset = "Default";

// --- Updated collectFiles() function ---
        function collectFiles() {
            currentFiles = [];
            currentDataset = getActiveDataset();  // Update the global dataset
           // console.log(`🔄 Collecting files for dataset: ${currentDataset}`);

            let table = $("#fileTable").DataTable();
            // Retrieve row indexes in the EXACT order displayed (search + order)
            let indexes = table.rows({ search: "applied", order: "applied" }).indexes();
          //  console.log(`📂 Total rows found (displayed order): ${indexes.length}`);

            indexes.each((idx) => {
                let rowData = table.row(idx).data(); // Get row data
                try {
                    let filePath = "";
                    // Instead of parsing the (possibly truncated) cell HTML, get the full path from the row attribute
                    let $row = $(table.row(idx).node());
                    filePath = $row.attr("data-path"); // This should contain the full path

                    let fileName = rowData[2] ? $("<div>").html(rowData[2]).text().trim() : "";
                    let fileType = rowData[3] ? $("<div>").html(rowData[3]).text().trim() : "";

                    if (!filePath || filePath.includes("undefined") || filePath.includes("null")) {
                      //  console.warn(`⚠️ Skipping row ${idx}: Invalid file path => "${filePath}"`);
                        return;
                    }

                    // Convert file path to URL
                    let fileUrl = convertFilePathToURL(filePath);

                    currentFiles.push({
                        url: fileUrl,
                        type: fileType,
                        filename: fileName,
                        filepath: filePath
                    });

                  //  console.log(`✅ [collectFiles] Added file: ${fileUrl}, Type: ${fileType}, Path: ${filePath}`);
                } catch (error) {
                  //  console.error(`❌ [collectFiles] Error processing row ${idx}:`, error);
                }
            });

           // console.log(`✅ Final collected files for ${currentDataset}:`, currentFiles);
        }





        // ✅ Improved Convert File Paths to URLs
        function convertFilePathToURL(filePath) {
            if (!filePath) return "";

            let basePaths = {
                "/Applications/XAMPP/xamppfiles/htdocs/testcreative": "http://172.16.152.47/testcreative",
                "/var/www/html/testcreative": "http://172.16.152.47/testcreative"
            };

            for (let localPath in basePaths) {
                if (filePath.startsWith(localPath)) {
                    let relativePath = filePath.slice(localPath.length).trim();
                    // Remove any leading slashes
                    relativePath = relativePath.replace(/^\/+/, "");
                    // Encode each segment separately so special characters and spaces are handled properly
                    let segments = relativePath.split("/");
                    let encodedSegments = segments.map(segment => encodeURIComponent(segment));
                    return `${basePaths[localPath]}/${encodedSegments.join("/")}`;
                }
            }

            // Fallback: encode the entire filePath by segments
            let segments = filePath.split("/");
            let encodedSegments = segments.map(segment => encodeURIComponent(segment));
            return encodedSegments.join("/");
        }




        // ✅ Navigate Between Files (Skips Corrupt & Missing Files)
        function navigateFile(direction) {
            console.log(`🔄 Navigating: ${direction}, currentIndex=${currentIndex}, totalFiles=${currentFiles.length}`);
            if (!currentFiles.length) {
                console.warn("⚠️ No files in currentFiles!");
                return;
            }

            if (direction === "next") {
                if (currentIndex < currentFiles.length - 1) {
                    currentIndex++;
                } else {
                    console.warn("⚠️ Already at the last file.");
                    return;
                }
            } else if (direction === "prev") {
                if (currentIndex > 0) {
                    currentIndex--;
                } else {
                    console.warn("⚠️ Already at the first file.");
                    return;
                }
            }

            let file = currentFiles[currentIndex];
            let correctedUrl = convertFilePathToURL(file.filepath);
            if (!correctedUrl || correctedUrl.includes("404")) {
                console.warn("⚠️ This file is invalid/corrupt. Not opening preview.");
                return;
            }

            console.log(`📌 [NAVIGATE] Opening index ${currentIndex} => ${correctedUrl}`);
            openPreview(correctedUrl, file.type, file.filename, file.filepath);
        }



        // ✅ Insert a snippet in openPreview to set currentIndex
        //    (Do NOT remove anything else in your openPreview.)
        window.openPreview = function(fileUrl, fileType, fileName = null, filePath = null) {
            const overlay = document.getElementById('file-preview-overlay');
            const content = document.getElementById('file-preview-content');
            const downloadBtn = document.getElementById('download-file-btn');

            if (!overlay || !content || !downloadBtn) {
                console.error("❌ Error: Missing preview modal elements!");
                return;
            }

            content.innerHTML = ''; // Clear previous content

            if (!fileName) {
                fileName = decodeURIComponent(fileUrl.split('/').pop());
            }
            if (!filePath) {
                filePath = fileUrl;
            }

            console.log(`📌 Opening Preview for: ${fileName} (${fileType}) - Path: ${filePath}`);

            // ─────────────────────────────────────────────────────────────────────
            // ✅ [INSERT] Update currentIndex if we find this file in currentFiles
            let foundIndex = currentFiles.findIndex(f => f.filepath === filePath);
            if (foundIndex >= 0) {
                currentIndex = foundIndex;
                console.log(`[openPreview] currentIndex set to ${foundIndex} for filePath="${filePath}"`);
            } else {
                console.warn(`[openPreview] Could not find filePath="${filePath}" in currentFiles.`);
            }
            // ─────────────────────────────────────────────────────────────────────

            // ✅ Set Up Download Confirmation
            downloadBtn.onclick = function () {
                confirmDownload(filePath, fileName);
            };

            // ✅ Display Media
            if (fileType.match(/(jpg|jpeg|png|gif)$/i)) {
                content.innerHTML = `<img src="${fileUrl}" class="preview-media" style="width: 100%; max-height: 80vh; object-fit: contain;">`;
            } else if (fileType.match(/(mp4|avi|mov|mkv)$/i)) {
                content.innerHTML = `<video src="${fileUrl}" controls class="preview-media" style="width: 100%; max-height: 80vh;"></video>`;
            } else {
                content.innerHTML = `<p>Preview not available for this file type.</p>`;
            }

            overlay.style.display = 'flex'; // Show preview overlay
        };


        // ✅ Click Events for Navigation Buttons
        $("#prev-btn").off().on("click", () => navigateFile("prev"));
        $("#next-btn").off().on("click", () => navigateFile("next"));

        // ✅ Keyboard Navigation Support
        $(document).on("keydown", function (e) {
            if (["INPUT", "TEXTAREA"].includes(document.activeElement.tagName)) return; // Ignore inside text inputs

            if (e.key === "ArrowLeft") {
                navigateFile("prev");
            } else if (e.key === "ArrowRight") {
                navigateFile("next");
            } else if (e.key === "Escape") {
                $("#file-preview-overlay").fadeOut();
            }
        });

        // ✅ Close Modal
        $("#close-preview-btn").on("click", function () {
            $("#file-preview-overlay").fadeOut();
        });

        // ✅ Re-collect when switching views
        $("#list-view-btn, #grid-view-btn").on("click", function () {
            console.log(`🔄 View switched: ${$(this).attr("id")}`);
            collectFiles();
        });

        // ✅ Re-collect when switching datasets
        $(".table-button").on("click", function () {
            console.log(`🔄 Switching dataset: ${getActiveDataset()}`);
            currentIndex = 0; // Reset to start
            setTimeout(() => collectFiles(), 500);
        });

        // ✅ Re-collect on sort or search changes
        $("#fileTable").on("order.dt search.dt", function () {
            console.log("🔄 [DataTable] order/search changed => re-collecting...");
            currentIndex = 0; // Always reset
            collectFiles();
        });

        // ✅ Initial collect after page load
        setTimeout(() => {
            console.log("🔄 Collecting files on initial load...");
            collectFiles();
        }, 1000);
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
// ✅ Function to Start Sync with Modal
function startSync() {

    

    $("#syncFiles").hide(); // ✅ Hide Sync button permanently
    $("#progress, #progressBar, .progress").show(); // ✅ Show progress bar
    sessionStorage.setItem("progressVisible", "true"); // ✅ Store progress bar state

    console.log("Sync!!!");
    $.ajax({
        url: 'sync_files.php',
        type: 'POST',
        dataType: 'json',
        success: function (response) {
            if (response.status === 'success') {
                // ✅ Show Sync Started Modal instead of alert
                $("#syncStartedModal").modal("show");

                // ✅ Start polling progress after closing modal
                $("#syncStartedModal").off("hidden.bs.modal").on("hidden.bs.modal", function () {
                    pollProgress();
                });

            } else {
                showErrorModal(response.message || "Failed to start sync.");
            }
        },
        error: function () {
            showErrorModal("Failed to sync files. Please try again.");
        }
    });
}


// ✅ Poll Scan Progress (Uses scan_progress.php)

$(document).ready(function () {
    // ✅ Prevent refresh while scanning
    if (sessionStorage.getItem("scanningInProgress") === "true") {
        console.log("🔄 Scan in progress, preventing refresh...");
        pollProgress();
    }

    // ✅ Block Refresh (F5, Ctrl+R, Closing Tab)
    $(document).on("keydown", function (event) {
        if (sessionStorage.getItem("scanningInProgress") === "true") {
            if (event.which === 116 || (event.ctrlKey && event.which === 82)) {
                event.preventDefault();
                $("#refreshWarningModal").modal("show");
                return false;
            }
        }
    });

    // ✅ Prevent Closing Tab or Reloading Page
    window.addEventListener("beforeunload", function (event) {
        if (sessionStorage.getItem("scanningInProgress") === "true") {
            event.preventDefault();
            event.returnValue = ""; // Required for Chrome
            $("#refreshWarningModal").modal("show");
            return false;
        }
    });
});

// ✅ Poll Scan Progress (Keeps Progress Even If Page Reloads)
function pollProgress() {
    sessionStorage.setItem("scanningInProgress", "true");

    let progressInterval = setInterval(function () {
        $.ajax({
            url: "scan_progress.php",
            type: "GET",
            dataType: "json",
            success: function (data) {
                if (data.status === "running") {
                    const progress = parseFloat(data.progress).toFixed(2);
                    updateProgressBar(progress);
                    sessionStorage.setItem("scanningInProgress", "true");

                } else if (data.status === "completed" || parseFloat(data.progress) >= 100) {
                    clearInterval(progressInterval);
                    updateProgressBar(100);
                    sessionStorage.removeItem("scanningInProgress");

                    // ✅ Hide progress bar after scan completes
                    $("#progress, #progressBar, .progress").fadeOut();
                    sessionStorage.removeItem("progressVisible");

                    checkFilesTable();

                    // ✅ Remove refresh block after scan is complete
                    $(document).off("keydown");
                    window.removeEventListener("beforeunload", function () {});
                    console.log("✅ Scan complete, refresh is now allowed.");

                    // ✅ Show scan completion modal
                    $("#scanCompleteModal").modal("show");

                    // ✅ Reload only after user confirms scan completion
                    $("#scanCompleteModal").off("hidden.bs.modal").on("hidden.bs.modal", function () {
                        location.reload();
                    });
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
    $("#progress").text(`Processing: ${progress}%`);
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

    async function fetchSuggestions(query) {
        if (query.length === 0) {
            suggestionsList.style.display = "none";
            return;
        }

        try {
            const response = await fetch(`fetch_tags2.php?query=${encodeURIComponent(query)}`);
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



<!-- Container for the custom selection box (dynamic filter) -->
<!-- Dynamic Filter Container: Initially visible -->












<!-- JavaScript -->
<!-- JavaScript -->
<script>
$(document).ready(function () {
    let currentFilter = "all"; // Store independent filter state
    let showingType = ""; // Track the current dataset
    let originalHTML = $('#fileTable tbody').html(); // Store the original table HTML
    let originalColumns = $('#fileTable thead tr').html(); // Store the original table header

    let totalRows = $("#fileTable tbody tr").length; // Count available rows
    let defaultPageLength = [10, 25, 50, 100, 500, 1000]; // ✅ Available entries
    let optimalPageLength = totalRows > 0 ? Math.min(totalRows, 1000) : 10; // ✅ Select best match
    let closestPageLength = defaultPageLength.find(n => n >= optimalPageLength) || 10; // ✅ Ensures valid option

    let table = $('#fileTable').DataTable({
        paging: true,
        searching: true,
        responsive: true,
        lengthChange: true,
        pageLength: closestPageLength, // Ensures dropdown is never empty
        lengthMenu: [defaultPageLength, defaultPageLength], // Sets proper dropdown values
        order: [[5, 'desc']], // Order by "Date Created" (column index 5) in descending order (most recent first)
        autoWidth: false,
    });


    console.log(`✅ DataTable initialized with pageLength: ${optimalPageLength}`);





    // Define collectFiles in this scope so it's available to toggleData
    function collectFiles() {
        currentFiles = [];
        let dataset = getActiveDataset();

        let table = $("#fileTable").DataTable();
        // Retrieve row indexes in the EXACT order displayed (search + order).
        let indexes = table.rows({ search: "applied", order: "applied" }).indexes();


        indexes.each((idx) => {
            let rowData = table.row(idx).data(); // Get row data
            try {
                let filePath = "";

                // Convert the HTML of the "Path" cell into a jQuery object
                if (rowData[4]) {
                    let $cell = $("<div>").html(rowData[4]);
                    let $shortPath = $cell.find(".shortened-path");
                    if ($shortPath.length > 0) {
                        filePath = $shortPath.attr("data-fullpath") || $shortPath.text().trim();
                    } else {
                        filePath = $cell.text().trim();
                    }
                }

                let fileName = rowData[2] ? $("<div>").html(rowData[2]).text().trim() : "";
                let fileType = rowData[3] ? $("<div>").html(rowData[3]).text().trim() : "";

                if (!filePath || filePath.includes("undefined") || filePath.includes("null")) {
                    return;
                }

                let fileUrl = convertFilePathToURL(filePath);

                currentFiles.push({
                    url: fileUrl,
                    type: fileType,
                    filename: fileName,
                    filepath: filePath
                });

                console.log(`✅ [collectFiles] Added file: ${fileUrl}, Type: ${fileType}, Path: ${filePath}`);
            } catch (error) {
            }
        });
    }



    // ✅ Independent List/Grid View Toggle
    $('#list-view-btn').on('click', function () {
        $('#list-view').show();
        $('#grid-view').addClass('d-none');
        $(this).addClass('btn-primary').removeClass('btn-outline-secondary');
        $('#grid-view-btn').addClass('btn-outline-secondary').removeClass('btn-primary');
    });

// Inside the grid view button click handler
    $('#grid-view-btn').on('click', function () {
        console.log(`🔄 View switched: grid-view-btn`);
        $('#list-view').hide();
        $('#grid-view').removeClass('d-none');
        $(this).addClass('btn-primary').removeClass('btn-outline-secondary');

        $('#list-view-btn').addClass('btn-outline-secondary').removeClass('btn-primary');

        // ✅ Check if we are showing the default dataset
        if (getActiveDataset() === "Default") {
            console.log("🔎 Re-collecting files for Default dataset...");
            collectFiles();  // Re-collect the files to ensure the grid is updated
        }

        // ✅ Now update the grid view after the default dataset is fetched
        updateGridView();
    });

// Function to set or get active dataset (you should implement this)
    function getActiveDataset() {
        // Assuming you're tracking the active dataset state elsewhere in your code
        return showingType === "" ? "Default" : showingType; // Default dataset when no type is selected
    }







    function applyFilter() {
        $('#fileTable tbody tr').each(function () {
            const rowType = $(this).attr("data-type");
            $(this).toggle(currentFilter === "all" || rowType === currentFilter);
        });
    }


    function updateGridView() {
        const gridContainer = $("#grid-view");
        gridContainer.html(""); // Clear old content

        if (!currentFiles || currentFiles.length === 0) {
            gridContainer.html("<p>No files found.</p>");
            return;
        }

        currentFiles.forEach(file => {
            const fileURL = file.url;
            const fileType = file.type;
            const fileName = file.filename;
            const filePath = file.filepath;

            let filePreview;
            if (fileType.match(/(jpg|jpeg|png|gif)$/i)) {
                filePreview = `<img src="${fileURL}"
                class="grid-thumbnail"
                onclick="openPreview('${fileURL}', '${fileType}', '${fileName}', '${filePath}')">`;
            } else if (fileType.match(/(mp4|mov|avi)$/i)) {
                filePreview = `<video src="${fileURL}"
                class="grid-thumbnail" muted
                onclick="openPreview('${fileURL}', '${fileType}', '${fileName}', '${filePath}')"></video>`;
            } else {
                filePreview = `<p>No Preview</p>`;
            }

            let gridItem = `
            <div class="grid-item" data-type="${fileType}" data-path="${filePath}">
                ${filePreview}
                <div class="file-info">
                    <div>${fileName}</div>
                    <div>${fileType}</div>
                </div>
            </div>
        `;
            gridContainer.append(gridItem);
        });

        console.log("✅ Grid View updated based on collected files:", currentFiles);
    }

    function getRandomColor() {
        // Generates a random hex color, e.g. "#3a2f1b"
        return '#' + Math.floor(Math.random() * 16777215).toString(16).padStart(6, '0');
    }

// Global JavaScript function to shorten filenames (mirrors your PHP logic)
    function shortenFileNameToggle(filename, maxLength = 30) {
        if (!filename) return "";
        if (filename.length <= maxLength) return filename;
        return filename.substring(0, maxLength - 3) + "...";
    }





    function toggleData(type, btnId, endpoint, responseKey) {
        const btn = $(btnId);
        const tableBody = $('#fileTable tbody');
        const tableHead = $('#fileTable thead tr');

        // If we're switching to a new dataset
        if (showingType !== type) {
            if (!originalHTML) {
                originalHTML = tableBody.html();
                originalColumns = tableHead.html();
            }

            // ───── Dynamic Filter for Objects/Emotions ─────
            if (type === "Objects" || type === "Emotions") {
                $("#default-filter").hide();

                $.ajax({
                    url: "fetch_filter_data.php",
                    type: "GET",
                    data: { type: type.toLowerCase() },
                    dataType: "json",
                    success: function (res) {
                        if (res.success) {
                            console.log(`[toggleData:${type}] => fetch_filter_data success:`, res.data);

                            // ✅ ✅ UPDATED: Bootstrap Button Style Checkboxes with random colors and left alignment
                            let togglesHTML = '';
                            res.data.forEach(item => {
                                let safeItem = item.replace(/\s+/g, "_");
                                let randomColor = getRandomColor();
                                togglesHTML += `
<input type="checkbox" class="btn-check dynamic-toggle" id="btn-check-${safeItem}" data-item="${item}" autocomplete="off">
<label class="btn" style="background-color: ${randomColor}; color: #fff;" for="btn-check-${safeItem}">${item}</label>
`;
                            });


                            $("#dynamic-filter-container").html(togglesHTML).css("display", "flex");

                            $(".dynamic-toggle").off("change").on("change", function () {
                                applyDynamicFilter(type);
                            });
                        } else {
                            console.error(`[toggleData:${type}] => fetch_filter_data error:`, res.message);
                        }
                    },
                    error: function () {
                        console.error(`[toggleData:${type}] => fetch_filter_data.php AJAX error`);
                    }
                });
            } else {
                $("#default-filter").show();
                $("#dynamic-filter-container").hide().empty();
            }
            // ───── End Dynamic Filter ─────

            // ───── Fetch dataset and rebuild table ─────
            $.ajax({
                url: endpoint,
                type: "GET",
                dataType: "json",
                success: function (response) {
                    if (response.success) {
                        table.clear().destroy();
                        tableBody.html("");
                        currentFiles = [];

                        tableHead.html(originalColumns);

                        let extraColumnHeader = "";
                        if (type === "Objects") {
                            extraColumnHeader = '<th class="dynamic-column">Detected Objects</th>';
                        } else if (type === "Emotions") {
                            extraColumnHeader = '<th class="dynamic-column">Emotions</th>';
                        } else if (type === "Duplicates") {
                            extraColumnHeader = '<th class="dynamic-column">Original File</th>';
                        }

                        if (extraColumnHeader) {
                            tableHead.find("th:last").before(extraColumnHeader);
                        }

                        response[responseKey].forEach((file) => {
                            const fileURL = convertFilePathToURL(file.filepath);
                            const fileType = file.filetype;
                            const filePath = file.filepath;
                            const fileName = file.filename;

                            currentFiles.push({ url: fileURL, type: fileType, filename: fileName, filepath: filePath });

                            let thumbnailHTML = fileType.match(/(jpg|jpeg|png|gif)$/i)
                                ? `<img src="${fileURL}" class="thumbnail" style="width:60px; height:60px; object-fit:cover; cursor:pointer;"
                              onclick="openPreview('${fileURL}','${fileType}','${fileName}','${filePath}')">`
                                : fileType.match(/(mp4|mov|avi)$/i)
                                    ? `<video src="${fileURL}" class="thumbnail" muted style="width:60px; height:60px; object-fit:cover;"
                              onclick="openPreview('${fileURL}','${fileType}','${fileName}','${filePath}')"></video>`
                                    : `<span>No Preview</span>`;

                            let extraColumnData = "";
                            if (type === "Objects") {
                                extraColumnData = file.detected_objects || "N/A";
                            } else if (type === "Emotions") {
                                extraColumnData = file.emotion || "N/A";
                            } else if (type === "Duplicates") {
                                extraColumnData = file.original_filename || "N/A";
                            }

                            let actionsColumn = "";
                            if (type === "Duplicates") {
                                actionsColumn = `
                              <button class="btn btn-danger btn-sm" onclick="deleteMedia('${filePath}','${fileName}')">
                                <i class="fas fa-trash-alt"></i> Delete
                              </button>`;
                            } else {
                                actionsColumn = `
                              <div class="dropdown">
                                <button class="btn btn-sm btn-danger dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                  <i class="fas fa-cogs"></i> Actions
                                </button>
                                <ul class="dropdown-menu">
                                  <li><a class="dropdown-item" href="javascript:void(0);" onclick="renameMedia('${filePath}','${fileName}')">
                                      <i class="fas fa-i-cursor"></i> Rename</a></li>
                                  <li><a class="dropdown-item" href="javascript:void(0);" onclick="copyMedia('${filePath}')">
                                      <i class="fas fa-copy"></i> Copy</a></li>
                                  <li><a class="dropdown-item" href="javascript:void(0);" onclick="downloadMedia('${filePath}')">
                                      <i class="fas fa-download"></i> Download</a></li>
                                  <li><a class="dropdown-item text-danger" href="javascript:void(0);" onclick="moveToTrash('${filePath}','${fileName}')">
                                      <i class="fas fa-trash"></i> Move to Trash</a></li>
                                </ul>
                              </div>`;
                            }

                            tableBody.append(`
                          <tr data-type="${fileType}" data-objects="${(file.detected_objects||"").toLowerCase()}"
                              data-emotion="${(file.emotion||"").toLowerCase()}" data-path="${filePath}">
                            <td><input type="checkbox" class="row-checkbox" value="${filePath}"></td>
                            <td>${thumbnailHTML}</td>
                          <td>${shortenFileNameToggle(fileName, 30)}</td>


                            <td>${fileType}</td>
                            <td class="shortened-path" data-fullpath="${filePath}" title="${filePath}">
                              ${filePath.length>50 ? filePath.substring(0,50)+"..." : filePath}
                            </td>
                            <td>${file.datecreated || "N/A"}</td>
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
                            pageLength: 500,
                            lengthMenu: [[10, 25, 50, 100, 500, -1], [10, 25, 50, 100, 500, "All"]],
                            order: [[5, 'desc']], // Order by "Date Created" column (6th column)
                            autoWidth: false,
                        });


                        applyFilter();
                        updateGridView();

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
            // Hiding the toggled dataset => restore default
            table.clear().destroy();
            tableHead.html(originalColumns);
            tableBody.html(originalHTML);
            currentFiles = [];

            table = $('#fileTable').DataTable({
                paging: true,
                searching: true,
                responsive: true,
                lengthChange: true,
                pageLength: 500,
                lengthMenu: [[10, 25, 50, 100, 500, -1], [10, 25, 50, 100, 500, "All"]],
                order: [[4, 'desc']],
                autoWidth: false,
            });

            collectFiles();
            applyFilter();
            updateGridView();

            btn.text(`Show ${type}`);
            showingType = "";

            $("#default-filter").show();
            $("#dynamic-filter-container").hide().empty();
        }
    }


// 1) Attach a change listener to the radio buttons
    $('input[name="filter-filetype"]').on('change', function () {
        const selected = $(this).val();
        console.log("[Filetype Filter] changed =>", selected);

        // 2) If “all,” clear the column search. Otherwise, filter for exactly that type.
        if (selected === 'all') {
            table.column(3).search('').draw();
        } else {
            // Use a regex search: ^selected$ => exact match, case-insensitive off
            table.column(3).search('^' + selected + '$', /*regex=*/true, /*smart=*/false).draw();
        }
    });





    $(document).ready(function(){
        $('#grid-view-btn').on('click', function(){
            // Hide the entire row that includes your filters
            $('.d-flex.justify-content-between.align-items-center.mb-3').hide();
        });

        $('#list-view-btn').on('click', function(){
            // Show it again for list view
            $('.d-flex.justify-content-between.align-items-center.mb-3').show();
        });
    });





    function applyDynamicFilter(type) {
        // Gather all toggles that are checked
        let toggledItems = [];
        $(".dynamic-toggle:checked").each(function() {
            toggledItems.push($(this).attr("data-item").toLowerCase());
        });

        // If no toggles are on, show all rows
        if (!toggledItems.length) {
            $("#fileTable tbody tr").show();
            return;
        }

        // Filter each row by whether it has at least one toggled item in the relevant field
        $("#fileTable tbody tr").each(function() {
            let row = $(this);
            let rowVal = "";

            if (type === "Objects") {
                // Compare toggles to data-objects attribute
                rowVal = row.attr("data-objects") || "";
            } else if (type === "Emotions") {
                // Compare toggles to data-emotion attribute
                rowVal = row.attr("data-emotion") || "";
            }

            // If rowVal matches at least one toggled item, show the row
            let matched = toggledItems.some(item => rowVal.includes(item));
            row.toggle(matched);
        });
    }










    const selectAllCheckbox = $('#select-all'); // Select All Checkbox
    const actionButtonContainer = $('.action-button-container'); // Bulk action buttons container















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

// ✅ Event delegation: Handle "Select All" toggle
    $(document).on('change', '#select-all', function () {
        $('.row-checkbox').prop('checked', this.checked);
        toggleBulkActionButtons();
    });

// ✅ Event delegation: Handle individual row checkboxes
    $(document).on('change', '.row-checkbox', function () {
        let allChecked = $('.row-checkbox').length === $('.row-checkbox:checked').length;
        $('#select-all').prop('checked', allChecked); // Update "Select All" checkbox state
        toggleBulkActionButtons();
    });








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




// Function to hide (or remove) corrupted thumbnails in table view


$(document).ready(function () {
    function initializeTooltips() {
        console.log("🔄 [TOOLTIP] Initializing tooltips...");

        // Destroy previous tooltips to prevent stacking issues
        $('[data-bs-toggle="tooltip"]').tooltip("dispose");

        // Apply tooltips dynamically to all `.shortened-path` elements
        $(".shortened-path").each(function () {
            let fullPath = $(this).attr("data-fullpath") || $(this).text().trim(); // Get full path from attribute or text

            $(this).attr("data-bs-toggle", "tooltip") // Bootstrap tooltip
                .attr("data-bs-placement", "top")
                .attr("title", fullPath); // Set tooltip content
        });

        // Reinitialize tooltips
        $('[data-bs-toggle="tooltip"]').tooltip();
        console.log("✅ [TOOLTIP] Tooltips initialized for all rows.");
    }

    // ✅ Initialize tooltips on page load
    initializeTooltips();

    // ✅ Ensure tooltips refresh dynamically when dataset changes
    function refreshTooltipsOnDatasetChange() {
        console.log("🔄 [TOOLTIP] Refreshing tooltips after dataset switch...");

        setTimeout(() => {
            initializeTooltips(); // Reinitialize after dataset loads
        }, 500);
    }

    // ✅ Hook tooltip refresh when switching datasets
    $("#showDetectedObjects, #showEmotions, #showDuplicates").on("click", function () {
        refreshTooltipsOnDatasetChange();
    });

    // ✅ Reinitialize tooltips when the table updates (pagination, search, sorting)
    $("#fileTable").on("draw.dt", function () {
        refreshTooltipsOnDatasetChange();
    });

    // ✅ Ensure tooltips work after dynamic AJAX content loads
    $(document).ajaxComplete(function () {
        refreshTooltipsOnDatasetChange();
    });
});




// ✅ Open Preview with Correct Download Action
// ✅ Open Preview & Set Up Download Action
// ✅ Open Preview with Correct FilePath & Filename
function openPreview(fileUrl, fileType, fileName = null, filePath = null) {
    const overlay = document.getElementById('file-preview-overlay');
    const content = document.getElementById('file-preview-content');
    const downloadBtn = document.getElementById('download-file-btn');

    if (!overlay || !content || !downloadBtn) {
        console.error("❌ Error: Missing preview modal elements!");
        return;
    }

    content.innerHTML = ''; // Clear previous content

    // ✅ Ensure fileName is set correctly
    if (!fileName) {
        fileName = decodeURIComponent(fileUrl.split('/').pop()); // Extract from URL if missing
    }

    // ✅ Ensure filePath is set correctly
    if (!filePath) {
        filePath = fileUrl; // Fallback to URL if `filePath` is missing
    }

    console.log(`📌 Opening Preview for: ${fileName} (${fileType}) - Path: ${filePath}`);

    // ✅ Set Up Download Confirmation
    downloadBtn.onclick = function () {
        confirmDownload(filePath, fileName); // ✅ Now uses `filePath`
    };

    // ✅ Display Media
    if (fileType.match(/(jpg|jpeg|png|gif)$/i)) {
        content.innerHTML = `<img src="${fileUrl}" class="preview-media" style="width: 100%; max-height: 80vh; object-fit: contain;">`;
    } else if (fileType.match(/(mp4|avi|mov|mkv)$/i)) {
        content.innerHTML = `<video src="${fileUrl}" controls class="preview-media" style="width: 100%; max-height: 80vh;"></video>`;
    } else {
        content.innerHTML = `<p>Preview not available for this file type.</p>`;
    }

    overlay.style.display = 'flex'; // Show preview overlay
}



// ✅ Extract the correct local file path from the URL
function extractFilePath(fileUrl) {
    // If the URL already starts with "/Applications/XAMPP/", return as is
    if (fileUrl.startsWith("/Applications/XAMPP/")) {
        return fileUrl;
    }

    // ✅ Convert HTTP URL back to local path
    let baseUrl = "http://172.16.152.47/testcreative/";
    let localPath = "/Applications/XAMPP/xamppfiles/htdocs/testcreative/";

    if (fileUrl.startsWith(baseUrl)) {
        return localPath + decodeURIComponent(fileUrl.replace(baseUrl, ""));
    }

    return fileUrl; // Fallback: Return original if no match
}

// ✅ Auto-download after confirmation
function confirmDownload(filePath, fileName) {
    $("#confirmationModalLabel").html("Confirm Download");
    $("#confirmationModalBody").html(`You are about to download: <br><strong>${fileName}</strong>`);
    $("#confirmationModal").modal("show");

    // ✅ Ensure correct file path is used
    let correctFilePath = extractFilePath(filePath);

    // ✅ Auto-download after confirmation
    $("#confirmActionBtn").off("click").on("click", function () {
        $("#confirmationModal").modal("hide");
        downloadMediaAction(correctFilePath);
    });
}


// ✅ Use corrected file path for download
function downloadMediaAction(filePath) {
    const downloadUrl = `download_file.php?file=${encodeURIComponent(filePath)}`;
    window.location.href = downloadUrl;
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





// ✅ Trigger Modal Before Executing Any Action
function moveToTrash(filePath, fileName) {
    openConfirmationModal("move_to_trash", filePath, fileName);
}

function renameMedia(filePath, fileName) {
    let row = $(`tr[data-path="${filePath}"]`);
    let latestFileName = row.find("td:nth-child(3)").text().trim(); // Get latest filename

    openConfirmationModal("rename", filePath, latestFileName); // Use latest filename
}


function copyMedia(filePath) {
    openConfirmationModal("copy", filePath, "");
}

function downloadMedia(filePath) {
    openConfirmationModal("download", filePath, "");
}

function deleteMedia(filePath, fileName, isTrash = false) {
    openConfirmationModal(isTrash ? "delete_trash" : "delete", filePath, fileName);
}



function openConfirmationModal(action, filePath, fileName) {
    let modalTitle = "";
    let modalMessage = "";

    if (action === "rename") {
        modalTitle = "Confirm Rename";
        modalMessage = `Enter the new name for: <br><br>
                        <strong>${fileName}</strong>
                        <br><br>
                        <input type="text" id="newFileName" class="form-control" value="${fileName}">`;
    } else if (action === "move_to_trash") {
        modalTitle = "Confirm Move to Trash";
        modalMessage = `Are you sure you want to move this file to trash? <br><br>
                        <strong>${fileName}</strong>`;
    } else if (action === "copy") {
        modalTitle = "Confirm Copy";
        modalMessage = `Are you sure you want to create a duplicate of this file?`;
    } else if (action === "download") {
        modalTitle = "Confirm Download";
        modalMessage = `You are about to download this file.`;
    } else if (action === "delete") {
        modalTitle = "Confirm Deletion";
        modalMessage = `Are you sure you want to delete this file? <br><br>
                        <strong>${fileName}</strong>`;
    } else if (action === "delete_trash") {
        modalTitle = "Confirm Permanent Deletion";
        modalMessage = `Are you sure you want to permanently delete this file from TRASH? <br><br>
                        <strong>${fileName}</strong>`;
    }

    $("#confirmationModalLabel").html(modalTitle);
    $("#confirmationModalBody").html(modalMessage);
    $("#confirmationModal").modal("show");

    // ✅ Attach Event Listener for Proceed Button
    $("#confirmActionBtn").off("click").on("click", function () {
        $("#confirmationModal").modal("hide");
        executeAction(action, filePath, fileName);
    });
}



function executeAction(action, filePath, fileName) {
    if (action === "rename") {
        const newFileName = $("#newFileName").val().trim();
        if (!newFileName) {
            showErrorModal("New filename cannot be empty.");
            return;
        }
        renameMediaAction(filePath, newFileName);
    } else if (action === "move_to_trash") {
        moveToTrashAction(filePath, fileName);
    } else if (action === "delete") {
        deleteMediaAction(filePath, fileName);
    } else if (action === "delete_trash") {
        deleteMediaAction(filePath, fileName, true);
    } else if (action === "copy") {
        copyMediaAction(filePath);
    } else if (action === "download") {
        downloadMediaAction(filePath);
    }
}


function moveToTrashAction(filePath, fileName) {
    $.ajax({
        url: 'moveToTrash.php',
        type: 'POST',
        data: JSON.stringify({ filepath: decodeURIComponent(filePath), fileName: fileName }),
        contentType: 'application/json',
        dataType: 'json',
        success: function (data) {
            if (data.status === 'success') {
                showSuccessModal(`File <strong>${fileName}</strong> moved to trash successfully.`);
                $(`tr[data-path="${filePath}"]`).remove();
            } else {
                showErrorModal('Error: ' + data.message);
            }
        },
        error: function (xhr) {
            console.error('Error moving file to trash:', xhr.responseText);
            showErrorModal('An unexpected error occurred.');
        }
    });
}



function renameMediaAction(filePath, newFileName) {
    console.log("Rename Payload:", { filePath, newFileName });
    $.ajax({
        url: 'rename_file.php',
        type: 'POST',
        data: JSON.stringify({ filePath: decodeURIComponent(filePath), newName: newFileName }),
        contentType: 'application/json',
        dataType: 'json',
        success: function (result) {
            console.log("Rename response received:", result);

            if (result.success) {
                showSuccessModal(`File renamed successfully to <strong>${result.newFileName}</strong>.`);

                // ✅ Find the row by old path
                let row = $(`tr[data-path="${filePath}"]`);

                // ✅ Update filename in the table
                row.find("td:nth-child(3)").text(result.newFileName);

                // ✅ Update data-path to new file path
                row.attr("data-path", result.newFilePath);

                // ✅ Update any onclick or data attributes
                row.find(".dropdown-item").each(function () {
                    let onclickValue = $(this).attr("onclick");
                    if (onclickValue) {
                        $(this).attr("onclick", onclickValue.replace(filePath, result.newFilePath));
                    }
                });

                // ✅ Update modal input field if rename modal is open
                if ($("#renameModal").is(":visible")) {
                    $("#newFileName").val(result.newFileName);
                }

                // ✅ Optional: rebind Copy/Trash button with the updated path
                row.find(".copy-btn").attr("onclick", `copyMediaAction('${result.newFilePath}')`);
                row.find(".trash-btn").attr("onclick", `moveToTrashAction('${result.newFilePath}', '${result.newFileName}')`);

                console.log("Rename operation completed successfully. Updated row and actions.");

            } else {
                showErrorModal('Error renaming file: ' + result.error);
                console.error("Rename failed. Server returned error:", result);
            }
        },
        error: function (xhr) {
            console.error('AJAX error during rename:', xhr.responseText);
            showErrorModal('An error occurred while renaming the file.');
        }
    });
}








function copyMediaAction(filePath) {
    console.log("Copy Payload:", { filePath });

    $.ajax({
        url: 'copy_file.php',
        type: 'POST',
        data: JSON.stringify({ filePath: filePath }), // No need to decode
        contentType: 'application/json',
        dataType: 'json',
        success: function (response) {
            console.log("Copy Response:", response);

            if (response.success) {
                const newFilename = response.newPath.split('/').pop();
                showSuccessModal(`File copied successfully!<br>
                    <strong>New File:</strong> <code style="color:#8B0000;">${newFilename}</code>`);

                // ✅ Optional: Add to UI dynamically (if needed)
                // refresh or prepend manually here
            } else {
                showErrorModal('Error copying file: ' + response.error);
            }
        },
        error: function (xhr) {
            console.error('AJAX error during copy:', xhr.responseText);
            showErrorModal('An error occurred while copying the file.');
        }
    });
}








function downloadMediaAction(filePath) {
    const downloadUrl = `download_file.php?file=${encodeURIComponent(filePath)}`;
    window.location.href = downloadUrl;
    showSuccessModal("File downloaded successfully.");
}




function deleteMediaAction(filePath, fileName) {
    console.log("[Delete] Sending AJAX request to delete:", filePath);

    $.ajax({
        url: "delete_file.php",
        type: "POST",
        dataType: "json",         // Expect a JSON response
        data: {
            filepath: filePath      // This matches $_POST['filepath'] in delete_file.php
        },
        success: function (result) {
            console.log("[Delete] Server response:", result);

            if (result.status === "success") {
                // 1) Show a success message
                showSuccessModal(`File <strong>${fileName}</strong> deleted successfully.`);

                // 2) Remove the row from your DataTable (if you're using DataTables)
                const table = $("#fileTable").DataTable();
                const $row = $(`tr[data-path="${filePath}"]`);
                if ($row.length) {
                    table.row($row).remove().draw(false);
                }

                // 3) If you have a grid view, remove that item too
                $(`.grid-item[data-path="${filePath}"]`).fadeOut(300, function() {
                    $(this).remove();
                });

            } else {
                // 4) Show error from the server
                showErrorModal(`Error deleting file: ${result.message}`);
                // Optionally, log debug info
                if (result.debug) {
                    console.warn("[Delete] Debug info:", result.debug);
                }
            }
        },
        error: function (xhr, status, error) {
            console.error("[Delete] AJAX error:", error, "XHR:", xhr.responseText);
            showErrorModal("An error occurred while deleting the file.");
        }
    });
}






function showSuccessModal(message) {
    $("#successModalBody").html(message);
    $("#successModal").modal("show");
}

function showErrorModal(message) {
    $("#errorModalBody").html(message);
    $("#errorModal").modal("show");
}




</script>
<script src="assets/js/main.js"></script>

</body>
</html>
