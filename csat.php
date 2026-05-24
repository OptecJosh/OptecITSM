<?php
/**
 * Public CSAT survey landing page.
 *
 * NO authentication — the URL itself is the credential. The token is matched
 * against ticket_csat_responses.token; an invalid or already-responded token
 * shows a friendly error rather than leaking which is which (timing-safe).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csat.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$conn  = connectToDatabase();

// Read survey scale setting before any branch — it's needed by both POST and GET
$scaleMode = csatGetSetting($conn, 'csat_scale', 'stars');

$response = null;
$ticket   = null;
$error    = '';

if ($token === '' || !preg_match('/^[a-f0-9]{20,64}$/i', $token)) {
    $error = 'invalid';
} else {
    $stmt = $conn->prepare(
        "SELECT cr.id, cr.ticket_id, cr.responded_datetime,
                t.ticket_number, t.subject,
                COALESCE(u.preferred_name, u.display_name, u.email) AS requester_name
         FROM ticket_csat_responses cr
         INNER JOIN tickets t ON t.id = cr.ticket_id
         LEFT JOIN users u ON u.id = t.user_id
         WHERE cr.token = ?"
    );
    $stmt->execute([$token]);
    $response = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$response) {
        $error = 'invalid';
    } elseif ($response['responded_datetime']) {
        $error = 'already';
    } else {
        $ticket = [
            'number'  => $response['ticket_number'],
            'subject' => $response['subject'],
            'name'    => $response['requester_name'],
        ];
    }
}

$showThanks = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $rating  = (int)($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    if ($rating < 1 || $rating > 5) {
        $error = 'invalid_rating';
    } else {
        try {
            $upd = $conn->prepare(
                "UPDATE ticket_csat_responses
                 SET rating = ?, comment = ?, responded_datetime = UTC_TIMESTAMP()
                 WHERE id = ? AND responded_datetime IS NULL"
            );
            $upd->execute([$rating, $comment !== '' ? $comment : null, (int)$response['id']]);
            $showThanks = true;
        } catch (Exception $e) {
            error_log('csat.php: ' . $e->getMessage());
            $error = 'server';
        }
    }
}

// Picked when emoji scale; renders the same 1-5 score visually
$emojis = ['', '😡', '🙁', '😐', '🙂', '😀'];
$emojiLabels = ['', 'Very dissatisfied', 'Dissatisfied', 'Neutral', 'Satisfied', 'Very satisfied'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>How did we do?</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    color: #333;
}
.card {
    background: white;
    max-width: 520px;
    width: 100%;
    border-radius: 14px;
    box-shadow: 0 12px 50px rgba(0,0,0,0.25);
    padding: 40px 36px;
    text-align: center;
}
h1 { font-size: 22px; margin-bottom: 8px; }
p.ticket { font-size: 14px; color: #666; margin-bottom: 28px; }
p.intro { font-size: 15px; line-height: 1.5; margin-bottom: 26px; color: #444; }

.rating-row {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin: 20px 0 28px;
    flex-wrap: wrap;
}
.rating-btn {
    background: white;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    padding: 12px 8px;
    cursor: pointer;
    min-width: 64px;
    transition: all 0.15s;
    font-family: inherit;
    color: inherit;
}
.rating-btn:hover { border-color: #667eea; transform: translateY(-2px); }
.rating-btn.selected {
    border-color: #667eea;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}
.rating-emoji { font-size: 32px; line-height: 1; margin-bottom: 6px; }
.rating-stars { font-size: 28px; line-height: 1; margin-bottom: 4px; color: #f59e0b; }
.rating-label { font-size: 11px; }

textarea {
    width: 100%;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 10px 12px;
    font-family: inherit;
    font-size: 14px;
    min-height: 80px;
    resize: vertical;
    margin-bottom: 20px;
}
textarea:focus { outline: none; border-color: #667eea; }
button.submit {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 12px 36px;
    border-radius: 6px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.15s;
}
button.submit:hover { transform: translateY(-2px); }
button.submit:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

.thanks-icon { font-size: 56px; margin-bottom: 12px; }
.error-box {
    background: #fee;
    color: #c33;
    border-left: 4px solid #c33;
    padding: 14px 16px;
    border-radius: 6px;
    margin: 20px 0;
    text-align: left;
    font-size: 14px;
}
</style>
</head>
<body>
<div class="card">

<?php if ($showThanks): ?>
    <div class="thanks-icon">✅</div>
    <h1>Thanks for your feedback!</h1>
    <p class="intro">We&rsquo;ve recorded your response. The team will use it to keep improving the service.</p>

<?php elseif ($error === 'invalid'): ?>
    <h1>This survey link isn&rsquo;t valid</h1>
    <p class="intro">The link may have been mistyped, or it&rsquo;s already been used. If you believe this is a mistake, please reply to the original ticket email.</p>

<?php elseif ($error === 'already'): ?>
    <h1>You&rsquo;ve already responded</h1>
    <p class="intro">Thanks &mdash; we&rsquo;ve already got your feedback for this ticket. Each survey link can only be used once.</p>

<?php elseif ($error === 'server'): ?>
    <h1>Something went wrong</h1>
    <p class="intro">We couldn&rsquo;t save your response just now. Please try again in a minute, or reply to the original ticket email.</p>

<?php else: ?>
    <h1>How did we do?</h1>
    <p class="ticket">Ticket <strong><?= htmlspecialchars($ticket['number']) ?></strong> &middot; <?= htmlspecialchars($ticket['subject']) ?></p>
    <p class="intro">Hi <?= htmlspecialchars(explode(' ', $ticket['name'])[0] ?: 'there') ?>, thanks for letting us help. How would you rate the experience?</p>

    <?php if ($error === 'invalid_rating'): ?>
        <div class="error-box">Please pick a rating before submitting.</div>
    <?php endif; ?>

    <form method="POST">
        <div class="rating-row" id="ratingRow">
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <button type="button" class="rating-btn" data-rating="<?= $i ?>" onclick="pickRating(<?= $i ?>)">
                    <?php if ($scaleMode === 'emojis'): ?>
                        <div class="rating-emoji"><?= $emojis[$i] ?></div>
                        <div class="rating-label"><?= htmlspecialchars($emojiLabels[$i]) ?></div>
                    <?php else: ?>
                        <div class="rating-stars"><?= str_repeat('&starf;', $i) ?></div>
                        <div class="rating-label"><?= $i ?>/5</div>
                    <?php endif; ?>
                </button>
            <?php endfor; ?>
        </div>

        <input type="hidden" name="rating" id="ratingInput" value="">
        <textarea name="comment" placeholder="Anything you'd like to add? (optional)" maxlength="2000"></textarea>
        <button type="submit" class="submit" id="submitBtn" disabled>Submit feedback</button>
    </form>

    <script>
    function pickRating(n) {
        document.getElementById('ratingInput').value = n;
        document.querySelectorAll('.rating-btn').forEach(b => {
            b.classList.toggle('selected', parseInt(b.dataset.rating, 10) === n);
        });
        document.getElementById('submitBtn').disabled = false;
    }
    </script>
<?php endif; ?>

</div>
</body>
</html>
