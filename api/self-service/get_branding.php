<?php
/**
 * API (self-service): the portal branding for the signed-in user (Phase 7e).
 *
 * A portal user isn't stored against a company, so their company is resolved the
 * same way inbound mail is — by their email domain (tenant_domains). If that
 * company has set any branding, it's returned; otherwise the portal uses its
 * built-in defaults. Always succeeds with branding = null when nothing applies.
 *
 * GET → { success, branding: null | { brand_color, portal_name, portal_welcome } }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $uid = (int)$_SESSION['ss_user_id'];

    $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $email = (string)$stmt->fetchColumn();

    $branding = null;
    $domain = '';
    if ($email !== '' && strpos($email, '@') !== false) {
        $domain = strtolower(trim(substr(strrchr($email, '@'), 1)));
    }

    if ($domain !== '') {
        try {
            $bStmt = $conn->prepare(
                "SELECT t.brand_color, t.portal_name, t.portal_welcome
                   FROM tenant_domains td
                   JOIN tenants t ON t.id = td.tenant_id
                  WHERE td.domain = ? AND t.is_active = 1
                  LIMIT 1"
            );
            $bStmt->execute([$domain]);
            $row = $bStmt->fetch(PDO::FETCH_ASSOC);
            // Only return branding if the company actually set something.
            if ($row && ($row['brand_color'] || $row['portal_name'] || $row['portal_welcome'])) {
                $branding = [
                    'brand_color'    => $row['brand_color'] ?: null,
                    'portal_name'    => $row['portal_name'] ?: null,
                    'portal_welcome' => $row['portal_welcome'] ?: null,
                ];
            }
        } catch (Exception $e) {
            // tenant_domains / branding columns not present yet → defaults
            $branding = null;
        }
    }

    echo json_encode(['success' => true, 'branding' => $branding]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
