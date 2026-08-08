<?php
/**
 * API: Create or update an SLA policy.
 *
 * POST JSON { id?, name, description?, is_default?, is_active? }
 *
 * Exactly one policy is the default (the fallback for companies with no
 * assignment), so setting is_default here clears it on every other policy.
 *
 * Two invariants, both because sla_resolve_policy() reads the default with
 * `is_default = 1 AND is_active = 1`:
 *   - the default is always active (is_default = 1 forces is_active = 1);
 *   - there is always exactly one, so the default moves by promoting another
 *     policy rather than by switching this one off.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_SLA);   // settings tab — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id          = !empty($data['id']) ? (int)$data['id'] : null;
    $name        = trim((string)($data['name'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $isDefault   = !empty($data['is_default']) ? 1 : 0;
    $isActive    = array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1;

    if ($name === '') throw new Exception('Name is required');

    $conn = connectToDatabase();

    // Name must be unique.
    $clashSql = "SELECT id FROM sla_policies WHERE LOWER(name) = LOWER(?)" . ($id ? " AND id <> ?" : "");
    $cs = $conn->prepare($clashSql);
    $cs->execute($id ? [$name, $id] : [$name]);
    if ($cs->fetch()) throw new Exception('An SLA policy with that name already exists');

    // The default has to be active, because sla_resolve_policy() looks it up with
    // `is_default = 1 AND is_active = 1`. An inactive default matches nothing, so
    // every company without its own assignment silently loses SLA entirely — no
    // targets, no breach warnings, no error anywhere. This used to be reachable:
    // the "must stay active" rule below only fired for a policy that was ALREADY
    // the default, so promoting an inactive policy (or adding one with Default on
    // and Active off) produced exactly that dead install.
    if ($isDefault) $isActive = 1;

    // The default policy must stay the default — it can only move by promoting
    // another policy, never by switching this one off and leaving none.
    if ($id) {
        $cur = $conn->prepare("SELECT is_default FROM sla_policies WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('SLA policy not found');
        if ((int)$row['is_default'] === 1 && !$isDefault) {
            throw new Exception('Make another policy the default first — one policy must always be the fallback.');
        }
    }

    $conn->beginTransaction();
    if ($isDefault) {
        // Only one default; clear the rest first.
        $conn->prepare("UPDATE sla_policies SET is_default = 0 WHERE id <> ?")->execute([$id ?: 0]);
    }

    if ($id) {
        $conn->prepare("UPDATE sla_policies SET name = ?, description = ?, is_default = ?, is_active = ? WHERE id = ?")
             ->execute([$name, $description !== '' ? $description : null, $isDefault, $isActive, $id]);
        $newId = $id;
    } else {
        $conn->prepare("INSERT INTO sla_policies (name, description, is_default, is_active, created_datetime) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$name, $description !== '' ? $description : null, $isDefault, $isActive]);
        $newId = (int)$conn->lastInsertId();
    }

    // Never leave the install without a default policy.
    $hasDefault = (int) $conn->query("SELECT COUNT(*) FROM sla_policies WHERE is_default = 1")->fetchColumn();
    if ($hasDefault === 0) {
        $conn->prepare("UPDATE sla_policies SET is_default = 1, is_active = 1 WHERE id = ?")->execute([$newId]);
    }
    $conn->commit();

    wf_emit('sla_policy', $id ? 'updated' : 'created', $newId, $name);
    echo json_encode(['success' => true, 'id' => $newId]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
