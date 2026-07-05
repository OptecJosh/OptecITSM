<?php
/**
 * Per-analyst timezone support.
 *
 * The app stores every datetime in UTC (UTC_TIMESTAMP() on write). This helper
 * resolves the *display* timezone for the current request — the logged-in
 * analyst's `timezone` user preference, falling back to the server default
 * (date_default_timezone_get(), i.e. the value set in config.php) for analysts
 * who haven't chosen one and for any no-user context (cron, workers, email).
 *
 * Mirrors the I18n bootstrap pattern: call Tz::init() once per page after the
 * session is available, then use Tz::current() / fmt_local() (PHP-rendered
 * dates) and Tz::scriptTag() to hand the zone to the browser (JS-rendered
 * dates convert via window.USER_TIMEZONE).
 *
 * Deliberately side-effect free: it does NOT call date_default_timezone_set(),
 * so server-side date math on a page is unaffected. Conversion is always
 * explicit (fmt_local / setTimezone), which keeps UTC-at-rest the single
 * source of truth.
 */
class Tz {
    /** Effective display zone for this request. Null until init(); then always a valid IANA id. */
    private static $zone = null;

    /**
     * Resolve the display zone for this request, in priority order:
     *   1. Logged-in analyst's `timezone` user preference (if a valid IANA id)
     *   2. Server default (date_default_timezone_get() — set in config.php)
     *
     * Safe to call without a database — falls back to the server default if
     * config/DB aren't loaded or the analyst has no preference.
     */
    public static function init() {
        // Self-load the DB helper if the including page hasn't (matches I18n).
        if (!function_exists('connectToDatabase') && is_file(__DIR__ . '/functions.php')) {
            require_once __DIR__ . '/functions.php';
        }

        if (!empty($_SESSION['analyst_id']) && function_exists('connectToDatabase')) {
            try {
                $conn = connectToDatabase();
                $stmt = $conn->prepare(
                    "SELECT preference_value FROM user_preferences
                     WHERE analyst_id = ? AND preference_key = 'timezone' LIMIT 1"
                );
                $stmt->execute([(int)$_SESSION['analyst_id']]);
                $value = $stmt->fetchColumn();
                if ($value && self::isValid($value)) {
                    self::$zone = $value;
                    return;
                }
            } catch (Throwable $e) {
                // Fall through to server default
            }
        }

        self::$zone = date_default_timezone_get();
    }

    /** The effective display zone. Lazily initialises to the server default if init() wasn't called. */
    public static function current() {
        if (self::$zone === null) {
            self::$zone = date_default_timezone_get();
        }
        return self::$zone;
    }

    /** True if $tz is a known IANA identifier. */
    public static function isValid($tz) {
        return is_string($tz) && $tz !== '' && in_array($tz, timezone_identifiers_list(), true);
    }

    /**
     * A <script> tag that publishes the effective zone to the browser so JS
     * date helpers can convert UTC → the analyst's zone. Emit once in <head>,
     * before any script that formats dates.
     */
    public static function scriptTag() {
        return '<script>window.USER_TIMEZONE = ' . json_encode(self::current()) . ';</script>';
    }
}

/**
 * Format a UTC datetime (as stored in the DB) in the current analyst's display
 * zone. Returns '' for null/empty and passes the raw value through unchanged if
 * it can't be parsed, so a bad value is visible rather than fatal.
 *
 * @param ?string $utc    A UTC datetime string (e.g. '2026-07-05 14:30:00') or null.
 * @param string  $format A date() format string.
 */
function fmt_local(?string $utc, string $format = 'Y-m-d H:i'): string {
    if ($utc === null || $utc === '') {
        return '';
    }
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone(Tz::current()));
        return $dt->format($format);
    } catch (Throwable $e) {
        return (string)$utc;
    }
}
