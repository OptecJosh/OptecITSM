<?php
/**
 * Asset warranty → Calendar sync.
 *
 * Keeps a set of auto-generated, all-day "Warranty expiry" calendar events in
 * step with assets' warranty_expiry dates, gated by the asset_warranty_surface
 * setting. The events carry source = 'asset_warranty' so a full resync can
 * delete + reinsert its own events without disturbing user-created ones.
 *
 * Called opportunistically when an asset's warranty date changes
 * (update_asset_field.php) and on demand when the setting is saved
 * (sync_warranty_calendar.php). Cheap full resync — warranty edits are rare and
 * the event set is small.
 */

if (!function_exists('syncAssetWarrantyCalendar')) {
    /**
     * @param PDO $conn
     * @return array{success:bool, synced?:int, error?:string}
     */
    function syncAssetWarrantyCalendar(PDO $conn): array
    {
        try {
            // The feature depends on schema added alongside it; bail quietly if
            // a DB verification hasn't run yet.
            if (!awcColumnExists($conn, 'calendar_events', 'source')
                || !awcColumnExists($conn, 'assets', 'warranty_expiry')) {
                return ['success' => false, 'error' => 'Schema not ready'];
            }

            $surface = awcGetSetting($conn, 'asset_warranty_surface', 'dashboard');
            $onCalendar = in_array($surface, ['calendar', 'both'], true);

            // Always clear our own events first.
            $conn->exec("DELETE FROM calendar_events WHERE source = 'asset_warranty'");

            if (!$onCalendar) {
                return ['success' => true, 'synced' => 0];
            }

            $categoryId = awcEnsureWarrantyCategory($conn);

            $rows = $conn->query(
                "SELECT id, hostname, warranty_expiry
                 FROM assets
                 WHERE warranty_expiry IS NOT NULL"
            )->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                return ['success' => true, 'synced' => 0];
            }

            $ins = $conn->prepare(
                "INSERT INTO calendar_events
                    (title, description, category_id, start_datetime, end_datetime, all_day, created_by, source)
                 VALUES (?, ?, ?, ?, ?, 1, 0, 'asset_warranty')"
            );

            $n = 0;
            foreach ($rows as $r) {
                $host = $r['hostname'] !== null && $r['hostname'] !== '' ? $r['hostname'] : ('Asset #' . $r['id']);
                $title = 'Warranty expiry: ' . $host;
                $dt = substr($r['warranty_expiry'], 0, 10) . ' 00:00:00';
                $ins->execute([
                    $title,
                    'Auto-generated from the asset record. Edit the warranty date on the asset to change this.',
                    $categoryId,
                    $dt,
                    $dt,
                ]);
                $n++;
            }
            return ['success' => true, 'synced' => $n];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** Find or create the "Warranty" calendar category; returns its id (or null). */
    function awcEnsureWarrantyCategory(PDO $conn): ?int
    {
        $sel = $conn->prepare("SELECT id FROM calendar_categories WHERE name = ? LIMIT 1");
        $sel->execute(['Warranty']);
        $id = $sel->fetchColumn();
        if ($id) {
            return (int)$id;
        }
        try {
            $ins = $conn->prepare("INSERT INTO calendar_categories (name, color, is_active) VALUES (?, ?, 1)");
            $ins->execute(['Warranty', '#d13438']);
            return (int)$conn->lastInsertId();
        } catch (Exception $e) {
            return null; // category column shape differs / table missing — events just go uncategorised
        }
    }

    function awcGetSetting(PDO $conn, string $key, string $default): string
    {
        try {
            $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return ($v === false || $v === null || $v === '') ? $default : (string)$v;
        } catch (Exception $e) {
            return $default;
        }
    }

    function awcColumnExists(PDO $conn, string $table, string $col): bool
    {
        try {
            $s = $conn->prepare(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
            );
            $s->execute([$table, $col]);
            return (int)$s->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}
