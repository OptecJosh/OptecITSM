<?php
/**
 * RBAC Layer 2 — per-module SETTINGS permissions.
 *
 * Layer 1 (includes/functions.php: requireModuleAccess) decides which modules an
 * analyst can ENTER. This file is Layer 2: once in a module, may they also
 * ADMINISTER its settings? See docs/design/rbac.md for the full design.
 *
 * Three rules, all enforced here:
 *   - DENY BY DEFAULT. No grant → no settings access. (Opposite of Layer 1's
 *     "absence = everything", and the right default for an admin surface.)
 *   - is_admin BYPASSES the whole layer. A System administrator implicitly holds
 *     every capability, which is what makes deny-by-default safe on upgrade —
 *     the instance owner keeps everything, and the tightening only bites
 *     non-admins (who mostly shouldn't be in settings anyway).
 *   - ENFORCED SERVER-SIDE, page AND api, failing closed — Layer 1's mistake was
 *     hiding cards without enforcing, so a "restricted" analyst could type the URL.
 *
 * A capability key is '<module>.<action>' and MUST appear in rbacCapabilities()
 * below. The DB never stores a capability the code doesn't know: the registry is
 * the source of truth, the tables only reference it.
 */

require_once __DIR__ . '/functions.php';

/**
 * The capability registry — every administrative capability the app defines,
 * grouped by module. THIS IS THE SOURCE OF TRUTH. A capability that isn't here
 * cannot be granted (the System UI won't offer it) and, if somehow present in
 * the DB, is ignored by rbacCapabilityExists().
 *
 * Rollout (docs/design/rbac.md §8) adds one '<module>.manage' per module as each
 * module's settings surface is moved behind it. Only the LMS is declared now,
 * because it is the pilot; the others land as they are wired.
 *
 * @return array<string,array{label:string,capabilities:array<string,string>}>
 */
function rbacCapabilities(): array {
    return [
        'lms' => [
            'label' => 'LMS',
            'capabilities' => [
                'lms.manage' => 'Manage courses, learning groups and assignments, and view everyone\'s progress',
            ],
        ],
        // Further modules declared here as their settings surfaces are moved
        // behind a capability (phase 3). e.g. 'tickets' => 'tickets.manage_settings'.
    ];
}

/** Flat list of every valid capability key. */
function rbacAllCapabilityKeys(): array {
    $keys = [];
    foreach (rbacCapabilities() as $group) {
        foreach ($group['capabilities'] as $key => $_label) $keys[] = $key;
    }
    return $keys;
}

/** Is this a capability the code actually defines? Guards writes against typos/stale rows. */
function rbacCapabilityExists(string $key): bool {
    return in_array($key, rbacAllCapabilityKeys(), true);
}

/** Human label for a capability key, or the key itself if unknown. */
function rbacCapabilityLabel(string $key): string {
    foreach (rbacCapabilities() as $group) {
        if (isset($group['capabilities'][$key])) return $group['capabilities'][$key];
    }
    return $key;
}

/**
 * Every capability an analyst effectively holds, as a flat list of keys.
 *
 * is_admin short-circuits to ALL declared capabilities. Otherwise it's the union
 * of the capabilities granted by the roles assigned to the analyst directly and
 * by the roles assigned to any team they belong to — one query, one choke-point,
 * the same individual-plus-team-unioned shape as module and company access.
 * Only capabilities that still exist in the registry are returned, so retiring a
 * capability in code retires it everywhere without a data migration.
 *
 * @return array<int,string>
 */
function getAnalystCapabilities(PDO $conn, int $analystId): array {
    if ($analystId <= 0) return [];
    if (analystIsAdmin($conn, $analystId)) return rbacAllCapabilityKeys();

    $sql = "SELECT DISTINCT rc.capability_key
            FROM rbac_role_capabilities rc
            JOIN rbac_roles r ON r.id = rc.role_id AND r.is_active = 1
            WHERE rc.role_id IN (
                SELECT role_id FROM rbac_analyst_roles WHERE analyst_id = ?
                UNION
                SELECT tr.role_id FROM rbac_team_roles tr
                JOIN analyst_teams at ON at.team_id = tr.team_id
                WHERE at.analyst_id = ?
            )";
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$analystId, $analystId]);
        $granted = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return []; // fail closed — a broken query must not grant access
    }

    // Intersect with the registry so a stale/retired capability can never apply.
    $valid = rbacAllCapabilityKeys();
    return array_values(array_intersect($granted, $valid));
}

/**
 * Does this analyst hold a capability? Authoritative (DB-checked). is_admin is
 * always true. Use this for server-side enforcement.
 */
function analystHasCapability(PDO $conn, int $analystId, string $capability): bool {
    if ($analystId <= 0) return false;
    if (analystIsAdmin($conn, $analystId)) return true;
    return in_array($capability, getAnalystCapabilities($conn, $analystId), true);
}

/**
 * Page gate: bounce an analyst who lacks $capability back to the launcher, so a
 * settings URL can't simply be typed. Authoritative (DB-checked), fails closed.
 * Call after functions.php is loaded and the login check. Pair with (or place
 * after) requireModuleAccess() — Layer 1 gets you into the module, this decides
 * whether you may configure it.
 */
function requireCapability(string $capability, ?PDO $conn = null): void {
    if (!isset($_SESSION['analyst_id'])) {
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
    try {
        if ($conn === null) $conn = connectToDatabase();
        $ok = analystHasCapability($conn, (int) $_SESSION['analyst_id'], $capability);
    } catch (Throwable $e) {
        $ok = false;
    }
    if (!$ok) {
        header('Location: ' . BASE_URL . '?denied=' . urlencode($capability));
        exit;
    }
}

/**
 * Hard gate for a settings JSON write API: refuse a lacking analyst with 403.
 * The API twin of requireCapability(). Call right after connecting.
 */
function requireCapabilityJson(string $capability, ?PDO $conn = null): void {
    if (!isset($_SESSION['analyst_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    try {
        if ($conn === null) $conn = connectToDatabase();
        $ok = analystHasCapability($conn, (int) $_SESSION['analyst_id'], $capability);
    } catch (Throwable $e) {
        $ok = false;
    }
    if (!$ok) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'You do not have permission to manage these settings']);
        exit;
    }
}
