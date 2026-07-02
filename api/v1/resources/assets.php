<?php
/**
 * FreeITSM REST API v1 — assets resource.
 *
 * Mirrors the Assets module's behaviour so an asset touched via the API is
 * indistinguishable from one touched in the UI or by the inventory agent:
 *   - field updates write asset_history rows with the SAME stable field keys
 *     the UI uses (update_asset_field.php), and display NAMES for lookups
 *   - assignment/unassignment mirror assign_asset_user.php /
 *     unassign_asset_user.php (users_assets + custody log + audit)
 *   - a warranty_expiry change re-syncs the calendar's warranty events
 *
 * Notes on scope and identity:
 *   - Assets are NOT company-scoped (no tenant_id column) — they are
 *     install-wide, exactly as in the UI, so a key's company scope does not
 *     restrict them.
 *   - There is deliberately NO delete endpoint: nothing in the product
 *     deletes assets (they are agent-maintained records).
 *   - hostname is the de-facto identity every ingest path upserts on, so
 *     creating a duplicate hostname is a 409.
 */

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function apiAssetSelect(): string {
    return "SELECT a.*,
                   at.name  AS type_name,
                   ast.name AS status_name,
                   al.name  AS location_name,
                   COALESCE(NULLIF(TRIM(s.trading_name), ''), s.legal_name) AS supplier_name,
                   (SELECT COUNT(*) FROM users_assets ua WHERE ua.asset_id = a.id) AS assigned_count
            FROM assets a
            LEFT JOIN asset_types        at  ON at.id  = a.asset_type_id
            LEFT JOIN asset_status_types ast ON ast.id = a.asset_status_id
            LEFT JOIN asset_locations    al  ON al.id  = a.location_id
            LEFT JOIN suppliers          s   ON s.id   = a.supplier_id";
}

/** All locations keyed by id — used to build full "UK › London › Office 1" paths. */
function apiAssetLocations(PDO $conn): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ($conn->query("SELECT id, name, parent_id FROM asset_locations") as $row) {
            $cache[(int)$row['id']] = ['name' => $row['name'], 'parent_id' => $row['parent_id'] !== null ? (int)$row['parent_id'] : null];
        }
    }
    return $cache;
}

function apiAssetLocationPath(PDO $conn, ?int $locationId): ?string {
    if ($locationId === null) {
        return null;
    }
    $locations = apiAssetLocations($conn);
    $parts = [];
    $id = $locationId;
    $guard = 0;
    while ($id !== null && isset($locations[$id]) && $guard++ < 20) {
        array_unshift($parts, $locations[$id]['name']);
        $id = $locations[$id]['parent_id'];
    }
    return $parts ? implode(' › ', $parts) : null;
}

function apiSerializeAsset(PDO $conn, array $r): array {
    $rel = function ($id, $name) {
        return $id === null ? null : ['id' => (int)$id, 'name' => $name];
    };
    $locationId = $r['location_id'] !== null ? (int)$r['location_id'] : null;
    return [
        'id'       => (int)$r['id'],
        'hostname' => $r['hostname'],
        'type'     => $rel($r['asset_type_id'], $r['type_name']),
        'status'   => $rel($r['asset_status_id'], $r['status_name']),
        'location' => $locationId === null ? null : [
            'id'   => $locationId,
            'name' => $r['location_name'],
            'path' => apiAssetLocationPath($conn, $locationId),
        ],
        'hardware' => [
            'manufacturer'     => $r['manufacturer'],
            'model'            => $r['model'],
            'service_tag'      => $r['service_tag'],
            'memory'           => $r['memory'] !== null ? (int)$r['memory'] : null,
            'cpu_name'         => $r['cpu_name'],
            'speed'            => $r['speed'] !== null ? (int)$r['speed'] : null,
            'gpu_name'         => $r['gpu_name'],
            'bios_version'     => $r['bios_version'],
            'tpm_version'      => $r['tpm_version'],
            'bitlocker_status' => $r['bitlocker_status'],
        ],
        'os' => [
            'operating_system' => $r['operating_system'],
            'feature_release'  => $r['feature_release'],
            'build_number'     => $r['build_number'],
        ],
        'network' => [
            'domain'         => $r['domain'],
            'logged_in_user' => $r['logged_in_user'],
        ],
        'lifecycle' => [
            'purchase_date'   => $r['purchase_date'],
            'purchase_cost'   => $r['purchase_cost'] !== null ? (float)$r['purchase_cost'] : null,
            'supplier'        => $rel($r['supplier_id'], $r['supplier_name']),
            'order_number'    => $r['order_number'],
            'warranty_expiry' => $r['warranty_expiry'],
        ],
        'assigned_users_count' => (int)($r['assigned_count'] ?? 0),
        'first_seen'   => apiIsoDate($r['first_seen']),
        'last_seen'    => apiIsoDate($r['last_seen']),
        'last_boot_at' => apiIsoDate($r['last_boot_utc']),
    ];
}

/** Load one asset (with joins); 404 if unknown. Assets are install-wide (no company scope). */
function apiLoadAsset(PDO $conn, int $assetId): array {
    $stmt = $conn->prepare(apiAssetSelect() . " WHERE a.id = ?");
    $stmt->execute([$assetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Asset not found.');
    }
    return $row;
}

function apiAssetAuditWrite(PDO $conn, int $assetId, int $analystId, string $fieldKey, ?string $old, ?string $new): void {
    $stmt = $conn->prepare(
        "INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
    );
    $stmt->execute([$assetId, $analystId, $fieldKey, $old, $new]);
}

/** Validate a DATE field (YYYY-MM-DD); 422 naming the field on garbage. Null/'' clears. */
function apiParseDateOnly($value, string $field): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('Y-m-d', (string)$value);
    if (!$d || $d->format('Y-m-d') !== (string)$value) {
        apiError(422, 'invalid_field', "'{$field}' must be a date in YYYY-MM-DD format.");
    }
    return (string)$value;
}

/**
 * The editable columns, their audit field keys (SAME stable keys the UI's
 * update_asset_field.php stores, so the history view localises them), and how
 * to validate/resolve each. 'lookup' fields audit display NAMES, not ids.
 */
function apiAssetFieldMap(): array {
    return [
        // Classification / lifecycle (the fields the UI edits)
        'asset_type_id'    => ['audit' => 'type',            'kind' => 'lookup', 'table' => 'asset_types',        'label' => 'asset type'],
        'asset_status_id'  => ['audit' => 'status',          'kind' => 'lookup', 'table' => 'asset_status_types', 'label' => 'asset status'],
        'location_id'      => ['audit' => 'location',        'kind' => 'lookup', 'table' => 'asset_locations',    'label' => 'location'],
        'supplier_id'      => ['audit' => 'supplier',        'kind' => 'supplier'],
        'purchase_date'    => ['audit' => 'purchase_date',   'kind' => 'date'],
        'purchase_cost'    => ['audit' => 'purchase_cost',   'kind' => 'decimal'],
        'order_number'     => ['audit' => 'order_number',    'kind' => 'string', 'max' => 100],
        'warranty_expiry'  => ['audit' => 'warranty_expiry', 'kind' => 'date'],
        // Hardware / OS / identity (agent-maintained in the UI; writable here
        // so a non-agent source can sync them — audited under the column name)
        'hostname'         => ['audit' => 'hostname',         'kind' => 'string', 'max' => 50],
        'manufacturer'     => ['audit' => 'manufacturer',     'kind' => 'string', 'max' => 50],
        'model'            => ['audit' => 'model',            'kind' => 'string', 'max' => 50],
        'service_tag'      => ['audit' => 'service_tag',      'kind' => 'string', 'max' => 50],
        'memory'           => ['audit' => 'memory',           'kind' => 'int'],
        'operating_system' => ['audit' => 'operating_system', 'kind' => 'string', 'max' => 50],
        'feature_release'  => ['audit' => 'feature_release',  'kind' => 'string', 'max' => 10],
        'build_number'     => ['audit' => 'build_number',     'kind' => 'string', 'max' => 50],
        'cpu_name'         => ['audit' => 'cpu_name',         'kind' => 'string', 'max' => 250],
        'speed'            => ['audit' => 'speed',            'kind' => 'int'],
        'bios_version'     => ['audit' => 'bios_version',     'kind' => 'string', 'max' => 20],
        'gpu_name'         => ['audit' => 'gpu_name',         'kind' => 'string', 'max' => 250],
        'tpm_version'      => ['audit' => 'tpm_version',      'kind' => 'string', 'max' => 50],
        'bitlocker_status' => ['audit' => 'bitlocker_status', 'kind' => 'string', 'max' => 20],
        'domain'           => ['audit' => 'domain',           'kind' => 'string', 'max' => 100],
        'logged_in_user'   => ['audit' => 'logged_in_user',   'kind' => 'string', 'max' => 100],
    ];
}

/** Validate one incoming field value per its map entry. Returns the DB-ready value. */
function apiAssetValidateField(PDO $conn, string $field, $value, array $def) {
    if ($value === '' || $value === null) {
        return null;
    }
    switch ($def['kind']) {
        case 'lookup':
            $stmt = $conn->prepare("SELECT id FROM {$def['table']} WHERE id = ?");
            $stmt->execute([(int)$value]);
            if (!$stmt->fetchColumn()) {
                apiError(422, 'invalid_field', "Unknown {$def['label']} id: {$value}");
            }
            return (int)$value;
        case 'supplier':
            $stmt = $conn->prepare("SELECT id FROM suppliers WHERE id = ?");
            $stmt->execute([(int)$value]);
            if (!$stmt->fetchColumn()) {
                apiError(422, 'invalid_field', "Unknown supplier id: {$value}");
            }
            return (int)$value;
        case 'date':
            return apiParseDateOnly($value, $field);
        case 'int':
            if (!is_numeric($value)) {
                apiError(422, 'invalid_field', "'{$field}' must be a number.");
            }
            return (int)$value;
        case 'decimal':
            if (!is_numeric($value)) {
                apiError(422, 'invalid_field', "'{$field}' must be a number.");
            }
            return (string)round((float)$value, 2);
        default: // string
            $v = trim((string)$value);
            if (isset($def['max']) && mb_strlen($v) > $def['max']) {
                apiError(422, 'invalid_field', "'{$field}' must be at most {$def['max']} characters.");
            }
            return $v === '' ? null : $v;
    }
}

/** Resolve a lookup id to its display name for the audit trail (mirrors update_asset_field.php). */
function apiAssetAuditDisplay(PDO $conn, string $field, $value, array $def): ?string {
    if ($value === null) {
        return null;
    }
    if ($def['kind'] === 'lookup') {
        $stmt = $conn->prepare("SELECT name FROM {$def['table']} WHERE id = ?");
        $stmt->execute([(int)$value]);
        $name = $stmt->fetchColumn();
        return $name !== false ? $name : (string)$value;
    }
    if ($def['kind'] === 'supplier') {
        $stmt = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(trading_name), ''), legal_name) FROM suppliers WHERE id = ?");
        $stmt->execute([(int)$value]);
        $name = $stmt->fetchColumn();
        return $name !== false ? $name : (string)$value;
    }
    return (string)$value;
}

// ---------------------------------------------------------------------------
// GET /assets
// ---------------------------------------------------------------------------
function apiAssetsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];

    foreach ([
        'asset_type_id'   => 'a.asset_type_id',
        'asset_status_id' => 'a.asset_status_id',
        'location_id'     => 'a.location_id',
        'supplier_id'     => 'a.supplier_id',
    ] as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col = ?";
            $args[]  = (int)$_GET[$param];
        }
    }
    if (isset($_GET['hostname']) && $_GET['hostname'] !== '') {
        $where[] = 'a.hostname = ?';
        $args[]  = trim($_GET['hostname']);
    }
    if (isset($_GET['service_tag']) && $_GET['service_tag'] !== '') {
        $where[] = 'a.service_tag = ?';
        $args[]  = trim($_GET['service_tag']);
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = '(a.hostname LIKE ? OR a.service_tag LIKE ? OR a.model LIKE ? OR a.manufacturer LIKE ?)';
        $like = '%' . trim($_GET['q']) . '%';
        array_push($args, $like, $like, $like, $like);
    }
    if (isset($_GET['assigned_user_id']) && $_GET['assigned_user_id'] !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM users_assets ua WHERE ua.asset_id = a.id AND ua.user_id = ?)';
        $args[]  = (int)$_GET['assigned_user_id'];
    }
    if (($_GET['unassigned'] ?? '') === 'true') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM users_assets ua WHERE ua.asset_id = a.id)';
    }
    // Lifecycle filters — the same shapes Watchtower/dashboard use.
    if (isset($_GET['warranty_within_days']) && $_GET['warranty_within_days'] !== '') {
        $where[] = 'a.warranty_expiry IS NOT NULL AND a.warranty_expiry <= DATE_ADD(CURDATE(), INTERVAL ? DAY)';
        $args[]  = max(0, (int)$_GET['warranty_within_days']);
    }
    if (($_GET['warranty_expired'] ?? '') === 'true') {
        $where[] = 'a.warranty_expiry IS NOT NULL AND a.warranty_expiry < CURDATE()';
    }
    if (isset($_GET['not_seen_days']) && $_GET['not_seen_days'] !== '') {
        $where[] = '(a.last_seen IS NULL OR a.last_seen < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY))';
        $args[]  = max(0, (int)$_GET['not_seen_days']);
    }

    $sortable = [
        'id' => 'a.id', 'hostname' => 'a.hostname', 'last_seen' => 'a.last_seen',
        'first_seen' => 'a.first_seen', 'warranty_expiry' => 'a.warranty_expiry',
        'purchase_date' => 'a.purchase_date', 'model' => 'a.model',
    ];
    $sortParam = trim($_GET['sort'] ?? 'hostname');
    $desc = strncmp($sortParam, '-', 1) === 0;
    $sortKey = ltrim($sortParam, '-');
    if (!isset($sortable[$sortKey])) {
        apiError(400, 'invalid_parameter', "Unknown sort field '{$sortKey}'. Sortable: " . implode(', ', array_keys($sortable)));
    }
    $orderSql = $sortable[$sortKey] . ($desc ? ' DESC' : ' ASC');

    [$page, $perPage, $offset] = apiPagination();
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM assets a WHERE $whereSql");
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(apiAssetSelect() . " WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
    $stmt->execute($args);
    $assets = array_map(function ($r) use ($conn) {
        return apiSerializeAsset($conn, $r);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    apiRespond($assets, 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

// ---------------------------------------------------------------------------
// GET /assets/{id}
// ---------------------------------------------------------------------------
function apiAssetsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $row = apiLoadAsset($conn, $params[0]);
    $asset = apiSerializeAsset($conn, $row);

    // Current holders inline — the one child collection you nearly always want.
    $uStmt = $conn->prepare(
        "SELECT ua.user_id, u.display_name, u.email, ua.assigned_datetime, ua.expected_return_date, ua.notes
         FROM users_assets ua LEFT JOIN users u ON u.id = ua.user_id
         WHERE ua.asset_id = ? ORDER BY ua.assigned_datetime ASC"
    );
    $uStmt->execute([$params[0]]);
    $asset['assigned_users'] = array_map(function ($u) {
        return [
            'user_id'              => (int)$u['user_id'],
            'name'                 => $u['display_name'],
            'email'                => $u['email'],
            'assigned_at'          => apiIsoDate($u['assigned_datetime']),
            'expected_return_date' => $u['expected_return_date'],
            'notes'                => $u['notes'],
        ];
    }, $uStmt->fetchAll(PDO::FETCH_ASSOC));

    apiRespond($asset);
}

// ---------------------------------------------------------------------------
// POST /assets
// ---------------------------------------------------------------------------
function apiAssetsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $hostname = trim((string)($body['hostname'] ?? ''));
    if ($hostname === '') {
        apiError(422, 'missing_field', "'hostname' is required.");
    }
    if (mb_strlen($hostname) > 50) {
        apiError(422, 'invalid_field', "'hostname' must be at most 50 characters.");
    }

    // hostname is the identity every ingest path upserts on — duplicates would
    // split an asset's records, so refuse rather than silently fork.
    $dup = $conn->prepare("SELECT id FROM assets WHERE hostname = ?");
    $dup->execute([$hostname]);
    $existingId = $dup->fetchColumn();
    if ($existingId !== false) {
        apiError(409, 'conflict', "An asset with this hostname already exists (id {$existingId}). Use PATCH /assets/{$existingId} to update it.");
    }

    $map = apiAssetFieldMap();
    unset($map['hostname']); // handled above
    $columns = ['hostname'];
    $values  = [$hostname];
    foreach ($map as $field => $def) {
        if (!array_key_exists($field, $body)) {
            continue;
        }
        $columns[] = $field;
        $values[]  = apiAssetValidateField($conn, $field, $body[$field], $def);
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = "INSERT INTO assets (" . implode(', ', $columns) . ", first_seen, last_seen)
            VALUES ($placeholders, UTC_TIMESTAMP(), UTC_TIMESTAMP())";
    $conn->prepare($sql)->execute($values);
    $assetId = (int)$conn->lastInsertId();

    apiAssetAuditWrite($conn, $assetId, (int)$apiKey['analyst_id'], 'asset_created', null,
        'Created via API (key: ' . $apiKey['name'] . ')');

    if (array_key_exists('warranty_expiry', $body) && $body['warranty_expiry']) {
        require_once dirname(__DIR__, 3) . '/includes/asset_warranty_calendar.php';
        try { syncAssetWarrantyCalendar($conn); } catch (Exception $syncEx) { /* non-critical */ }
    }

    apiRespond(apiSerializeAsset($conn, apiLoadAsset($conn, $assetId)), 201);
}

// ---------------------------------------------------------------------------
// PATCH /assets/{id}
// ---------------------------------------------------------------------------
function apiAssetsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $assetId = $params[0];
    $current = apiLoadAsset($conn, $assetId);
    if (!$body) {
        apiError(422, 'missing_field', 'No fields to update.');
    }

    $map = apiAssetFieldMap();
    $updates = [];
    $args    = [];
    $audits  = [];   // [fieldKey, oldDisplay, newDisplay]
    $warrantyChanged = false;

    foreach ($body as $field => $rawValue) {
        if (!isset($map[$field])) {
            continue; // unknown fields are ignored, like the internal endpoints
        }
        $def = $map[$field];
        $newValue = apiAssetValidateField($conn, $field, $rawValue, $def);

        if ($field === 'hostname') {
            if ($newValue === null) {
                apiError(422, 'invalid_field', "'hostname' cannot be blank.");
            }
            $dup = $conn->prepare("SELECT id FROM assets WHERE hostname = ? AND id != ?");
            $dup->execute([$newValue, $assetId]);
            if ($dup->fetchColumn()) {
                apiError(409, 'conflict', 'Another asset already uses this hostname.');
            }
        }

        // Normalise the current value the same way for change detection.
        $oldValue = $current[$field];
        if (in_array($def['kind'], ['lookup', 'supplier', 'int'], true) && $oldValue !== null) {
            $oldValue = (int)$oldValue;
        }
        $comparableNew = ($def['kind'] === 'decimal' && $newValue !== null) ? (float)$newValue : $newValue;
        $comparableOld = ($def['kind'] === 'decimal' && $oldValue !== null) ? (float)$oldValue : $oldValue;
        if ($comparableNew === $comparableOld || (string)$comparableNew === (string)$comparableOld && $comparableNew !== null && $comparableOld !== null) {
            continue; // no actual change
        }

        $updates[] = "$field = ?";
        $args[]    = $newValue;
        $audits[]  = [
            $def['audit'],
            apiAssetAuditDisplay($conn, $field, $oldValue, $def),
            apiAssetAuditDisplay($conn, $field, $newValue, $def),
        ];
        if ($field === 'warranty_expiry') {
            $warrantyChanged = true;
        }
    }

    if (!$updates) {
        apiRespond(apiSerializeAsset($conn, $current)); // idempotent PATCH
    }

    $args[] = $assetId;
    $conn->prepare('UPDATE assets SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);

    foreach ($audits as [$fieldKey, $old, $new]) {
        apiAssetAuditWrite($conn, $assetId, (int)$apiKey['analyst_id'], $fieldKey, $old, $new);
    }

    // Keep the calendar's warranty events in step (same hook as the UI).
    if ($warrantyChanged) {
        require_once dirname(__DIR__, 3) . '/includes/asset_warranty_calendar.php';
        try { syncAssetWarrantyCalendar($conn); } catch (Exception $syncEx) { /* non-critical */ }
    }

    apiRespond(apiSerializeAsset($conn, apiLoadAsset($conn, $assetId)));
}

// ---------------------------------------------------------------------------
// Assignments — POST/GET /assets/{id}/assignments, DELETE .../{user_id}
// ---------------------------------------------------------------------------
function apiAssetAssignmentsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $params[0]);
    $stmt = $conn->prepare(
        "SELECT ua.user_id, u.display_name, u.email, ua.assigned_datetime, ua.expected_return_date, ua.notes,
                a.full_name AS assigned_by
         FROM users_assets ua
         LEFT JOIN users u ON u.id = ua.user_id
         LEFT JOIN analysts a ON a.id = ua.assigned_by_analyst_id
         WHERE ua.asset_id = ? ORDER BY ua.assigned_datetime ASC"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($r) {
        return [
            'user_id'              => (int)$r['user_id'],
            'name'                 => $r['display_name'],
            'email'                => $r['email'],
            'assigned_at'          => apiIsoDate($r['assigned_datetime']),
            'expected_return_date' => $r['expected_return_date'],
            'notes'                => $r['notes'],
            'assigned_by'          => $r['assigned_by'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

function apiAssetAssignmentsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $assetId = $params[0];
    apiLoadAsset($conn, $assetId);
    $actorId = (int)$apiKey['analyst_id'];

    // Accept user_id or user_email (must be an existing requester — use
    // POST /users with the users.create permission to add one first).
    $userId = null;
    if (isset($body['user_id']) && $body['user_id'] !== '') {
        $userId = (int)$body['user_id'];
        $u = $conn->prepare("SELECT id, display_name FROM users WHERE id = ?");
        $u->execute([$userId]);
    } elseif (isset($body['user_email']) && trim((string)$body['user_email']) !== '') {
        $u = $conn->prepare("SELECT id, display_name FROM users WHERE email = ?");
        $u->execute([strtolower(trim((string)$body['user_email']))]);
    } else {
        apiError(422, 'missing_field', "Provide 'user_id' or 'user_email'.");
    }
    $userRow = $u->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) {
        apiError(422, 'invalid_field', 'Unknown requester. Create them first with POST /users.');
    }
    $userId   = (int)$userRow['id'];
    $userName = $userRow['display_name'];

    $notes = trim((string)($body['notes'] ?? '')) ?: null;
    $expectedReturn = apiParseDateOnly($body['expected_return_date'] ?? null, 'expected_return_date');

    $check = $conn->prepare("SELECT id FROM users_assets WHERE asset_id = ? AND user_id = ?");
    $check->execute([$assetId, $userId]);
    if ($check->fetchColumn()) {
        apiError(409, 'conflict', 'This user is already assigned to this asset.');
    }

    $conn->prepare(
        "INSERT INTO users_assets (asset_id, user_id, assigned_by_analyst_id, notes, expected_return_date, assigned_datetime)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
    )->execute([$assetId, $userId, $actorId, $notes, $expectedReturn]);

    // Custody trail (best-effort, like the UI).
    try {
        $conn->prepare(
            "INSERT INTO asset_checkout_log (asset_id, user_id, user_name, action, expected_return_date, analyst_id, notes, action_datetime)
             VALUES (?, ?, ?, 'checkout', ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$assetId, $userId, $userName, $expectedReturn, $actorId, $notes]);
    } catch (Exception $clogEx) { /* custody log not critical */ }

    apiAssetAuditWrite($conn, $assetId, $actorId, 'assigned_user', null, $userName);

    apiRespond([
        'asset_id'             => $assetId,
        'user_id'              => $userId,
        'name'                 => $userName,
        'expected_return_date' => $expectedReturn,
        'notes'                => $notes,
    ], 201);
}

function apiAssetAssignmentsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    [$assetId, $userId] = $params;
    apiLoadAsset($conn, $assetId);
    $actorId = (int)$apiKey['analyst_id'];

    // Snapshot before removal, for the custody trail + audit (mirrors the UI).
    $snap = $conn->prepare(
        "SELECT u.display_name, ua.expected_return_date
         FROM users_assets ua INNER JOIN users u ON u.id = ua.user_id
         WHERE ua.asset_id = ? AND ua.user_id = ?"
    );
    $snap->execute([$assetId, $userId]);
    $row = $snap->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Assignment not found.');
    }

    $conn->prepare("DELETE FROM users_assets WHERE asset_id = ? AND user_id = ?")->execute([$assetId, $userId]);

    try {
        $conn->prepare(
            "INSERT INTO asset_checkout_log (asset_id, user_id, user_name, action, expected_return_date, analyst_id, action_datetime)
             VALUES (?, ?, ?, 'checkin', ?, ?, UTC_TIMESTAMP())"
        )->execute([$assetId, $userId, $row['display_name'], $row['expected_return_date'], $actorId]);
    } catch (Exception $clogEx) { /* custody log not critical */ }

    apiAssetAuditWrite($conn, $assetId, $actorId, 'assigned_user', $row['display_name'], null);

    apiRespond(['asset_id' => $assetId, 'user_id' => $userId, 'unassigned' => true]);
}

// ---------------------------------------------------------------------------
// GET /assets/{id}/history  +  /custody
// ---------------------------------------------------------------------------
function apiAssetHistoryList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $params[0]);
    $stmt = $conn->prepare(
        "SELECT h.id, h.field_name, h.old_value, h.new_value, h.created_datetime,
                h.analyst_id, a.full_name AS analyst_name
         FROM asset_history h LEFT JOIN analysts a ON a.id = h.analyst_id
         WHERE h.asset_id = ? ORDER BY h.created_datetime DESC, h.id DESC"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($e) {
        return [
            'id'         => (int)$e['id'],
            'field'      => $e['field_name'],
            'old_value'  => $e['old_value'],
            'new_value'  => $e['new_value'],
            'analyst'    => $e['analyst_id'] === null ? null : ['id' => (int)$e['analyst_id'], 'name' => $e['analyst_name']],
            'created_at' => apiIsoDate($e['created_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

function apiAssetCustodyList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $params[0]);
    $stmt = $conn->prepare(
        "SELECT c.id, c.user_id, c.user_name, c.action, c.expected_return_date, c.notes,
                c.action_datetime, a.full_name AS analyst_name
         FROM asset_checkout_log c LEFT JOIN analysts a ON a.id = c.analyst_id
         WHERE c.asset_id = ? ORDER BY c.action_datetime DESC, c.id DESC"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($e) {
        return [
            'id'                   => (int)$e['id'],
            'action'               => $e['action'],
            'user_id'              => $e['user_id'] !== null ? (int)$e['user_id'] : null,
            'user_name'            => $e['user_name'],
            'expected_return_date' => $e['expected_return_date'],
            'notes'                => $e['notes'],
            'by'                   => $e['analyst_name'],
            'at'                   => apiIsoDate($e['action_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// ---------------------------------------------------------------------------
// Inventory reads — disks, network adapters, devices, software
// ---------------------------------------------------------------------------
function apiAssetDisksList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $params[0]);
    $stmt = $conn->prepare(
        "SELECT id, drive, label, file_system, size_bytes, free_bytes, used_percent
         FROM asset_disks WHERE asset_id = ? ORDER BY drive"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($d) {
        return [
            'id'           => (int)$d['id'],
            'drive'        => $d['drive'],
            'label'        => $d['label'],
            'file_system'  => $d['file_system'],
            'size_bytes'   => $d['size_bytes'] !== null ? (int)$d['size_bytes'] : null,
            'free_bytes'   => $d['free_bytes'] !== null ? (int)$d['free_bytes'] : null,
            'used_percent' => $d['used_percent'] !== null ? (float)$d['used_percent'] : null,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

function apiAssetNetworkAdaptersList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $params[0]);
    $stmt = $conn->prepare(
        "SELECT id, name, mac_address, ip_address, subnet_mask, gateway, dhcp_enabled
         FROM asset_network_adapters WHERE asset_id = ? ORDER BY name"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($n) {
        return [
            'id'           => (int)$n['id'],
            'name'         => $n['name'],
            'mac_address'  => $n['mac_address'],
            'ip_address'   => $n['ip_address'],
            'subnet_mask'  => $n['subnet_mask'],
            'gateway'      => $n['gateway'],
            'dhcp_enabled' => $n['dhcp_enabled'] === null ? null : (bool)$n['dhcp_enabled'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

function apiAssetDevicesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $params[0]);
    $stmt = $conn->prepare(
        "SELECT id, device_class, device_name, status, manufacturer, driver_version, driver_date
         FROM asset_devices WHERE asset_id = ? ORDER BY device_class, device_name"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($d) {
        return [
            'id'             => (int)$d['id'],
            'device_class'   => $d['device_class'],
            'device_name'    => $d['device_name'],
            'status'         => $d['status'],
            'manufacturer'   => $d['manufacturer'],
            'driver_version' => $d['driver_version'],
            'driver_date'    => $d['driver_date'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

function apiAssetSoftwareList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $params[0]);
    // software_inventory_detail keys the asset as host_id.
    $sql = "SELECT d.id, a.display_name, a.publisher, d.display_version, d.install_date,
                   d.system_component, d.last_seen
            FROM software_inventory_detail d
            INNER JOIN software_inventory_apps a ON a.id = d.app_id
            WHERE d.host_id = ?";
    $args = [$params[0]];
    if (($_GET['include_components'] ?? '') !== 'true') {
        $sql .= " AND (d.system_component IS NULL OR d.system_component = 0)";
    }
    $sql .= " ORDER BY a.display_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute($args);
    apiRespond(array_map(function ($s) {
        return [
            'id'               => (int)$s['id'],
            'name'             => $s['display_name'],
            'publisher'        => $s['publisher'],
            'version'          => $s['display_version'],
            'install_date'     => $s['install_date'],
            'system_component' => (bool)$s['system_component'],
            'last_seen'        => apiIsoDate($s['last_seen']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// ---------------------------------------------------------------------------
// Reference lookups
// ---------------------------------------------------------------------------
function apiAssetTypesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query("SELECT id, name, description, is_active FROM asset_types ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($r) {
        return ['id' => (int)$r['id'], 'name' => $r['name'], 'description' => $r['description'], 'is_active' => (bool)$r['is_active']];
    }, $rows));
}

function apiAssetStatusesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query("SELECT id, name, description, is_active FROM asset_status_types ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($r) {
        return ['id' => (int)$r['id'], 'name' => $r['name'], 'description' => $r['description'], 'is_active' => (bool)$r['is_active']];
    }, $rows));
}

function apiAssetLocationsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT id, name, parent_id, display_order FROM asset_locations
         ORDER BY (parent_id IS NULL) DESC, parent_id, display_order, name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($r) use ($conn) {
        $id = (int)$r['id'];
        return [
            'id'        => $id,
            'name'      => $r['name'],
            'parent_id' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
            'path'      => apiAssetLocationPath($conn, $id),
        ];
    }, $rows));
}

function apiSuppliersList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT id, COALESCE(NULLIF(TRIM(trading_name), ''), legal_name) AS name, supplies_assets
         FROM suppliers ORDER BY name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($r) {
        return ['id' => (int)$r['id'], 'name' => $r['name'], 'supplies_assets' => (bool)$r['supplies_assets']];
    }, $rows));
}
