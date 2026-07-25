<?php
/**
 * API: download a CSV template for a dataset — the exact header the importer
 * expects, plus one commented example row showing the format of each field.
 * GET ?dataset=<key>[&examples=0]
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // auth + admin
require_once '../../includes/functions.php';
require_once '../../includes/data_import.php';

/** A plausible example value for a column spec, so the template is self-documenting. */
function template_example(string $name, array $spec): string {
    switch ($spec['type'] ?? 'string') {
        case 'email':    return 'someone@example.com';
        case 'date':     return date('Y-m-d');
        case 'datetime': return date('Y-m-d H:i:s');
        case 'int':      return '1';
        case 'decimal':  return '1.00';
        case 'bool':     return 'yes';
        case 'enum':     return (string)($spec['values'][0] ?? '');
        default:         return 'Example ' . str_replace('_', ' ', $name);
    }
}

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $datasets = data_import_datasets();
    $key = (string)($_GET['dataset'] ?? '');
    if (!isset($datasets[$key])) throw new Exception('Unknown dataset');
    $spec = $datasets[$key];
    if (!analystCanAccessModule($conn, $analystId, $spec['module'])) throw new Exception('You do not have access to that module');

    $cols = data_import_template_columns($spec);
    $withExamples = ($_GET['examples'] ?? '1') !== '0';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $key . '-import-template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $cols);

    if ($withExamples) {
        $row = [];
        foreach ($cols as $c) {
            if (isset($spec['generate'][$c])) {
                $row[] = '';                       // generated on import — leave blank to create
            } elseif (isset($spec['columns'][$c])) {
                $row[] = template_example($c, $spec['columns'][$c]);
            } elseif (isset($spec['lookups'][$c])) {
                // Offer a real value from the target table where there is one — a
                // template that names an existing status beats one that invents a name.
                $lk = $spec['lookups'][$c];
                $val = '';
                try {
                    $s = $conn->query("SELECT `{$lk['name']}` FROM `{$lk['table']}` ORDER BY id LIMIT 1");
                    $val = (string)($s->fetchColumn() ?: '');
                } catch (Exception $e) { $val = ''; }
                $row[] = $val !== '' ? $val : ('existing ' . str_replace('_', ' ', $c));
            } else {
                $row[] = '';
            }
        }
        fputcsv($out, $row);
    }
    fclose($out);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
