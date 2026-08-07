<?php
session_start();
require 'config.php'; // Database connection file

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = array();

    if (!empty($_POST['filepath']) && !empty($_POST['filename'])) {
        // Normalize and sanitize the input
        $filePath = strtolower(trim($_POST['filepath'])); // Convert to lowercase and trim spaces
        $fileName = strtolower(trim($_POST['filename'])); // Convert to lowercase and trim spaces

        // Retrieve admin ID from session
        $publishedBy = $_SESSION['employee_id'] ?? null;

        // Debugging logs
        error_log("DEBUG: Received normalized filepath: '$filePath'");
        error_log("DEBUG: Received normalized filename: '$fileName'");

        if (!$publishedBy) {
            $response['status'] = 'error';
            $response['message'] = 'Admin user not logged in.';
            echo json_encode($response);
            exit();
        }

        // Fetch the file ID from the `files` table
        $sqlFetchFile = "
            SELECT id 
            FROM files 
            WHERE LOWER(TRIM(filepath)) = LOWER(TRIM(?)) AND LOWER(TRIM(filename)) = LOWER(TRIM(?))
        ";

        if ($stmtFetch = $conn->prepare($sqlFetchFile)) {
            $stmtFetch->bind_param("ss", $filePath, $fileName);

            // Log the exact query for debugging
            error_log("DEBUG: Executing query: SELECT id FROM files WHERE LOWER(TRIM(filepath)) = '$filePath' AND LOWER(TRIM(filename)) = '$fileName'");

            if ($stmtFetch->execute()) {
                $resultFetch = $stmtFetch->get_result();

                // Log the number of rows returned
                error_log("DEBUG: Rows returned: " . $resultFetch->num_rows);

                if ($resultFetch->num_rows > 0) {
                    $fileRow = $resultFetch->fetch_assoc();
                    $fileId = $fileRow['id'];

                    // Insert the file into the `published_files` table
                    $sqlInsert = "
                        INSERT INTO published_files (file_id, published_at, status, published_by)
                        VALUES (?, NOW(), 'published', ?)
                    ";

                    if ($stmtInsert = $conn->prepare($sqlInsert)) {
                        $stmtInsert->bind_param("ii", $fileId, $publishedBy);

                        if ($stmtInsert->execute()) {
                            $response['status'] = 'success';
                            $response['message'] = 'File published successfully!';
                        } else {
                            $response['status'] = 'error';
                            $response['message'] = 'Failed to execute the insert query: ' . $stmtInsert->error;
                        }

                        $stmtInsert->close();
                    } else {
                        $response['status'] = 'error';
                        $response['message'] = 'Failed to prepare the insert SQL statement: ' . $conn->error;
                    }
                } else {
                    // Log failed match for debugging
                    $response['status'] = 'error';
                    $response['message'] = 'No matching file found in the database. Check if the filepath and filename are exact matches.';
                    error_log("DEBUG: No matching file found. filepath: '$filePath', filename: '$fileName'");
                }
            } else {
                $response['status'] = 'error';
                $response['message'] = 'Failed to execute the fetch query: ' . $stmtFetch->error;
            }
            $stmtFetch->close();
        } else {
            $response['status'] = 'error';
            $response['message'] = 'Failed to prepare the fetch SQL statement: ' . $conn->error;
        }
    } else {
        $response['status'] = 'error';
        $response['message'] = 'File path or file name is missing.';
    }

    // Return response in JSON format
    echo json_encode($response);
} else {
    http_response_code(405); // Method not allowed
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
