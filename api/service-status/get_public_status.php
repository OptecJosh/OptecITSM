<?php
/**
 * API (PUBLIC, no auth): current service statuses + active incidents for the
 * public status page (Phase 7b).
 *
 * Exposure is OPT-IN: returns data only when the `status_page_public` system
 * setting is '1' (an admin toggles it on Service Status → Settings). Otherwise
 * it reports { enabled: false } and nothing else. Only public-safe fields are
 * ever returned — service names/statuses and incident titles/updates.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

try {
    $conn = connectToDatabase();

    $flag = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'status_page_public' LIMIT 1")->fetchColumn();
    if ((string)$flag !== '1') {
        echo json_encode(['success' => true, 'enabled' => false]);
        exit;
    }

    // Each active service's worst current (unresolved) impact, else 'Operational'
    // — the same derivation the portal dashboard uses.
    $svc = $conn->query(
        "SELECT ss.id, ss.name, ss.description,
            COALESCE(
                (SELECT il.name
                   FROM status_incident_services sis
                   JOIN status_incidents si ON sis.incident_id = si.id
                   JOIN service_impact_levels il ON il.id = sis.impact_level_id
              LEFT JOIN service_incident_statuses sst ON sst.id = si.status_id
                  WHERE sis.service_id = ss.id
                    AND si.resolved_datetime IS NULL
                    AND (sst.is_resolved = 0 OR sst.id IS NULL)
               ORDER BY il.severity_order ASC
                  LIMIT 1),
                'Operational'
            ) AS current_status
           FROM status_services ss
          WHERE ss.is_active = 1
       ORDER BY ss.display_order, ss.name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $services = [];
    foreach ($svc as $s) {
        $services[] = [
            'name'        => $s['name'],
            'description' => $s['description'],
            'status'      => $s['current_status'],
            'operational' => ($s['current_status'] === 'Operational'),
        ];
    }

    // Open incidents (not resolved), with affected service names + latest update.
    $inc = $conn->query(
        "SELECT si.title, si.comment, si.updated_datetime,
                COALESCE(sst.name, 'Investigating') AS status_name,
                GROUP_CONCAT(ss.name ORDER BY ss.name SEPARATOR ', ') AS services
           FROM status_incidents si
      LEFT JOIN service_incident_statuses sst ON sst.id = si.status_id
      LEFT JOIN status_incident_services sis ON sis.incident_id = si.id
      LEFT JOIN status_services ss ON ss.id = sis.service_id
          WHERE si.resolved_datetime IS NULL
       GROUP BY si.id
       ORDER BY si.updated_datetime DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $incidents = [];
    foreach ($inc as $i) {
        $incidents[] = [
            'title'    => $i['title'],
            'comment'  => $i['comment'],
            'status'   => $i['status_name'],
            'services' => $i['services'],
            'updated'  => $i['updated_datetime'],
        ];
    }

    // Active announcements flagged for the public status page (Phase 7b).
    $announcements = [];
    try {
        $announcements = $conn->query(
            "SELECT title, body FROM announcements
              WHERE is_active = 1 AND show_status = 1
                AND (starts_at IS NULL OR starts_at <= UTC_TIMESTAMP())
                AND (ends_at IS NULL OR ends_at >= UTC_TIMESTAMP())
           ORDER BY id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $announcements = []; }

    echo json_encode(['success' => true, 'enabled' => true, 'services' => $services, 'incidents' => $incidents, 'announcements' => $announcements]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
