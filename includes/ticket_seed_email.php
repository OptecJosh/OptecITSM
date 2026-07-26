<?php
/**
 * The initial email every ticket needs.
 *
 * THE INVARIANT: **a ticket without at least one row in `emails` is invisible.**
 * The ticket list is built FROM that table — get_emails.php starts at "latest
 * email per ticket" and joins tickets onto it — so a ticket with none is real,
 * counted in every folder badge, reportable and exportable, and unreachable in
 * the one place anyone looks. TicketsService::createTicket has always written
 * one; anything else that creates tickets has to as well.
 *
 * Callers today: the mass importer's post-insert hook, the reporting demo
 * generator, and scripts/backfill-ticket-emails.php for tickets created before
 * this was shared.
 */

/**
 * Write a ticket's opening email.
 *
 * @param string      $subject   the ticket's subject
 * @param string      $body      plain text; stored as escaped HTML
 * @param string|null $from      sender address; a placeholder is used when null,
 *                               since emails.from_address is NOT NULL
 * @param string|null $fromName  display name for the sender
 * @param string|null $receivedAt 'Y-m-d H:i:s'; defaults to now. Pass the
 *                               TICKET's created_datetime, or a back-dated
 *                               import sorts to the top of the list.
 * @param string      $direction stored on the row so the origin stays visible
 */
function ticketSeedInitialEmail(
    PDO $conn,
    int $ticketId,
    string $subject,
    string $body = '',
    ?string $from = null,
    ?string $fromName = null,
    ?string $receivedAt = null,
    string $direction = 'Manual'
): void {
    if ($ticketId <= 0) return;

    $from = ($from !== null && trim($from) !== '') ? trim($from) : 'noreply@localhost';
    $fromName = ($fromName !== null && trim($fromName) !== '') ? trim($fromName) : $from;
    $receivedAt = $receivedAt ?: gmdate('Y-m-d H:i:s');
    if (trim($body) === '') {
        $body = 'No message body was recorded for this ticket.';
    }

    $conn->prepare(
        "INSERT INTO emails
            (mailbox_id, subject, from_address, from_name, to_recipients, received_datetime,
             body_preview, body_content, body_type, has_attachments, importance,
             is_read, ticket_id, is_initial, direction)
         VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 'html', 0, 'normal', 1, ?, 1, ?)"
    )->execute([
        mb_substr($subject, 0, 500),
        mb_substr($from, 0, 255),
        mb_substr($fromName, 0, 255),
        mb_substr($from, 0, 255),
        $receivedAt,
        mb_substr(strip_tags($body), 0, 200),
        nl2br(htmlspecialchars($body)),
        $ticketId,
        $direction,
    ]);
}

/** Does this ticket already have an email? Used by the backfill and by callers
 *  that might run twice. */
function ticketHasEmail(PDO $conn, int $ticketId): bool {
    try {
        $stmt = $conn->prepare("SELECT 1 FROM emails WHERE ticket_id = ? LIMIT 1");
        $stmt->execute([$ticketId]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return true;   // fail safe: never double-write on a broken read
    }
}
