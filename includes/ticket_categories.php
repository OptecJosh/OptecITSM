<?php
/**
 * Category ↔ ticket-type scoping (12c).
 *
 * A category may belong to ONE ticket type, or to none:
 *
 *   ticket_categories.ticket_type_id = N     → offered only on tickets of type N
 *   ticket_categories.ticket_type_id IS NULL → offered on EVERY type
 *
 * NULL meaning "every type" is the whole reason the migration is free. Every
 * category that existed before this column keeps working on every ticket until
 * somebody deliberately narrows it, so nothing has to be duplicated per type on
 * day one and no existing ticket becomes invalid.
 *
 * Fourteen surfaces read categories — the reading pane, create-ticket, settings,
 * filters, reports, export, import, migration and the self-service catalogue
 * among them. They share the rule from here rather than each spelling out the
 * same OR, because a surface that forgets the NULL half silently hides every
 * unscoped category and a surface that forgets the scope offers categories that
 * do not belong.
 */

/**
 * Does ticket_categories.ticket_type_id exist yet? Code always deploys before
 * Database Verify runs, so every reader has to cope with the gap rather than
 * 500 in it. Until the column lands, scoping is simply inert: every category is
 * offered on every type, which is exactly the pre-12c behaviour.
 */
function ticketCategoryTypeColumnExists(PDO $conn): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM ticket_categories LIKE 'ticket_type_id'");
        $has = $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    } catch (Exception $e) {
        $has = false;
    }
    return $has;
}

/**
 * A SQL fragment restricting categories to those valid for $typeId, for use as
 * an AND-ed condition. Returns '' when it cannot or should not filter:
 *
 *   - the column does not exist yet (pre-Database-Verify)
 *   - no type is in play, e.g. a report grouping every category
 *
 * '' means "no restriction", so callers can concatenate it unconditionally.
 * $alias qualifies the column where the query joins more than one table.
 */
function ticketCategoryTypeWhere(PDO $conn, ?int $typeId, string $alias = ''): string {
    if (!ticketCategoryTypeColumnExists($conn)) return '';
    if ($typeId === null || $typeId <= 0) return '';
    $col = ($alias !== '' ? $alias . '.' : '') . 'ticket_type_id';
    // The NULL half is not optional: without it a type-scoped list would drop
    // every category that is meant to be available everywhere.
    return "($col IS NULL OR $col = " . (int)$typeId . ")";
}

/**
 * May $categoryId be used on a ticket of type $typeId?
 *
 * True when there is no category, no type, or the category is unscoped — the
 * question only has a real answer when both sides are known and the category is
 * narrowed. An unknown category id is treated as fitting: this guards a UI
 * choice, and it is not this function's job to reject rows it cannot see.
 */
function ticketCategoryFitsType(PDO $conn, ?int $categoryId, ?int $typeId): bool {
    if (!$categoryId || !$typeId) return true;
    if (!ticketCategoryTypeColumnExists($conn)) return true;
    try {
        $stmt = $conn->prepare("SELECT ticket_type_id FROM ticket_categories WHERE id = ?");
        $stmt->execute([$categoryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return true;
        if ($row['ticket_type_id'] === null) return true;
        return (int)$row['ticket_type_id'] === (int)$typeId;
    } catch (Exception $e) {
        return true;
    }
}

/**
 * The ticket type a category is scoped to, or null for "every type" (and for a
 * category that does not exist, or a schema that has not caught up).
 */
function ticketCategoryTypeId(PDO $conn, ?int $categoryId): ?int {
    if (!$categoryId) return null;
    if (!ticketCategoryTypeColumnExists($conn)) return null;
    try {
        $stmt = $conn->prepare("SELECT ticket_type_id FROM ticket_categories WHERE id = ?");
        $stmt->execute([$categoryId]);
        $val = $stmt->fetchColumn();
        return ($val === false || $val === null) ? null : (int)$val;
    } catch (Exception $e) {
        return null;
    }
}
