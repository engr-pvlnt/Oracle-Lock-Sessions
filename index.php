<?php
// Your encrypted connection parameters
$encrypted_username = '';
$encrypted_password = '';
$encrypted_db = ''; 
$encrypted_host = ''; 

// Decrypt the connection parameters
$oracle_username = base64_decode($encrypted_username);
$oracle_password = base64_decode($encrypted_password);
$oracle_db = base64_decode($encrypted_db);
$oracle_host = base64_decode($encrypted_host);

// Connect to Oracle
$conn = oci_connect($oracle_username, $oracle_password, "(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=$oracle_host)(PORT=1521))(CONNECT_DATA=(SID=$oracle_db)))");
if (!$conn) {
    $error = oci_error();
    echo "Failed to connect to Oracle: " . htmlspecialchars($error['message']);
    exit;
}

// Initialize message variable
$message = '';

/**
 * Handle disconnect request
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sid'])) {
    $sidToKill = $_POST['sid'];

    // Validate that sid is numeric
    if (!is_numeric($sidToKill)) {
        $message = "Invalid session ID.";
    } else {
        // Fetch serial# for the session
        $sql_serial = "SELECT serial# FROM v\$session WHERE sid = :sid";
        $stid_serial = oci_parse($conn, $sql_serial);
        oci_bind_by_name($stid_serial, ':sid', $sidToKill);
        oci_execute($stid_serial);
        $serialRow = oci_fetch_assoc($stid_serial);
        oci_free_statement($stid_serial);

        if ($serialRow) {
            $serial = $serialRow['SERIAL#'];

            // Kill session
            $sql_kill = "ALTER SYSTEM KILL SESSION :sid, :serial";
            $stid_kill = oci_parse($conn, $sql_kill);
            oci_bind_by_name($stid_kill, ':sid', $sidToKill);
            oci_bind_by_name($stid_kill, ':serial', $serial);
            try {
                oci_execute($stid_kill);
                $message = "Successfully disconnected session SID $sidToKill.";
            } catch (Exception $e) {
                $message = "Error disconnecting session: " . htmlspecialchars($e->getMessage());
            }
            oci_free_statement($stid_kill);
        } else {
            $message = "Session SID $sidToKill not found.";
        }
    }
}

/**
 * Fetch sessions with locks
 */
$sql = "
SELECT 
    s.sid,
    s.serial#,
    s.username AS \"User\",
    DECODE(l.TYPE,
        'TM', 'DML Enqueue',
        'TX', 'Transaction',
        'UL', 'User Lock',
        l.TYPE) AS \"Lock Type\",
    DECODE(l.lmode,
        0, 'None', 1, 'Null', 2, 'Row-S (SS)',
        3, 'Row-X (SX)', 4, 'Share',
        5, 'S/Row-X (SSX)', 6, 'Exclusive', TO_CHAR(l.lmode)) AS \"Mode Held\",
    DECODE(l.request,
        0, 'None', 1, 'Null', 2, 'Row-S (SS)',
        3, 'Row-X (SX)', 4, 'Share',
        5, 'S/Row-X (SSX)', 6, 'Exclusive', TO_CHAR(l.request)) AS \"Mode Requested\",
    o.owner AS \"Owner\",
    o.object_type AS \"Object Type\",
    o.object_name AS \"Object Name\",
    l.block AS \"Blocking\",
    l.sid AS \"Session Blocked\",
    s.osuser AS \"OS User\",
    s.machine AS \"Machine Name\"
FROM 
    v\$lock l
JOIN 
    v\$session s ON l.sid = s.sid
LEFT JOIN 
    dba_objects o ON l.id1 = o.object_id
WHERE 
    l.block = 1
ORDER BY 
    l.block DESC, s.sid
";

// Prepare and execute the query
$stid = oci_parse($conn, $sql);
oci_execute($stid);
$rows = [];
while (($row = oci_fetch_assoc($stid)) !== false) {
    $rows[] = $row;
}
oci_free_statement($stid);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Oracle Lock Sessions</title>
<!-- Bootstrap CSS CDN -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body>
<div class="container my-4">
    <!-- Header with title and Refresh button -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Oracle Lock Sessions</h2>
        <!-- Refresh button -->
        <button class="btn btn-secondary" onclick="location.reload();">Refresh</button>
    </div>
    <p>Coded by p.velante@gmail.com</p>

    <?php if ($message): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-striped table-bordered table-hover">
            <thead class="table-dark">
            <tr>
                <th>SID</th>
                <th>Serial#</th>
                <th>User</th>
                <th>Lock Type</th>
                <th>Mode Held</th>
                <th>Mode Requested</th>
                <th>Owner</th>
                <th>Object Type</th>
                <th>Object Name</th>
                <th>Blocking</th>
                <th>Session Blocked</th>
                <th>OS User</th>
                <th>Machine Name</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (count($rows) > 0): ?>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['SID']) ?></td>
                <td><?= htmlspecialchars($row['SERIAL#']) ?></td>
                <td><?= htmlspecialchars($row['User']) ?></td>
                <td><?= htmlspecialchars($row['Lock Type']) ?></td>
                <td><?= htmlspecialchars($row['Mode Held']) ?></td>
                <td><?= htmlspecialchars($row['Mode Requested']) ?></td>
                <td><?= htmlspecialchars($row['Owner']) ?></td>
                <td><?= htmlspecialchars($row['Object Type']) ?></td>
                <td><?= htmlspecialchars($row['Object Name']) ?></td>
                <td><?= htmlspecialchars($row['Blocking']) ?></td>
                <td><?= htmlspecialchars($row['Session Blocked']) ?></td>
                <td><?= htmlspecialchars($row['OS User']) ?></td>
                <td><?= htmlspecialchars($row['Machine Name']) ?></td>
                <td>
                    <form method="post" style="display:inline-block;">
                        <input type="hidden" name="sid" value="<?= htmlspecialchars($row['SID']) ?>" />
                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to disconnect session SID <?= htmlspecialchars($row['SID']) ?>?');">
                            Disconnect
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <tr>
                <td colspan="14" class="text-center">No active locks found.</td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<!-- Bootstrap JS CDN -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>