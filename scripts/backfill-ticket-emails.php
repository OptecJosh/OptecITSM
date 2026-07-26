<?php
/**
 * Backfill the initial email for tickets that have none — CLI.
 *
 * The ticket list is built FROM the emails table, so a ticket with no email row
 * is counted in every folder badge and shown in none of them. Tickets created
 * before includes/ticket_seed_email.php was shared — by the reporting demo
 * generator, or by the mass importer before it seeded one — are in that state.
 * This gives each of them a plausible opening message built from the ticket's
 * own data, so they appear where they should.
 *
 * Usage (from the repo root, inside the app container):
 *
 *   php scripts/backfill-ticket-emails.php            # dry run: how many, and a sample
 *   php scripts/backfill-ticket-emails.php --confirm  # write them
 *
 * In Docker:
 *   docker compose exec app php scripts/backfill-ticket-emails.php
 *   docker compose exec app php scripts/backfill-ticket-emails.php --confirm
 *
 * Safe to re-run: only tickets with zero emails are touched.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ticket_seed_email.php';

$confirm = in_array('--confirm', array_slice($argv, 1), true);
$conn = connectToDatabase();

// Every live ticket with no email at all, plus what we need to write a sensible
// one: its own subject and creation time, and the requester if it has one.
$sql = "SELECT t.id, t.ticket_number, t.subject, t.created_datetime,
               u.email AS requester_email, u.display_name AS requester_name
          FROM tickets t
     LEFT JOIN users u ON u.id = t.user_id
     LEFT JOIN emails e ON e.ticket_id = t.id
         WHERE t.deleted_datetime IS NULL
           AND e.id IS NULL
      ORDER BY t.id";
$rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
echo ($confirm ? 'BACKFILLING' : 'DRY RUN') . " — {$total} ticket(s) have no email and are therefore invisible in the ticket list.\n\n";

if ($total === 0) {
    echo "Nothing to do: every live ticket already has at least one email.\n";
    exit(0);
}

foreach (array_slice($rows, 0, 5) as $r) {
    printf("  %-16s %s\n", $r['ticket_number'], mb_substr((string)$r['subject'], 0, 60));
}
if ($total > 5) echo "  … and " . ($total - 5) . " more\n";

if (!$confirm) {
    echo "\nDry run — nothing was written. Re-run with --confirm to create the missing emails.\n";
    exit(0);
}

echo "\nWriting…\n";
$done = 0;
$failed = 0;
$conn->beginTransaction();
try {
    foreach ($rows as $r) {
        $subject = (string)($r['subject'] ?? '(no subject)');
        try {
            ticketSeedInitialEmail(
                $conn,
                (int)$r['id'],
                $subject,
                $subject . "\n\nThis ticket was created without a recorded message. The subject is all the "
                         . "detail that was captured at the time.",
                $r['requester_email'] ?: null,
                $r['requester_name'] ?: null,
                $r['created_datetime'] ?: null,
                'Backfill'
            );
            $done++;
        } catch (Exception $e) {
            $failed++;
            error_log('[backfill-ticket-emails] ticket ' . $r['id'] . ': ' . $e->getMessage());
        }
    }
    $conn->commit();
} catch (Exception $e) {
    $conn->rollBack();
    fwrite(STDERR, "FAILED — rolled back, nothing was written: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Created {$done} email(s)" . ($failed ? ", {$failed} failed (see the error log)" : '') . ".\n";
echo "Those tickets will now appear in the ticket list.\n";
