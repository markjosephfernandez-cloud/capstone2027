<?php
require_once 'config.php';
session_start();

// Ensure database connection
if (!isset($conn) || $conn->connect_error) {
    die(json_encode(['status' => 'error', 'message' => 'Database connection failed.']));
}

// Current user
$employee_id = $_SESSION['employee_id'] ?? null;

// Decode JSON input
$data = json_decode(file_get_contents('php://input'), true);
if (empty($data['filepath']) || empty($data['fileName'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
    exit;
}

$filePath     = trim($data['filepath']);
$fileName     = trim($data['fileName']);
$featuredPath = '/Applications/XAMPP/xamppfiles/htdocs/testcreative/Featured';

// Recursively delete a directory
function deleteDirectory($dir) {
    if (!is_dir($dir)) return is_file($dir) ? unlink($dir) : false;
    foreach (array_diff(scandir($dir), ['.','..']) as $f) {
        $p = "$dir/$f";
        is_dir($p) ? deleteDirectory($p) : unlink($p);
    }
    return rmdir($dir);
}

// Was it a folder?
$wasDir = is_dir($filePath);

// Perform the filesystem delete
if ($wasDir) {
    $deleted = deleteDirectory($filePath);
} elseif (file_exists($filePath)) {
    $deleted = unlink($filePath);
} else {
    echo json_encode(['status'=>'error','message'=>'Not found.']);
    exit;
}

if (! $deleted) {
    echo json_encode(['status'=>'error','message'=>'Delete failed.']);
    exit;
}

if ($wasDir) {
    // ── 1) Log folder deletion
    if ($employee_id) {
        $log = $conn->prepare("
            INSERT INTO activity (admin_id, action, details)
            VALUES (?, 'FOLDER_DELETE', ?)
        ");
        $detail = "Deleted folder '{$fileName}' at '{$filePath}'";
        $log->bind_param("is", $employee_id, $detail);
        $log->execute();
        $log->close();
    }

    // ── 2a) Featured-album cleanup
    if (strpos($filePath, $featuredPath) === 0) {
        $s = $conn->prepare("SELECT id FROM albums WHERE name = ?");
        $s->bind_param("s", $fileName);
        $s->execute();
        $r = $s->get_result();
        if ($alb = $r->fetch_assoc()) {
            $aid = $alb['id'];
            $d1 = $conn->prepare("DELETE FROM album_files WHERE album_id = ?");
            $d1->bind_param("i",$aid); $d1->execute(); $d1->close();
            $d2 = $conn->prepare("DELETE FROM albums       WHERE id = ?");
            $d2->bind_param("i",$aid); $d2->execute(); $d2->close();
        }
        $s->close();
        echo json_encode(['status'=>'success','message'=>'Featured album deleted.']);
    }
    // ── 2b) Regular folder cleanup (from `folder` table)
    else {
        $d = $conn->prepare("DELETE FROM folder WHERE name = ?");
        $d->bind_param("s",$fileName);
        $d->execute();
        $d->close();
        echo json_encode(['status'=>'success','message'=>'Folder deleted and DB record removed.']);
    }

} else {
    // ── File deletion => soft-delete in `files` table
    if ($employee_id) {
        $u = $conn->prepare("
            UPDATE files
               SET is_deleted     = 1,
                   last_action    = 'deleted',
                   last_action_at = NOW(),
                   employee_id    = ?
             WHERE filepath = ?
        ");
        $u->bind_param("is", $employee_id, $filePath);
        $u->execute();
        $u->close();
    }
    echo json_encode(['status'=>'success','message'=>"File '{$fileName}' deleted."]);
}

$conn->close();
?>