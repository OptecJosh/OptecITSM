<?php
/**
 * Customer contacts (13a).
 *
 * A customer may have many contacts, exactly one of which is its default.
 *
 * THE MIRROR IS THE POINT. customers.contact_name / contact_email /
 * contact_phone are not retired — they are rewritten from whichever contact is
 * default, every time that could change. The ticket picker, the exports, the
 * demo CSVs, the importer and the v1 API all keep reading the column they
 * already read, so adding a contacts table cost those surfaces nothing. Every
 * write path in this module therefore ends in customerContactsSyncDefault().
 *
 * "Exactly one default" is enforced here rather than by a unique key, because
 * MySQL has no partial index and UNIQUE (customer_id, is_default) would allow
 * only one NON-default contact per customer — the opposite of what is wanted.
 */

/**
 * Has Database Verify created the table yet? Code always deploys first, so every
 * reader has to cope with the gap. Until it exists, the customer page shows the
 * single inline contact exactly as it did before 13a.
 */
function customerContactsTableExists(PDO $conn): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $has = $conn->query("SHOW TABLES LIKE 'customer_contacts'")->fetch() !== false;
    } catch (Exception $e) {
        $has = false;
    }
    return $has;
}

/** Does tickets.customer_contact_id exist yet? Same reasoning. */
function ticketCustomerContactColumnExists(PDO $conn): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $has = $conn->query("SHOW COLUMNS FROM tickets LIKE 'customer_contact_id'")->fetch() !== false;
    } catch (Exception $e) {
        $has = false;
    }
    return $has;
}

/**
 * Rewrite customers.contact_* from this customer's default contact.
 *
 * Call after ANY change to a customer's contacts — create, edit, delete,
 * activate, deactivate, or a change of which one is default. When there is no
 * usable default the mirror is left exactly as it was rather than blanked: a
 * customer whose only contact was deleted should not silently lose the contact
 * details that every other surface still reads.
 */
function customerContactsSyncDefault(PDO $conn, int $customerId): void {
    if ($customerId <= 0 || !customerContactsTableExists($conn)) return;
    try {
        $stmt = $conn->prepare(
            "SELECT name, email, phone FROM customer_contacts
              WHERE customer_id = ? AND is_default = 1 AND is_active = 1
           ORDER BY id LIMIT 1"
        );
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        $conn->prepare(
            "UPDATE customers SET contact_name = ?, contact_email = ?, contact_phone = ?,
                    updated_datetime = UTC_TIMESTAMP()
              WHERE id = ?"
        )->execute([$row['name'], $row['email'], $row['phone'], $customerId]);
    } catch (Exception $e) {
        error_log('[customer-contacts] sync: ' . $e->getMessage());
    }
}

/**
 * Make one contact the customer's default, demoting any other.
 * Returns false when the contact does not belong to that customer.
 */
function customerContactsSetDefault(PDO $conn, int $customerId, int $contactId): bool {
    if (!customerContactsTableExists($conn)) return false;
    try {
        $chk = $conn->prepare("SELECT id FROM customer_contacts WHERE id = ? AND customer_id = ?");
        $chk->execute([$contactId, $customerId]);
        if (!$chk->fetch()) return false;

        $conn->prepare("UPDATE customer_contacts SET is_default = 0 WHERE customer_id = ? AND id <> ?")
             ->execute([$customerId, $contactId]);
        $conn->prepare("UPDATE customer_contacts SET is_default = 1, is_active = 1, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$contactId]);

        customerContactsSyncDefault($conn, $customerId);
        return true;
    } catch (Exception $e) {
        error_log('[customer-contacts] set default: ' . $e->getMessage());
        return false;
    }
}

/**
 * Make sure the customer still has a default, promoting the oldest active
 * contact if the previous one has just gone. A customer with contacts but no
 * default would stop mirroring, and every surface reading customers.contact_*
 * would quietly freeze on stale details.
 */
function customerContactsEnsureDefault(PDO $conn, int $customerId): void {
    if (!customerContactsTableExists($conn)) return;
    try {
        $cur = $conn->prepare("SELECT id FROM customer_contacts WHERE customer_id = ? AND is_default = 1 AND is_active = 1 LIMIT 1");
        $cur->execute([$customerId]);
        if ($cur->fetch()) return;

        $next = $conn->prepare("SELECT id FROM customer_contacts WHERE customer_id = ? AND is_active = 1 ORDER BY id LIMIT 1");
        $next->execute([$customerId]);
        $id = $next->fetchColumn();
        if ($id !== false) {
            customerContactsSetDefault($conn, $customerId, (int)$id);
        }
    } catch (Exception $e) {
        error_log('[customer-contacts] ensure default: ' . $e->getMessage());
    }
}

/**
 * Push the customer form's inline contact fields INTO the contacts table.
 *
 * The mirror has to work both ways or it desynchronises the first time someone
 * edits a customer. The customer form still shows one set of contact fields, and
 * those fields ARE the default contact's — so editing them here edits that
 * contact, and a customer that has no contacts yet gets one created from them.
 *
 * Call at the end of a customer save, after customers.contact_* is written.
 */
function customerContactsAdoptInline(PDO $conn, int $customerId, ?int $createdByAnalystId = null): void {
    if ($customerId <= 0 || !customerContactsTableExists($conn)) return;
    try {
        $c = $conn->prepare("SELECT name, contact_name, contact_email, contact_phone FROM customers WHERE id = ?");
        $c->execute([$customerId]);
        $cust = $c->fetch(PDO::FETCH_ASSOC);
        if (!$cust) return;

        $inlineName  = trim((string)($cust['contact_name'] ?? ''));
        $inlineEmail = trim((string)($cust['contact_email'] ?? '')) ?: null;
        $inlinePhone = trim((string)($cust['contact_phone'] ?? '')) ?: null;

        // Nothing to adopt: no inline details at all.
        if ($inlineName === '' && $inlineEmail === null && $inlinePhone === null) return;

        $d = $conn->prepare("SELECT id FROM customer_contacts WHERE customer_id = ? AND is_default = 1 AND is_active = 1 ORDER BY id LIMIT 1");
        $d->execute([$customerId]);
        $defaultId = $d->fetchColumn();

        if ($defaultId !== false) {
            $conn->prepare(
                "UPDATE customer_contacts SET name = ?, email = ?, phone = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?"
            )->execute([$inlineName !== '' ? $inlineName : $cust['name'], $inlineEmail, $inlinePhone, (int)$defaultId]);
            return;
        }

        // No default yet — either a brand-new customer, or one whose contacts all
        // went. Either way the inline details become its first contact.
        $conn->prepare(
            "INSERT INTO customer_contacts (customer_id, name, email, phone, is_default, is_active, created_by_analyst_id, created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, 1, 1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$customerId, $inlineName !== '' ? $inlineName : $cust['name'], $inlineEmail, $inlinePhone, $createdByAnalystId]);

        // Anything previously marked default but inactive must not linger.
        $conn->prepare("UPDATE customer_contacts SET is_default = 0 WHERE customer_id = ? AND id <> ?")
             ->execute([$customerId, (int)$conn->lastInsertId()]);
    } catch (Exception $e) {
        error_log('[customer-contacts] adopt inline: ' . $e->getMessage());
    }
}

/**
 * May $contactId be used on a ticket belonging to $customerId?
 *
 * True when either side is absent — a ticket with no customer, or no contact
 * chosen, is not a mismatch. A contact belongs to exactly one customer, so
 * anything else is a straightforward comparison.
 */
function customerContactBelongsTo(PDO $conn, ?int $contactId, ?int $customerId): bool {
    if (!$contactId || !$customerId) return true;
    if (!customerContactsTableExists($conn)) return true;
    try {
        $stmt = $conn->prepare("SELECT customer_id FROM customer_contacts WHERE id = ?");
        $stmt->execute([$contactId]);
        $owner = $stmt->fetchColumn();
        if ($owner === false) return false;
        return (int)$owner === (int)$customerId;
    } catch (Exception $e) {
        return true;
    }
}

/** The customer's contacts, default first, for a picker or a panel. */
function customerContactsList(PDO $conn, int $customerId, bool $activeOnly = true): array {
    if ($customerId <= 0 || !customerContactsTableExists($conn)) return [];
    try {
        $sql = "SELECT id, customer_id, name, email, phone, job_title, is_default, is_active, notes
                  FROM customer_contacts WHERE customer_id = ?"
             . ($activeOnly ? " AND is_active = 1" : '')
             . " ORDER BY is_default DESC, name";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }

    return array_map(fn($r) => [
        'id'          => (int)$r['id'],
        'customer_id' => (int)$r['customer_id'],
        'name'        => $r['name'],
        'email'       => $r['email'],
        'phone'       => $r['phone'],
        'job_title'   => $r['job_title'],
        'is_default'  => (int)$r['is_default'] === 1,
        'is_active'   => (int)$r['is_active'] === 1,
        'notes'       => $r['notes'],
    ], $rows);
}
