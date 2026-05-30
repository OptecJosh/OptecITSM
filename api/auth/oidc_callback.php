<?php
/**
 * SSO login callback.
 * GET ?code=...&state=...   (the provider redirects the browser here)
 *
 * Completes the OpenID Connect login:
 *   1. validate `state` (CSRF), exchange the code for tokens (PKCE),
 *   2. validate the ID token (signature/JWKS, issuer, audience, nonce),
 *   3. resolve the analyst: existing link (provider+sub) -> email match ->
 *      just-in-time create (if the provider allows it),
 *   4. enforce STRICT isolation: an analyst may only sign in via the provider
 *      they're assigned to,
 *   5. set the session directly (SSO users skip the local TOTP/MFA step).
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/oidc.php';

/** Bounce back to the login page with an error message. */
function ssoBail(string $msg): void {
    // Clear any in-flight OIDC state so a retry starts clean.
    unset($_SESSION['oidc_state'], $_SESSION['oidc_nonce'],
          $_SESSION['oidc_code_verifier'], $_SESSION['oidc_provider_id']);
    $_SESSION['sso_error'] = $msg;
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

// Provider-side error (e.g. user cancelled).
if (isset($_GET['error'])) {
    ssoBail('Sign-in was cancelled or failed: ' . htmlspecialchars($_GET['error_description'] ?? $_GET['error']));
}

// --- CSRF: state must match what we issued ---
$state = $_GET['state'] ?? '';
if ($state === '' || empty($_SESSION['oidc_state']) || !hash_equals($_SESSION['oidc_state'], $state)) {
    ssoBail('Security check failed (state mismatch). Please try signing in again.');
}

$code         = $_GET['code'] ?? '';
$nonce        = $_SESSION['oidc_nonce'] ?? '';
$codeVerifier = $_SESSION['oidc_code_verifier'] ?? '';
$providerId   = (int)($_SESSION['oidc_provider_id'] ?? 0);
if ($code === '' || $nonce === '' || $codeVerifier === '' || $providerId <= 0) {
    ssoBail('Sign-in session expired. Please try again.');
}

try {
    $conn = connectToDatabase();

    $provider = oidcGetProvider($conn, $providerId);
    if (!$provider || (int)$provider['enabled'] !== 1) {
        ssoBail('That identity provider is no longer available.');
    }

    $disco  = oidcDiscover($provider['issuer_url']);
    $tokens = oidcExchangeCode($provider, $disco, $code, $codeVerifier);
    $claims = oidcValidateIdToken($tokens['id_token'], $disco, $provider, $nonce);

    // One-time use: invalidate the stashed tokens now that they're consumed.
    unset($_SESSION['oidc_state'], $_SESSION['oidc_nonce'],
          $_SESSION['oidc_code_verifier'], $_SESSION['oidc_provider_id']);

    // --- Identity claims ---
    $sub           = $claims['sub'] ?? '';
    $email         = strtolower(trim($claims['email'] ?? ''));
    $emailVerified = !empty($claims['email_verified']);
    $name          = $claims['name']
                     ?? trim(($claims['given_name'] ?? '') . ' ' . ($claims['family_name'] ?? ''))
                     ?: ($claims['preferred_username'] ?? $email);
    $preferredUser = $claims['preferred_username'] ?? ($email ?: $sub);
    if ($sub === '') {
        ssoBail('The provider did not return a user identifier.');
    }

    // --- 1) Existing link by (provider, sub) ---
    $stmt = $conn->prepare(
        "SELECT analyst_id FROM analyst_sso_identities WHERE provider_id = ? AND subject = ?"
    );
    $stmt->execute([$providerId, $sub]);
    $analystId = $stmt->fetchColumn();

    if ($analystId) {
        $analystId = (int)$analystId;
        $analyst = oidcLoadAnalyst($conn, $analystId);
        if (!$analyst || (int)$analyst['is_active'] !== 1) {
            ssoBail('Your account is inactive. Contact an administrator.');
        }
        // Strict isolation: must still be assigned to this provider.
        if ((int)($analyst['auth_provider_id'] ?? 0) !== $providerId) {
            ssoBail('Your account is not assigned to this sign-in method.');
        }
        $conn->prepare("UPDATE analyst_sso_identities SET last_login_datetime = UTC_TIMESTAMP(), email = ? WHERE provider_id = ? AND subject = ?")
             ->execute([$email ?: null, $providerId, $sub]);

    } else {
        // --- 2) Match an existing analyst by email ---
        $analyst = null;
        if ($email !== '') {
            if (!$emailVerified) {
                ssoBail('Your email is not verified with the identity provider.');
            }
            $stmt = $conn->prepare("SELECT * FROM analysts WHERE LOWER(email) = ? LIMIT 1");
            $stmt->execute([$email]);
            $analyst = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($analyst) {
            $analystId = (int)$analyst['id'];
            if ((int)$analyst['is_active'] !== 1) {
                ssoBail('Your account is inactive. Contact an administrator.');
            }
            // Strict isolation: the analyst must be assigned to THIS provider.
            if ((int)($analyst['auth_provider_id'] ?? 0) !== $providerId) {
                ssoBail('This account is not set up to sign in with this provider.');
            }
        } else {
            // --- 3) Just-in-time provisioning (only if the provider allows it) ---
            if ((int)$provider['auto_create_users'] !== 1) {
                ssoBail('No FreeITSM account exists for ' . ($email ?: 'this user') . '. Ask an administrator to create one.');
            }
            if ($email === '') {
                ssoBail('Cannot auto-create an account without an email from the provider.');
            }
            $analystId = oidcCreateAnalyst($conn, $providerId, $preferredUser, $name, $email, $provider['default_modules']);
            $analyst   = oidcLoadAnalyst($conn, $analystId);
        }

        // Link this IdP identity to the analyst for next time.
        $conn->prepare(
            "INSERT INTO analyst_sso_identities (analyst_id, provider_id, subject, email, linked_datetime, last_login_datetime)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$analystId, $providerId, $sub, $email ?: null]);
    }

    // --- Success: set the session directly (SSO bypasses local MFA) ---
    $conn->prepare("UPDATE analysts SET last_login_datetime = UTC_TIMESTAMP(), failed_login_count = 0 WHERE id = ?")
         ->execute([$analystId]);

    $_SESSION['analyst_id']       = $analystId;
    $_SESSION['analyst_username'] = $analyst['username'];
    $_SESSION['analyst_name']     = $analyst['full_name'];
    $_SESSION['analyst_email']    = $analyst['email'];
    $_SESSION['allowed_modules']  = getAnalystAllowedModules($conn, $analystId);
    // Remember the SSO context so logout can also end the session at the IdP.
    $_SESSION['sso_provider_id']  = $providerId;
    $_SESSION['sso_id_token']     = $tokens['id_token'];

    header('Location: ' . BASE_URL);
    exit;

} catch (Exception $e) {
    ssoBail('Sign-in failed: ' . $e->getMessage());
}

// --------------------------------------------------------------------------

function oidcLoadAnalyst(PDO $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT * FROM analysts WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Create a new analyst from SSO claims, assigned to the given provider.
 * The local password is set to an unusable random hash (SSO users sign in
 * via the IdP, not a local password).
 */
function oidcCreateAnalyst(PDO $conn, int $providerId, string $preferredUser, string $name, string $email, ?string $defaultModules): int {
    // Derive a unique username from the preferred username / email local-part.
    $base = strtolower(preg_replace('/[^a-zA-Z0-9._-]/', '', $preferredUser ?: explode('@', $email)[0]));
    if ($base === '') $base = 'ssouser';
    $username = $base;
    $i = 1;
    $check = $conn->prepare("SELECT COUNT(*) FROM analysts WHERE username = ?");
    while (true) {
        $check->execute([$username]);
        if ((int)$check->fetchColumn() === 0) break;
        $username = $base . $i++;
    }

    $unusable = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $stmt = $conn->prepare(
        "INSERT INTO analysts (username, password_hash, full_name, email, is_active, created_datetime, auth_provider_id)
         VALUES (?, ?, ?, ?, 1, UTC_TIMESTAMP(), ?)"
    );
    $stmt->execute([$username, $unusable, $name ?: $username, $email, $providerId]);
    $analystId = (int)$conn->lastInsertId();

    // Optional per-provider default module grants. Empty => no rows => full access.
    if ($defaultModules !== null && trim($defaultModules) !== '') {
        $mods = array_filter(array_map('trim', explode(',', $defaultModules)));
        $ins  = $conn->prepare("INSERT INTO analyst_modules (analyst_id, module_key) VALUES (?, ?)");
        foreach ($mods as $m) {
            try { $ins->execute([$analystId, $m]); } catch (Exception $e) { /* ignore dupes */ }
        }
    }
    return $analystId;
}
