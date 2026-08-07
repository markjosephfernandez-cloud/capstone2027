<?php
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
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: left;
            padding: 10px 20px;
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }

        .breadcrumb {
            margin-bottom: 0;
        }

        .add-folder-btn {
            margin-left: auto; /* Align button to the right */
        }

        #file-list img {
            margin: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
            cursor: pointer; /* Make images clickable */
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

        #file-preview-content {
            max-width: 95vw;
            max-height: 95vh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden; /* Prevents scrollbars inside the content container */
        }

        .preview-media {
            max-width: 100%;
            max-height: 80vh; /* Keep aspect ratio */
        }

        #close-preview-btn {
            position: absolute;
            top: 20px;
            left: 20px;
            font-size: 2rem;
            color: white;
            background: none;
            border: none;
            cursor: pointer;
            z-index: 1100;
        }

        #close-preview-btn:hover {
            opacity: 0.7; /* Slightly transparent on hover */
        }

        .navigation-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            font-size: 2rem;
            color: white;
            background: none;
            border: none;
            cursor: pointer;
            z-index: 1100;
        }

        #prev-btn {
            left: 20px;
        }

        #next-btn {
            right: 20px;
        }
    </style>
</head>

<body>

<main id="main" class="main">
    <div class="header">
        <h1 id="pageTitle" class="h4"><?php echo htmlspecialchars($pageTitle); ?></h1>
        <button class="btn btn-primary add-folder-btn" onclick="location.href='add_folder.php'">Add New Folder</button>
    </div><!-- End Header -->

    <div class="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">Home</a></li>
            <li class="breadcrumb-item active" id="breadcrumbTitle"><?php echo htmlspecialchars($pageTitle); ?></li>
        </ol>
    </div><!-- End Breadcrumb -->

    <!-- Add Search Form -->
    <section class="section">
        <div class="row">
            <div class="col-lg-12">
                <form action="search_afp.php" method="GET">
                    <div class="input-group mb-3">
                        <input type="text" class="form-control" placeholder="Search by AI-detected objects (e.g., 'person', 'car')" name="query">
                        <div class="input-group-append">
                            <button class="btn btn-primary" type="submit">Search</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>

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
                        <tbody id="file-list">
                            <!-- Rows will be added by file-list.php -->
                        </tbody>
                    </table>
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
    let currentFiles = []; // Array to hold the current files
    let currentIndex = 0; // Index of the current file

    // Function to open the preview overlay and display the image or video
    function openModal(fileUrl, fileType, files) {
        currentFiles = files; // Save the current files
        currentIndex = 0; // Reset index
        displayFile(fileUrl, fileType); // Display the first file
        document.getElementById("file-preview-overlay").style.display = "flex"; // Show the overlay
    }

    function displayFile(fileUrl, fileType) {
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
    }

    // Function to close the preview overlay
    function closePreview() {
        document.getElementById("file-preview-overlay").style.display = "none"; // Hide the overlay
    }

    function navigateFile(direction) {
        if (direction === 'next' && currentIndex < currentFiles.length - 1) {
            currentIndex++;
        } else if (direction === 'prev' && currentIndex > 0) {
            currentIndex--;
        }
        const fileUrl = currentFiles[currentIndex].path; // Adjust path as needed
        displayFile(fileUrl, currentFiles[currentIndex].type); // Update display
    }
</script>

<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
