<?php
/**
 * Overtime helpers (Phase 11) — settings lookup, rate resolution, audit.
 */

/** All overtime_settings as key=>value (with sane fallbacks). */
function overtime_settings(PDO $conn): array {
    $defaults = [
        'ot_multiplier_standard'      => '1.00',
        'ot_multiplier_time_and_half' => '1.50',
        'ot_multiplier_double'        => '2.00',
        'ot_max_daily_hours'          => '16',
        'ot_approval_required'        => '1',
    ];
    try {
        $rows = $conn->query("SELECT setting_key, setting_value FROM overtime_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_merge($defaults, $rows ?: []);
    } catch (Exception $e) {
        return $defaults;
    }
}

/** Valid overtime types. */
function overtime_types(): array { return ['standard', 'time_and_half', 'double']; }

/** The rate multiplier for a type, from settings (default 1.00). */
function overtime_multiplier_for(array $settings, string $type): float {
    $key = 'ot_multiplier_' . $type;
    return isset($settings[$key]) ? (float)$settings[$key] : 1.00;
}

/** Business hours between two 'HH:MM[:SS]' times on the same day (end > start). */
function overtime_hours_between(string $start, string $end): float {
    $s = strtotime('1970-01-01 ' . $start);
    $e = strtotime('1970-01-01 ' . $end);
    if ($s === false || $e === false || $e <= $s) return 0.0;
    return round(($e - $s) / 3600, 2);
}

/** Append an overtime audit row (best-effort). */
function overtime_audit(PDO $conn, int $requestId, ?int $analystId, string $action, ?string $old = null, ?string $new = null, ?string $note = null): void {
    try {
        $conn->prepare(
            "INSERT INTO overtime_audit (request_id, analyst_id, action_type, old_value, new_value, note, created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$requestId, $analystId, $action, $old, $new, $note]);
    } catch (Exception $e) { /* non-fatal */ }
}
