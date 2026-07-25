<?php
/**
 * API: the datasets that can be mass-imported, with their column contracts.
 * GET → { success, datasets:[{key,label,group,table,notes,match,creates_only,
 *         tenant, columns:[{name,type,required,values}], lookups:[{name,of,required}]}] }
 *
 * Admin only (importing writes to every module), and a dataset is only offered if
 * the admin also has that module. The UI builds its column help and templates
 * from this, so the contract can never drift from the importer.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // auth + admin
require_once '../../includes/functions.php';
require_once '../../includes/data_import.php';
header('Content-Type: application/json');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $moduleNames = getModuleRegistry();

    $out = [];
    foreach (data_import_datasets() as $key => $spec) {
        if (!analystCanAccessModule($conn, $analystId, $spec['module'])) continue;

        $columns = [];
        foreach ($spec['columns'] as $name => $c) {
            $columns[] = [
                'name'     => $name,
                'type'     => $c['type'] ?? 'string',
                'required' => !empty($c['required']),
                'values'   => $c['values'] ?? null,
            ];
        }
        $lookups = [];
        foreach ($spec['lookups'] ?? [] as $name => $lk) {
            $lookups[] = [
                'name'     => $name,
                'of'       => $lk['table'],
                'by'       => $lk['name'],
                'required' => !empty($lk['required']),
            ];
        }

        $out[] = [
            'key'          => $key,
            'label'        => $spec['label'],
            'group'        => $spec['group'] ?? ($moduleNames[$spec['module']] ?? ucfirst($spec['module'])),
            'table'        => $spec['table'],
            'notes'        => $spec['notes'] ?? '',
            'match'        => $spec['match'] ?? null,
            'upsert_on'    => $spec['upsert_on'] ?? null,
            'creates_only' => empty($spec['match']) && empty($spec['upsert_on']),
            'tenant'       => !empty($spec['tenant']),
            'columns'      => $columns,
            'lookups'      => $lookups,
            'template'     => data_import_template_columns($spec),
        ];
    }

    echo json_encode(['success' => true, 'datasets' => $out, 'row_cap' => data_import_row_cap()]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
