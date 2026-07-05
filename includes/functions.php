<?php
/**
 * Shared Functions
 * Include this file in any script that needs common functionality
 *
 * Usage: require_once '../includes/functions.php'; (from api folder)
 *        require_once 'includes/functions.php'; (from root folder)
 */

/**
 * Connect to MySQL database using PDO
 *
 * @return PDO Database connection
 * @throws Exception If connection fails
 */
function connectToDatabase() {
    $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $conn = new PDO($dsn, DB_USERNAME, DB_PASSWORD);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $conn;
}

/**
 * Get the list of modules an analyst is allowed to access.
 *
 * @param PDO $conn Database connection
 * @param int $analyst_id Analyst ID
 * @return array|null Null means all access; array of module_key strings if restricted
 */
function getAnalystAllowedModules($conn, $analyst_id) {
    $sql = "SELECT module_key FROM analyst_modules WHERE analyst_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$analyst_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($rows)) {
        return null; // No restrictions — full access
    }

    // Always include system module
    if (!in_array('system', $rows)) {
        $rows[] = 'system';
    }

    return $rows;
}

/**
 * Fire a settings/CRUD workflow event ({entity}.{created|updated|deleted}) from a
 * UI settings endpoint, without each file having to require the workflow engine.
 * Lazily loads the engine and is fully self-safe — swallows any Throwable
 * (including a missing engine on a minimal install) so it can NEVER affect the
 * save it follows. Funnels into the exact same WorkflowEngine::dispatch() path as
 * every other event, so a webhook fires identically however it was emitted.
 */
function wf_emit(string $entity, string $action, int $id, ?string $name = null): void
{
    try {
        require_once __DIR__ . '/../workflow/includes/engine.php';
        WorkflowEngine::emitCrud($entity, $action, $id, $name);
    } catch (Throwable $e) {
        error_log('wf_emit(' . $entity . '.' . $action . ') error: ' . $e->getMessage());
    }
}
?>
