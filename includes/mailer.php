<?php
/**
 * Generic outbound HTML mailer (Phase 8b).
 *
 * Sends a self-contained HTML email that has NO ticket context — e.g. a
 * scheduled report — via a target mailbox, picking the right provider path
 * (Microsoft Graph / Gmail API / basic IMAP-SMTP). This is the ticket-less
 * counterpart to sla_send_breach_email(): same provider branching, no merge
 * data or ticket mailbox routing.
 */

require_once __DIR__ . '/encryption.php';

/**
 * The first active target mailbox (decrypted), or null if none. Used as the
 * default "from" for system mail that isn't tied to a specific ticket/mailbox.
 */
function mailer_first_active_mailbox(PDO $conn): ?array {
    $stmt = $conn->query("SELECT * FROM target_mailboxes WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
    $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mailbox) return null;
    return decryptMailboxRow($mailbox);
}

/**
 * Send an HTML email to one or more recipients from $mailbox (defaults to the
 * first active mailbox). Throws on unrecoverable errors (no mailbox, bad token,
 * send failure) so the caller can log per-report.
 *
 * @param string[] $recipients  validated email addresses
 */
function mailer_send_html(PDO $conn, array $recipients, string $subject, string $htmlBody, ?array $mailbox = null): void {
    $recipients = array_values(array_filter($recipients, fn($e) => is_string($e) && $e !== ''));
    if (!$recipients) {
        throw new Exception('no recipients');
    }

    $mailbox = $mailbox ?: mailer_first_active_mailbox($conn);
    if (!$mailbox) {
        throw new Exception('no active mailbox available to send from');
    }

    // Graph/Gmail want plain-text subjects.
    $subject = html_entity_decode($subject, ENT_QUOTES | ENT_HTML5);

    $provider = $mailbox['provider'] ?? 'microsoft';
    $accessToken = null;

    if ($provider === 'imap') {
        require_once __DIR__ . '/mailbox_imap.php';
    } else {
        $tokenData = json_decode(preg_replace('/[\x00-\x1F\x7F]/', '', $mailbox['token_data'] ?? ''), true);
        if (!$tokenData || !isset($tokenData['access_token'])) {
            throw new Exception("invalid token data on mailbox {$mailbox['id']}");
        }
        if ($provider === 'google') {
            require_once __DIR__ . '/gmail.php';
            $accessToken = gmailGetValidAccessToken($conn, $mailbox, $tokenData);
        } else {
            require_once __DIR__ . '/template_email.php';
            $accessToken = templateGetValidAccessToken($conn, $mailbox, $tokenData);
        }
        if (!$accessToken) {
            throw new Exception("failed to refresh access token for mailbox {$mailbox['id']}");
        }
    }

    foreach ($recipients as $to) {
        if ($provider === 'imap') {
            imapSmtpSend($mailbox, $to, '', $subject, $htmlBody);
        } elseif ($provider === 'google') {
            $from = $mailbox['target_mailbox'] ?? '';
            gmailSendEmail($accessToken, $to, $subject, $htmlBody, $from);
        } else {
            $message = [
                'message' => [
                    'subject'      => $subject,
                    'body'         => ['contentType' => 'HTML', 'content' => $htmlBody],
                    'toRecipients' => [['emailAddress' => ['address' => $to]]],
                ],
                'saveToSentItems' => false,
            ];
            templateSendViaGraph($accessToken, $message);
        }
    }
}
