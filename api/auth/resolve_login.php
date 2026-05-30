<?php
/**
 * API: Resolve an email to a login method (for the email-first login router).
 * POST JSON { email }
 *
 * Returns { mode: 'sso', provider_id, provider_name } when the email belongs
 * to an active analyst assigned to an enabled provider (and SSO is on);
 * otherwise { mode: 'local' }.
 *
 * Deliberately returns 'local' for unknown emails too, so this endpoint does
 * not reveal whether a given email has an account.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

$data  = json_decode(file_get_contents('php://input'), true);
$email = strtolower(trim($data['email'] ?? ''));

$resp = ['mode' => 'local'];

if ($email !== '') {
    try {
        $conn = connectToDatabase();
        $ssoOn = (($conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'sso_enabled'")->fetchColumn()) ?: '0') === '1';
        if ($ssoOn) {
            $stmt = $conn->prepare(
                "SELECT a.auth_provider_id, p.display_name
                   FROM analysts a
                   JOIN auth_providers p ON p.id = a.auth_provider_id
                  WHERE LOWER(a.email) = ? AND a.is_active = 1 AND p.enabled = 1
                  LIMIT 1"
            );
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $resp = [
                    'mode'          => 'sso',
                    'provider_id'   => (int)$row['auth_provider_id'],
                    'provider_name' => $row['display_name'],
                ];
            }
        }
    } catch (Exception $e) {
        // Any failure -> fall back to local login.
    }
}

echo json_encode($resp);
