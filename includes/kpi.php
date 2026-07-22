<?php
/**
 * KPI helpers (management scorecards).
 *
 * The catalog (kpi_definitions) is seeded from the NOC KPI framework. Each KPI
 * carries a direction + optional green/amber thresholds so a RAG status can be
 * derived from a numeric value; qualitative KPIs (direction 'info'/'band', or no
 * thresholds) keep a hand-entered status. K2 adds an engine that computes the
 * ticket-derived values automatically; K0/K1 accept manual + imported values.
 */

/** Valid scorecards + their display labels/order. */
function kpi_scorecards(): array {
    return [
        'L1'       => 'L1 - Monitoring, Triage & First-Line Response',
        'L2'       => 'L2 - Incident Handling, Changes & Tuning',
        'L3'       => 'L3 - Detection Engineering & Complex Incident Lead',
        'L3_BAU'   => 'L3 - Business-as-usual operations',
        'COMBINED' => 'Combined Managed Service Team',
    ];
}

/** Ordered sections within the Combined scorecard (others have a single blank section). */
function kpi_sections(): array {
    return ['Service delivery', 'Estate health', 'Capacity & people', 'Ticket flow & effort'];
}

/**
 * Derive a RAG status from a numeric value against a KPI's thresholds.
 * Returns green|amber|red|info|na. 'info'/'band' KPIs and threshold-less KPIs
 * are never auto-scored (their status is entered by hand).
 */
function kpi_compute_status(string $direction, $green, $amber, $value): string {
    if ($value === null || $value === '') return 'na';
    if ($direction === 'info' || $direction === 'band' || $green === null) return 'info';
    $v = (float)$value;
    $g = (float)$green;
    $a = $amber !== null ? (float)$amber : null;
    if ($direction === 'higher') {
        if ($v >= $g) return 'green';
        if ($a !== null && $v >= $a) return 'amber';
        return 'red';
    }
    // lower is better
    if ($v <= $g) return 'green';
    if ($a !== null && $v <= $a) return 'amber';
    return 'red';
}

/** Validate a 'YYYY-MM' period string; returns it or null. */
function kpi_valid_period($p): ?string {
    $p = (string)$p;
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p) ? $p : null;
}

/** The N most recent period strings ending at $period (inclusive), oldest first. */
function kpi_period_window(string $period, int $n): array {
    [$y, $m] = array_map('intval', explode('-', $period));
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $mm = $m - $i;
        $yy = $y;
        while ($mm <= 0) { $mm += 12; $yy--; }
        $out[] = sprintf('%04d-%02d', $yy, $mm);
    }
    return $out;
}
