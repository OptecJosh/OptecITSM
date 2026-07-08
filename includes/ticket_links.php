<?php
/**
 * Ticket-to-ticket linking (issue #38). Self-referential, typed links stored in
 * `ticket_links`. Three stored relation types:
 *   'related'   — symmetric (order irrelevant; reciprocal duplicates blocked)
 *   'duplicate' — source is a DUPLICATE OF target (target is the master)
 *   'parent'    — source is the PARENT OF target (target is the child)
 *
 * The UI expresses direction from the acting ticket's point of view with four
 * choices (related / duplicate_of / parent_of / child_of); we canonicalise to
 * the three stored types (child_of is stored as the inverse 'parent').
 *
 * v1 is informational only — links display and are audited, nothing changes
 * ticket state automatically. Multi-tenancy: links are same-company only.
 */

require_once __DIR__ . '/tenancy.php';

/** Load the minimal ticket row we need (or null if missing/deleted). */
function ticketLinkLoad(PDO $conn, int $id): ?array
{
    $st = $conn->prepare("SELECT id, ticket_number, tenant_id FROM tickets WHERE id = ? AND deleted_datetime IS NULL");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ticketLinkNum(PDO $conn, int $id): string
{
    $st = $conn->prepare("SELECT ticket_number FROM tickets WHERE id = ?");
    $st->execute([$id]);
    return $st->fetchColumn() ?: ('#' . $id);
}

function ticketLinkAudit(PDO $conn, int $ticketId, int $analystId, string $field, string $newVal): void
{
    $conn->prepare(
        "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
         VALUES (?, ?, ?, NULL, ?, UTC_TIMESTAMP())"
    )->execute([$ticketId, $analystId, $field, mb_substr($newVal, 0, 500)]);
}

/**
 * Create a link. $uiRelation is one of related|duplicate_of|parent_of|child_of,
 * expressed from $sourceId's perspective. Returns ['success'=>bool, 'error'=>?].
 */
function ticketLinkCreate(PDO $conn, int $analystId, int $sourceId, int $targetId, string $uiRelation): array
{
    if ($sourceId <= 0 || $targetId <= 0) {
        return ['success' => false, 'error' => 'Both tickets are required.'];
    }
    if ($sourceId === $targetId) {
        return ['success' => false, 'error' => "A ticket can't be linked to itself."];
    }

    // Map the UI relation to a stored (type, canonical source/target).
    $map = [
        'related'      => ['type' => 'related',   'swap' => false],
        'duplicate_of' => ['type' => 'duplicate', 'swap' => false], // source is a dup of target
        'parent_of'    => ['type' => 'parent',    'swap' => false], // source is parent of target
        'child_of'     => ['type' => 'parent',    'swap' => true],  // target is parent of source
    ];
    if (!isset($map[$uiRelation])) {
        return ['success' => false, 'error' => 'Invalid relation type.'];
    }
    $type = $map[$uiRelation]['type'];
    $s = $sourceId;
    $t = $targetId;
    if ($map[$uiRelation]['swap']) { $s = $targetId; $t = $sourceId; }

    // The actor must be able to see BOTH tickets.
    if (!analystCanAccessTicket($conn, $analystId, $sourceId) || !analystCanAccessTicket($conn, $analystId, $targetId)) {
        return ['success' => false, 'error' => 'Ticket not found.'];
    }
    $a = ticketLinkLoad($conn, $sourceId);
    $b = ticketLinkLoad($conn, $targetId);
    if (!$a || !$b) {
        return ['success' => false, 'error' => 'Ticket not found.'];
    }

    // Same-company only (NULL normalises to Default).
    if (isMultiTenant($conn)) {
        $def = getDefaultTenantId($conn);
        $at = $a['tenant_id'] === null ? $def : (int)$a['tenant_id'];
        $bt = $b['tenant_id'] === null ? $def : (int)$b['tenant_id'];
        if ($at !== $bt) {
            return ['success' => false, 'error' => 'Those tickets belong to different companies.'];
        }
    }

    // Relationship-specific guards (operate on the canonical $s/$t).
    if ($type === 'parent') {
        // The child ($t) may have at most one parent.
        $st = $conn->prepare("SELECT source_ticket_id FROM ticket_links WHERE target_ticket_id = ? AND relation_type = 'parent'");
        $st->execute([$t]);
        $existingParent = $st->fetchColumn();
        if ($existingParent) {
            return ['success' => false, 'error' => ((int)$existingParent === $s)
                ? 'These tickets are already linked as parent and child.'
                : 'That child ticket already has a parent — remove the existing parent link first.'];
        }
        // Block a direct loop ($s is already a child of $t).
        $cy = $conn->prepare("SELECT id FROM ticket_links WHERE source_ticket_id = ? AND target_ticket_id = ? AND relation_type = 'parent'");
        $cy->execute([$t, $s]);
        if ($cy->fetchColumn()) {
            return ['success' => false, 'error' => 'That would create a parent/child loop.'];
        }
    } elseif ($type === 'duplicate') {
        // A ticket ($s) may be a duplicate of at most one master.
        $st = $conn->prepare("SELECT target_ticket_id FROM ticket_links WHERE source_ticket_id = ? AND relation_type = 'duplicate'");
        $st->execute([$s]);
        $existingMaster = $st->fetchColumn();
        if ($existingMaster) {
            return ['success' => false, 'error' => ((int)$existingMaster === $t)
                ? 'This ticket is already marked a duplicate of that ticket.'
                : 'This ticket is already marked a duplicate of another ticket — remove that link first.'];
        }
    } else { // related — block the reciprocal too
        $rc = $conn->prepare(
            "SELECT id FROM ticket_links WHERE relation_type = 'related'
             AND ((source_ticket_id = ? AND target_ticket_id = ?) OR (source_ticket_id = ? AND target_ticket_id = ?))"
        );
        $rc->execute([$s, $t, $t, $s]);
        if ($rc->fetchColumn()) {
            return ['success' => false, 'error' => 'These tickets are already linked as related.'];
        }
    }

    // Exact-duplicate guard (the unique key is the backstop).
    $dup = $conn->prepare("SELECT id FROM ticket_links WHERE source_ticket_id = ? AND target_ticket_id = ? AND relation_type = ?");
    $dup->execute([$s, $t, $type]);
    if ($dup->fetchColumn()) {
        return ['success' => false, 'error' => 'These tickets are already linked.'];
    }

    $conn->prepare(
        "INSERT INTO ticket_links (source_ticket_id, target_ticket_id, relation_type, created_by_id, created_datetime)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP())"
    )->execute([$s, $t, $type, $analystId]);

    // Audit both tickets, each from its own perspective.
    $sNum = ticketLinkNum($conn, $s);
    $tNum = ticketLinkNum($conn, $t);
    if ($type === 'parent') {
        ticketLinkAudit($conn, $s, $analystId, 'Linked ticket', 'Parent of ' . $tNum);
        ticketLinkAudit($conn, $t, $analystId, 'Linked ticket', 'Child of ' . $sNum);
    } elseif ($type === 'duplicate') {
        ticketLinkAudit($conn, $s, $analystId, 'Linked ticket', 'Duplicate of ' . $tNum);
        ticketLinkAudit($conn, $t, $analystId, 'Linked ticket', 'Duplicated by ' . $sNum);
    } else {
        ticketLinkAudit($conn, $s, $analystId, 'Linked ticket', 'Related to ' . $tNum);
        ticketLinkAudit($conn, $t, $analystId, 'Linked ticket', 'Related to ' . $sNum);
    }

    return ['success' => true];
}

/** Remove a link by id. */
function ticketLinkRemove(PDO $conn, int $analystId, int $linkId): array
{
    $st = $conn->prepare("SELECT source_ticket_id, target_ticket_id FROM ticket_links WHERE id = ?");
    $st->execute([$linkId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['success' => false, 'error' => 'Link not found.'];
    }
    $s = (int)$row['source_ticket_id'];
    $t = (int)$row['target_ticket_id'];
    // Same-company on create guarantees both sides share a company, so access to
    // either endpoint is sufficient authority to unlink.
    if (!analystCanAccessTicket($conn, $analystId, $s) && !analystCanAccessTicket($conn, $analystId, $t)) {
        return ['success' => false, 'error' => 'Not permitted.'];
    }
    $conn->prepare("DELETE FROM ticket_links WHERE id = ?")->execute([$linkId]);
    $sNum = ticketLinkNum($conn, $s);
    $tNum = ticketLinkNum($conn, $t);
    ticketLinkAudit($conn, $s, $analystId, 'Unlinked ticket', $tNum);
    ticketLinkAudit($conn, $t, $analystId, 'Unlinked ticket', $sNum);
    return ['success' => true];
}

/**
 * All of a ticket's links, grouped for display from THIS ticket's perspective.
 * Returns parent (0/1), children[], duplicate_of (0/1), duplicates[], related[].
 */
function ticketLinksFor(PDO $conn, int $ticketId): array
{
    $out = ['parent' => null, 'children' => [], 'duplicate_of' => null, 'duplicates' => [], 'related' => []];
    $sql = "SELECT tl.id AS link_id, tl.source_ticket_id, tl.target_ticket_id, tl.relation_type,
                   t.id AS other_id, t.ticket_number, t.subject, s.name AS status
            FROM ticket_links tl
            JOIN tickets t ON t.id = CASE WHEN tl.source_ticket_id = :tid THEN tl.target_ticket_id ELSE tl.source_ticket_id END
            LEFT JOIN ticket_statuses s ON s.id = t.status_id
            WHERE (tl.source_ticket_id = :tid OR tl.target_ticket_id = :tid)
              AND t.deleted_datetime IS NULL
            ORDER BY tl.created_datetime";
    $st = $conn->prepare($sql);
    $st->execute([':tid' => $ticketId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $isSource = ((int)$r['source_ticket_id'] === $ticketId);
        $item = [
            'link_id'       => (int)$r['link_id'],
            'ticket_id'     => (int)$r['other_id'],
            'ticket_number' => $r['ticket_number'],
            'subject'       => $r['subject'],
            'status'        => $r['status'],
        ];
        switch ($r['relation_type']) {
            case 'parent':
                if ($isSource) { $out['children'][] = $item; } // this is the parent
                else { $out['parent'] = $item; }               // this is the child
                break;
            case 'duplicate':
                if ($isSource) { $out['duplicate_of'] = $item; } // this is a dup of the master
                else { $out['duplicates'][] = $item; }           // the other is a dup of this
                break;
            default:
                $out['related'][] = $item;
        }
    }
    return $out;
}

/** Ticket search for the link picker — scoped to the source ticket's company. */
function ticketLinkableList(PDO $conn, int $analystId, int $sourceId, string $q): array
{
    $source = ticketLinkLoad($conn, $sourceId);
    if (!$source || !analystCanAccessTicket($conn, $analystId, $sourceId)) {
        return ['success' => false, 'error' => 'Ticket not found.'];
    }
    $params = [':src' => $sourceId];
    $where = "t.id != :src AND t.deleted_datetime IS NULL";

    if (isMultiTenant($conn)) {
        $def = getDefaultTenantId($conn);
        $stid = $source['tenant_id'] === null ? $def : (int)$source['tenant_id'];
        // The Default company also owns NULL-tenant tickets.
        $where .= ($stid === $def) ? " AND (t.tenant_id = :tid OR t.tenant_id IS NULL)" : " AND t.tenant_id = :tid";
        $params[':tid'] = $stid;
    }
    if ($q !== '') {
        $where .= " AND (t.ticket_number LIKE :q OR t.subject LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $sql = "SELECT t.id, t.ticket_number, t.subject, s.name AS status
            FROM tickets t LEFT JOIN ticket_statuses s ON s.id = t.status_id
            WHERE $where ORDER BY t.id DESC LIMIT 50";
    $st = $conn->prepare($sql);
    $st->execute($params);
    return ['success' => true, 'tickets' => $st->fetchAll(PDO::FETCH_ASSOC)];
}
