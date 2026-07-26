<?php
/**
 * Test-data reset — the registry of what counts as "records" and what counts as
 * "configuration".
 *
 * After a UAT round you want the platform you configured and none of the data you
 * loaded into it. That split is not guessable, which is the whole reason this file
 * exists: `forms` is configuration but `form_submissions` is data; `lms_courses` is
 * content but `lms_progress` is data; `kpi_definitions` is a catalogue but
 * `kpi_measurements` is data; `knowledge_tags` is a vocabulary but the articles
 * using it are records.
 *
 * THE RULE THAT KEEPS THIS SAFE: only tables named below are ever deleted.
 * Everything else is kept. A table added to the schema next month is therefore
 * kept by default — the failure mode is leftover rows, never lost configuration.
 *
 * Never listed, and never deletable here: analysts, teams, tenants, RBAC, module
 * access, system_settings, mailboxes, messaging channels, API keys, SLA policies
 * and calendars, every status/priority/type lookup, CMDB classes and property
 * definitions, custom field definitions, forms, service catalogue items, workflow
 * definitions, KPI definitions, the certification catalogue, LMS courses.
 *
 * Order matters within a group: children are listed before their parents so the
 * deletes stay sane even though FK checks are suspended during a run.
 *
 * A table entry is normally just its name. Where a table is SHARED between a
 * wiped and a kept concern it is written as ['table' => x, 'where' => '...']
 * instead, so only the relevant rows go:
 *
 *   custom_field_values        holds ticket values AND CI values in one table,
 *                              discriminated by entity_type — wiping tickets must
 *                              not take the CMDB's property values with it.
 *   software_dashboard_widgets mostly dashboard layout, but a widget pinned to a
 *                              specific application would dangle once that app is
 *                              gone; only the pinned ones are removed.
 *
 * Those WHERE fragments come from this file only, never from a request — the same
 * rule that makes the table names safe to interpolate.
 */

/**
 * Groups of deletable tables. Keyed by group id.
 *
 *   label       what the UI calls it
 *   description one line on what disappears
 *   tables      deleted in this order, children first
 */
function data_reset_groups(): array {
    return [
        'tickets' => [
            'label'       => 'Tickets & conversations',
            'description' => 'Tickets and everything hanging off them: audit trail, notes, time entries, CSAT, watchers, links, approvals, tags, affected CIs, SLA snapshots, KPI capture, recordings, the stored emails and web-chat transcripts.',
            'tables'      => [
                'ticket_audit', 'ticket_notes', 'ticket_time_entries', 'ticket_csat_responses',
                'ticket_watchers', 'ticket_links', 'ticket_approvals', 'ticket_tag_map',
                'ticket_cmdb_objects', 'ticket_sla_snapshot', 'ticket_escalations',
                'ticket_hold_events', 'ticket_qa_reviews', 'ticket_recordings',
                'ticket_view_sessions',
                'email_attachments', 'emails',
                'webchat_messages', 'webchat_conversations',
                ['table' => 'custom_field_values', 'where' => "entity_type = 'ticket'"],
                'tickets',
            ],
        ],
        'changes' => [
            'label'       => 'Changes',
            'description' => 'Change records with their audit trail, comments, checklists, attachments, notifications and their links to tickets, problems and CIs. Freeze windows are configuration and are kept.',
            'tables'      => [
                'change_audit', 'change_comments', 'change_checklist_items', 'change_attachments',
                'change_notifications', 'change_relations', 'change_tickets', 'change_cmdb_objects',
                'change_cab_members',        // per-change roster + votes, not a standing CAB list
                'changes',
            ],
        ],
        'problems' => [
            'label'       => 'Problems (KEDB)',
            'description' => 'Problem records, their notes journal, audit trail and incident links.',
            'tables'      => ['problem_notes', 'problem_audit', 'problem_tickets', 'problems'],
        ],
        'assets' => [
            'label'       => 'Assets & discovery data',
            'description' => 'Asset inventory, hardware detail from the agent, history, checkout log, user assignments, and the Intune/vCenter sync results. Types, statuses and locations are kept.',
            'tables'      => [
                'asset_history', 'asset_devices', 'asset_disks', 'asset_network_adapters',
                'asset_checkout_log', 'users_assets',
                'intune_app_sync_job_assets', 'intune_app_sync_jobs', 'intune_devices', 'intune_sync_jobs',
                'assets',
            ],
        ],
        'software' => [
            'label'       => 'Software inventory & licences',
            'description' => 'Discovered applications, their per-host detail, the sync log, and licence records.',
            'tables'      => [
                'software_inventory_detail', 'software_inventory_log', 'software_licences',
                ['table' => 'software_dashboard_widgets', 'where' => 'app_id IS NOT NULL'],
                'software_inventory_apps',
            ],
        ],
        'cmdb' => [
            'label'       => 'CMDB items & diagrams',
            'description' => 'Configuration items, their property values, relationships, per-CI SLA policy overrides and the network diagrams drawn over them. Classes, property definitions, icons and relationship types are kept.',
            'tables'      => [
                'cmdb_object_properties', 'cmdb_object_relationships', 'cmdb_object_sla_policies',
                'network_diagram_connectors', 'network_diagram_nodes', 'network_diagrams',
                ['table' => 'custom_field_values', 'where' => "entity_type = 'cmdb_object'"],
                'cmdb_objects',
            ],
        ],
        'contracts' => [
            'label'       => 'Contracts, suppliers & RFPs',
            'description' => 'Contracts with their term values and asset coverage, suppliers and their contacts, and the RFP builder\'s documents and scoring. Statuses, types and payment schedules are kept.',
            'tables'      => [
                'contract_term_values', 'contract_assets',
                'rfp_scores', 'rfp_conflicts', 'rfp_consolidated_sources', 'rfp_consolidated_requirements',
                'rfp_extracted_requirements', 'rfp_output_sections', 'rfp_section_history',
                'rfp_document_sections', 'rfp_documents', 'rfp_processing_log', 'rfp_invited_suppliers',
                'rfp_departments', 'rfp_categories', 'rfps',
                'contracts', 'contacts', 'suppliers',
            ],
        ],
        'customers' => [
            'label'       => 'Customers',
            'description' => 'Customer accounts, their contacts and their CI links. Tickets keep working — the links are set to NULL.',
            // Contacts first: they cascade from customers anyway, but naming them
            // keeps the wipe explicit rather than relying on the constraint.
            'tables'      => ['customer_contacts', 'customer_cmdb_objects', 'customers'],
        ],
        'knowledge' => [
            'label'       => 'Knowledge articles & announcements',
            'description' => 'Articles with their version history, ratings and tag assignments, plus portal announcements. The tag vocabulary itself is kept.',
            'tables'      => [
                'knowledge_article_versions', 'knowledge_article_ratings', 'knowledge_article_tags',
                'knowledge_articles', 'announcements',
            ],
        ],
        'forms' => [
            'label'       => 'Form submissions',
            'description' => 'Submitted forms and their answers. The form definitions and their versions are kept.',
            'tables'      => ['form_submission_data', 'form_submissions'],
        ],
        'tasks' => [
            'label'       => 'Tasks, calendar & rota',
            'description' => 'Tasks with comments and tag assignments, calendar events, and on-call rota entries. Statuses, priorities, the tag list and rota locations are kept.',
            'tables' => [
                'task_comments', 'task_tag_map', 'tasks',
                'calendar_events',
                'ticket_rota_entries', 'ticket_rota_shifts',
            ],
        ],
        'checks' => [
            'label'       => 'Morning check results',
            'description' => 'Recorded check results. The check definitions are kept.',
            'tables'      => ['morningChecks_Results'],
        ],
        'status' => [
            'label'       => 'Service status incidents',
            'description' => 'Published status-page incidents and the services they affected. The service list itself is kept.',
            'tables'      => ['status_incident_services', 'status_incidents'],
        ],
        'people' => [
            'label'       => 'Staff records (overtime, training, journal)',
            'description' => 'Overtime claims and their audit trail, certifications held, training completed, development journal entries, and LMS course progress. Analysts themselves, the certification catalogue and the courses are kept.',
            'tables'      => [
                'overtime_audit', 'overtime_requests',
                'analyst_certifications', 'analyst_training_records', 'analyst_journal_entries',
                'lms_cmi_data', 'lms_progress',
            ],
        ],
        'kpi' => [
            'label'       => 'KPI measurements',
            'description' => 'Every recorded monthly value. The ~69 KPI definitions are kept, so the scorecards stay intact but empty.',
            'tables'      => ['kpi_measurements'],
        ],
        'portal_users' => [
            'label'       => 'Portal users',
            'description' => 'Self-service portal accounts, their preferences and SSO identities. Tickets they raised keep working — the requester link is set to NULL. Analysts are never touched.',
            'tables'      => ['user_preferences', 'user_sso_identities', 'users'],
        ],
        'logs' => [
            'label'       => 'Logs, automation history & generated data',
            'description' => 'Application log, cron run history, SLA notification ledger, workflow executions and the fire-once ledger, webhook deliveries, mailbox activity, login bans, reset tokens, trusted devices, and the wiki code-scan results.',
            'tables'      => [
                'system_logs', 'sla_cron_runs', 'sla_notifications_sent',
                'workflow_executions', 'workflow_scheduled_emissions', 'webhook_deliveries',
                'mailbox_activity_log', 'inbound_webhook_events', 'ip_login_bans', 'password_reset_tokens', 'trusted_devices',
                'api_rate_limits', 'api_key_rate_limits',
                'wiki_function_calls', 'wiki_db_references', 'wiki_dependencies', 'wiki_session_vars',
                'wiki_functions', 'wiki_classes', 'wiki_files', 'wiki_scan_runs',
            ],
        ],
    ];
}

/** The phrase a caller must type to confirm a destructive run. */
function data_reset_confirm_phrase(): string { return 'DELETE TEST DATA'; }

/** Does this table exist on this install? Cached. */
function data_reset_table_exists(PDO $conn, string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $stmt->execute([$table]);
        $cache[$table] = (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

/** Normalise a registry entry into ['table' => ..., 'where' => ?string]. */
function data_reset_entry($entry): array {
    if (is_array($entry)) {
        return ['table' => (string)$entry['table'], 'where' => $entry['where'] ?? null];
    }
    return ['table' => (string)$entry, 'where' => null];
}

/** Row count for an entry (honouring its WHERE), or null if the table isn't there. */
function data_reset_count(PDO $conn, array $entry): ?int {
    if (!data_reset_table_exists($conn, $entry['table'])) return null;
    try {
        $sql = "SELECT COUNT(*) FROM `{$entry['table']}`"
             . ($entry['where'] !== null ? " WHERE {$entry['where']}" : '');
        return (int)$conn->query($sql)->fetchColumn();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * What a run would do. Groups are validated against the registry, so an unknown
 * group id can never widen the blast radius.
 *
 * @param string[] $groupIds
 * @return array{groups:array,tables:array,total:int,missing:array}
 */
function data_reset_plan(PDO $conn, array $groupIds): array {
    $registry = data_reset_groups();
    $groups = [];
    $tables = [];          // ordered, deduped across groups
    $missing = [];
    $total = 0;

    foreach ($groupIds as $id) {
        if (!isset($registry[$id])) continue;
        $rows = 0;
        $detail = [];
        foreach ($registry[$id]['tables'] as $rawEntry) {
            $entry = data_reset_entry($rawEntry);
            $count = data_reset_count($conn, $entry);
            if ($count === null) { $missing[] = $entry['table']; continue; }
            $detail[] = [
                'table' => $entry['table'],
                'where' => $entry['where'],
                'rows'  => $count,
            ];
            $rows += $count;
            // Dedupe on table + filter, so the same table narrowed two different
            // ways (custom_field_values) survives as two separate deletes.
            $key = $entry['table'] . '|' . (string)$entry['where'];
            if (!isset($tables[$key])) $tables[$key] = $entry;
        }
        $groups[] = [
            'id'          => $id,
            'label'       => $registry[$id]['label'],
            'description' => $registry[$id]['description'],
            'rows'        => $rows,
            'tables'      => $detail,
        ];
        $total += $rows;
    }

    return [
        'groups'  => $groups,
        'tables'  => array_values($tables),
        'total'   => $total,
        'missing' => array_values(array_unique($missing)),
    ];
}

/**
 * Execute a plan. One transaction with FK checks suspended, so a failure part-way
 * leaves the database exactly as it was rather than half-wiped.
 *
 * DELETE rather than TRUNCATE deliberately: TRUNCATE is DDL, commits implicitly,
 * and would take the transaction guarantee away. AUTO_INCREMENT counters are
 * therefore not reset, which costs nothing but tidiness.
 *
 * @return array{deleted:array<string,int>,total:int}
 */
function data_reset_execute(PDO $conn, array $tables): array {
    $deleted = [];
    $total = 0;

    $conn->exec('SET FOREIGN_KEY_CHECKS = 0');
    $conn->beginTransaction();
    try {
        foreach ($tables as $rawEntry) {
            $entry = data_reset_entry($rawEntry);
            if (!data_reset_table_exists($conn, $entry['table'])) continue;
            $sql = "DELETE FROM `{$entry['table']}`"
                 . ($entry['where'] !== null ? " WHERE {$entry['where']}" : '');
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $n = $stmt->rowCount();
            $label = $entry['table'] . ($entry['where'] !== null ? ' (' . $entry['where'] . ')' : '');
            $deleted[$label] = $n;
            $total += $n;
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        $conn->exec('SET FOREIGN_KEY_CHECKS = 1');
        throw $e;
    }
    $conn->exec('SET FOREIGN_KEY_CHECKS = 1');

    return ['deleted' => $deleted, 'total' => $total];
}

/**
 * Record the wipe. Called AFTER the deletes on purpose — system_logs is itself in
 * the 'logs' group, so an entry written first would be deleted by the very run it
 * is meant to document.
 */
function data_reset_log(PDO $conn, ?int $analystId, array $groupIds, int $total): void {
    try {
        $who = ($analystId !== null && $analystId > 0) ? $analystId : null;   // null = run from the CLI
        $conn->prepare("INSERT INTO system_logs (log_type, analyst_id, details, created_datetime) VALUES ('data_reset', ?, ?, UTC_TIMESTAMP())")
             ->execute([$who, ($who === null ? 'CLI test-data reset: ' : 'Test-data reset: ')
                              . $total . ' row(s) deleted across groups: ' . implode(', ', $groupIds)]);
    } catch (Exception $e) { /* non-fatal — the wipe itself already succeeded */ }
}
