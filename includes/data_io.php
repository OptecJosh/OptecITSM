<?php
/**
 * CSV data import/export specs (Phase 10b).
 *
 * One source of truth for which entities are importable/exportable, which
 * columns are in scope, and how each column is validated. Deliberately a narrow,
 * safe surface: scalar columns only (no FK/lookup resolution, no secrets) so a
 * CSV round-trip can never touch password hashes, tokens or relational wiring.
 */

/** @return array<string,array> entity key => spec */
function data_io_entities(): array {
    return [
        'assets' => [
            'table'         => 'assets',
            'label'         => 'Assets',
            'match'         => 'hostname',      // natural key for create-or-update
            'tenant_scoped' => true,            // new rows stamped with active tenant; match within it
            'columns'       => [
                'hostname'         => ['type' => 'string',  'max' => 50,  'required' => true],
                'manufacturer'     => ['type' => 'string',  'max' => 50],
                'model'            => ['type' => 'string',  'max' => 50],
                'service_tag'      => ['type' => 'string',  'max' => 50],
                'operating_system' => ['type' => 'string',  'max' => 50],
                'order_number'     => ['type' => 'string',  'max' => 100],
                'purchase_cost'    => ['type' => 'decimal'],
                'purchase_date'    => ['type' => 'date'],
                'warranty_expiry'  => ['type' => 'date'],
                'end_of_life_date' => ['type' => 'date'],
                'disposal_date'    => ['type' => 'date'],
                'disposal_method'  => ['type' => 'string',  'max' => 100],
            ],
        ],
        'users' => [
            'table'         => 'users',
            'label'         => 'Users (portal)',
            'match'         => 'email',
            'tenant_scoped' => false,
            'columns'       => [
                'email'          => ['type' => 'email',  'max' => 255, 'required' => true],
                'display_name'   => ['type' => 'string', 'max' => 255],
                'preferred_name' => ['type' => 'string', 'max' => 100],
            ],
        ],
    ];
}

/**
 * Validate + normalise a raw CSV cell for a column spec.
 * @return array{0:bool,1:mixed,2:?string} [ok, normalisedValue, error]
 */
function data_io_normalise(string $col, array $spec, $raw): array {
    $v = trim((string)$raw);
    if ($v === '') {
        if (!empty($spec['required'])) return [false, null, "$col is required"];
        return [true, null, null];   // empty optional → NULL
    }
    switch ($spec['type']) {
        case 'email':
            if (!filter_var($v, FILTER_VALIDATE_EMAIL)) return [false, null, "$col is not a valid email"];
            if (isset($spec['max']) && mb_strlen($v) > $spec['max']) return [false, null, "$col too long"];
            return [true, $v, null];
        case 'date':
            $ts = strtotime($v);
            if ($ts === false) return [false, null, "$col is not a valid date"];
            return [true, date('Y-m-d', $ts), null];
        case 'decimal':
            if (!is_numeric($v)) return [false, null, "$col must be a number"];
            return [true, (string)(float)$v, null];
        case 'string':
        default:
            if (isset($spec['max']) && mb_strlen($v) > $spec['max']) $v = mb_substr($v, 0, $spec['max']);
            return [true, $v, null];
    }
}
