<?php
// Backend logic for creating a new folder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['folderName'])) {
    // Path to the directory where new folders will be created
    $directory = '/Volumes/creative/categorizesample';
    $folderName = basename($_POST['folderName']); // Ensure the folder name is safe

    // Full path to the new folder
    $newFolderPath = $directory . '/' . $folderName;

    // Check if the folder already exists
    if (file_exists($newFolderPath)) {
        echo 'Folder already exists';
    } else {
        // Attempt to create the folder
        if (mkdir($newFolderPath, 0777, true)) {
            echo 'success';
        } else {
            echo 'Failed to create folder';
        }
    }

    exit(); // End the script execution after handling the folder creation
}
?>

<div class="folder-actions mt-4">
    <!-- Add New Folder Button with Dropdown -->
    <div class="dropdown">
        <button class="btn btn-primary dropdown-toggle" type="button" id="addFolderDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            Add New Folder
        </button>
        <ul class="dropdown-menu" aria-labelledby="addFolderDropdown">
            <li><a class="dropdown-item" href="#" id="new-folder">Add New Folder</a></li>
            <li><a class="dropdown-item" href="#" id="file-upload">File Upload</a></li>
            <li><a class="dropdown-item" href="#" id="folder-upload">Folder Upload</a></li>
        </ul>
    </div>
</div>

<!-- Hidden Inputs for File and Folder Uploads -->
<input type="file" id="fileInput" style="display: none;">
<input type="file" id="folderInput" webkitdirectory directory style="display: none;">

<!-- Modal for Adding a New Folder -->
<div class="modal fade" id="addNewFolderModal" tabindex="-1" aria-labelledby="addNewFolderLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addNewFolderLabel">Add New Folder</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label for="folderName" class="form-label">Folder Name</label>
          <input type="text" class="form-control" id="folderName" placeholder="Enter folder name">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="createFolder">Create</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Handle "Add New Folder" click
    document.getElementById('new-folder').addEventListener('click', function() {
        // Open the modal for creating a new folder
        var addNewFolderModal = new bootstrap.Modal(document.getElementById('addNewFolderModal'));
        addNewFolderModal.show();
    });

    // Handle folder creation when the user clicks "Create"
    document.getElementById('createFolder').addEventListener('click', function() {
        const folderName = document.getElementById('folderName').value;
        if (folderName) {
            // Send the folder name to the backend via AJAX
            const formData = new FormData();
            formData.append('folderName', folderName);

            fetch('folder-actions.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                if (data === 'success') {
                    alert('Folder created successfully');
                } else {
                    alert('Error creating folder: ' + data);
                }
            })
            .catch(error => console.error('Error:', error));

            // Close the modal
            var addNewFolderModal = bootstrap.Modal.getInstance(document.getElementById('addNewFolderModal'));
            addNewFolderModal.hide();
        } else {
            alert('Please enter a folder name.');
        }
    });

    // Handle "File Upload" click
    document.getElementById('file-upload').addEventListener('click', function() {
        // Trigger the file input click
        document.getElementById('fileInput').click();
    });

    // Handle file input change (when a user selects a file)
    document.getElementById('fileInput').addEventListener('change', function(event) {
        const files = event.target.files;
        if (files.length > 0) {
            // Handle file upload logic here (send to backend or display in UI)
            console.log('File uploaded: ' + files[0].name);
        }
    });

    // Handle "Folder Upload" click
    document.getElementById('folder-upload').addEventListener('click', function() {
        // Trigger the folder input click
        document.getElementById('folderInput').click();
    });

    // Handle folder input change (when a user selects a folder)
    document.getElementById('folderInput').addEventListener('change', function(event) {
        const files = event.target.files;
        if (files.length > 0) {
            // Handle folder upload logic here (send to backend or display in UI)
            console.log('Folder uploaded with files: ');
            Array.from(files).forEach(file => {
                console.log(file.webkitRelativePath);
            });
        }
    });
});
</script>
