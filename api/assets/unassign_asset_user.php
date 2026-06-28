<?php
/**
 * API Endpoint: Remove a user from an asset
 * Deletes the users_assets record
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

$assetId = $data['asset_id'] ?? null;
$userId = $data['user_id'] ?? null;
$skipAudit = $data['skip_audit'] ?? false;

if (!$assetId || !$userId) {
    echo json_encode(['success' => false, 'error' => 'Asset ID and User ID are required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Snapshot the assignment (holder name + due-back) before removing it, for
    // the custody trail and audit log.
    $snapStmt = $conn->prepare("SELECT u.display_name, ua.expected_return_date
                                FROM users_assets ua INNER JOIN users u ON u.id = ua.user_id
                                WHERE ua.asset_id = ? AND ua.user_id = ?");
    $snapStmt->execute([$assetId, $userId]);
    $snap = $snapStmt->fetch(PDO::FETCH_ASSOC);
    $userName = $snap ? $snap['display_name'] : $userId;
    $expectedReturn = $snap ? $snap['expected_return_date'] : null;

    // Delete the assignment
    $sql = "DELETE FROM users_assets WHERE asset_id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$assetId, $userId]);

    if ($stmt->rowCount() > 0) {
        // Record the check-in in the custody trail — always, even on re-assign,
        // so the previous holder's return is captured. Best-effort.
        try {
            $clog = $conn->prepare("INSERT INTO asset_checkout_log (asset_id, user_id, user_name, action, expected_return_date, analyst_id, action_datetime)
                                    VALUES (?, ?, ?, 'checkin', ?, ?, UTC_TIMESTAMP())");
            $clog->execute([$assetId, $userId, $userName, $expectedReturn, $_SESSION['analyst_id']]);
        } catch (Exception $clogEx) { /* custody log not critical */ }

        // Log to asset_history (skip if this is part of a re-assign, the assign endpoint will log it)
        if (!$skipAudit) {
            $auditSql = "INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime)
                         VALUES (?, ?, 'assigned_user', ?, NULL, UTC_TIMESTAMP())";
            $auditStmt = $conn->prepare($auditSql);
            $auditStmt->execute([$assetId, $_SESSION['analyst_id'], $userName]);
        }

        echo json_encode([
            'success' => true,
            'message' => 'User removed from asset successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Assignment not found'
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
