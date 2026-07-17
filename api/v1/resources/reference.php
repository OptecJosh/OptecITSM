<?php
/**
 * FreeITSM REST API v1 — reference data: the lookups an integration needs to
 * build valid ticket writes (statuses, priorities, ticket types, origins,
 * departments), plus analysts and companies.
 */

// GET /analysts — active analysts (assignment targets).
function apiAnalystsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT id, full_name, email, is_active FROM analysts WHERE is_active = 1 ORDER BY full_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($a) {
        return [
            'id'    => (int)$a['id'],
            'name'  => $a['full_name'],
            'email' => $a['email'],
        ];
    }, $rows));
}

// GET /companies — the companies (tenants) this key can see.
function apiCompaniesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $tenants = getAllTenants($conn, true);
    if (!$tenants) {
        // tenants table not migrated yet — behave as a single Default company.
        apiRespond([['id' => getDefaultTenantId($conn), 'name' => 'Default', 'is_default' => true]]);
    }
    if ($apiKey['company_scope'] !== null) {
        $tenants = array_values(array_filter($tenants, function ($t) use ($apiKey) {
            return in_array($t['id'], $apiKey['company_scope'], true);
        }));
    }
    apiRespond(array_map(function ($t) {
        return ['id' => $t['id'], 'name' => $t['name'], 'is_default' => $t['is_default']];
    }, $tenants));
}

// GET /statuses
function apiStatusesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT id, name, is_closed, is_default, pauses_sla, colour FROM ticket_statuses ORDER BY display_order, name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($s) {
        return [
            'id'         => (int)$s['id'],
            'name'       => $s['name'],
            'is_closed'  => (bool)$s['is_closed'],
            'is_default' => (bool)$s['is_default'],
            'pauses_sla' => (bool)$s['pauses_sla'],
            'colour'     => $s['colour'],
        ];
    }, $rows));
}

// GET /priorities
// SLA targets are per-policy now; report the DEFAULT policy's targets, which is
// what these numbers meant back when a single global SLA lived on the priority.
// (A company on its own policy may resolve different targets — see sla_policies.)
function apiPrioritiesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT p.id, p.name, t.sla_response_minutes, t.sla_resolution_minutes
           FROM ticket_priorities p
      LEFT JOIN sla_policies pol ON pol.is_default = 1
      LEFT JOIN sla_policy_targets t ON t.priority_id = p.id AND t.policy_id = pol.id
       ORDER BY p.display_order, p.name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($p) {
        return [
            'id'                     => (int)$p['id'],
            'name'                   => $p['name'],
            'sla_response_minutes'   => $p['sla_response_minutes'] !== null ? (int)$p['sla_response_minutes'] : null,
            'sla_resolution_minutes' => $p['sla_resolution_minutes'] !== null ? (int)$p['sla_resolution_minutes'] : null,
        ];
    }, $rows));
}

// GET /ticket-types (?company_id= applies the per-company add/hide overrides)
function apiTicketTypesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiTenantConfigList($conn, $apiKey, 'ticket_types', 'ticket_type');
}

// GET /origins (?company_id= applies the per-company add/hide overrides)
function apiOriginsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiTenantConfigList($conn, $apiKey, 'ticket_origins', 'ticket_origin');
}

/** Shared list for the tenant-overridable config tables (add/hide model). */
function apiTenantConfigList(PDO $conn, array $apiKey, string $table, string $entityType): void {
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $cid = (int)$_GET['company_id'];
        if (!apiKeyCanAccessTenant($conn, $apiKey, $cid)) {
            apiError(403, 'forbidden', 'This API key is not scoped to that company.');
        }
        $rows = getTenantConfigRows($conn, $table, $entityType, $cid, 'id, name, is_active');
    } else {
        $rows = $conn->query("SELECT id, name, is_active FROM {$table} ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
    }
    apiRespond(array_map(function ($r) {
        return [
            'id'        => (int)$r['id'],
            'name'      => $r['name'],
            'is_active' => (bool)$r['is_active'],
        ];
    }, $rows));
}

// GET /departments
function apiDepartmentsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT id, name, description, is_active FROM departments ORDER BY display_order, name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($d) {
        return [
            'id'          => (int)$d['id'],
            'name'        => $d['name'],
            'description' => $d['description'],
            'is_active'   => (bool)$d['is_active'],
        ];
    }, $rows));
}
