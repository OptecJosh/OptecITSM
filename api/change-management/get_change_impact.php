<?php
/**
 * API: CMDB-driven impact + suggested risk for a change (Phase 9c).
 *
 * Unions the "blast radius" of every affected CI linked to the change — the same
 * three buckets api/cmdb/get_object_impact.php uses (hierarchy descendants,
 * object_ref property references, incoming relationships) — deduped across CIs
 * and excluding the linked CIs themselves. The breadth of that set becomes a
 * SUGGESTED impact score (1-5); the analyst still owns the final
 * likelihood × impact (we never auto-write risk).
 *
 * GET ?change_id=<id>. Returns {
 *   success, linked_count, impacted_count,
 *   suggested_impact (1-5|null), suggested_impact_label,
 *   impacted: [{ id, name, class_name, via }]   // capped
 * }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('changes');

/** Breadth of downstream impact → a 1-5 impact score. */
function change_impact_to_score(int $n): int {
    if ($n <= 0)  return 1;
    if ($n <= 2)  return 2;
    if ($n <= 5)  return 3;
    if ($n <= 10) return 4;
    return 5;
}

try {
    $conn = connectToDatabase();
    $changeId = isset($_GET['change_id']) ? (int)$_GET['change_id'] : 0;
    if ($changeId <= 0) throw new Exception('change_id is required');

    // The change's linked CIs (the roots we compute impact from).
    $rootStmt = $conn->prepare("SELECT cmdb_object_id FROM change_cmdb_objects WHERE change_id = ?");
    $rootStmt->execute([$changeId]);
    $rootIds = array_map('intval', $rootStmt->fetchAll(PDO::FETCH_COLUMN));
    $rootSet = array_flip($rootIds);

    $impacted = [];   // object_id => ['id','name','class_name','via']
    $addImpact = function (int $id, ?string $name, ?string $className, string $via) use (&$impacted, $rootSet) {
        if (isset($rootSet[$id]) || isset($impacted[$id])) return;   // skip the roots + dupes
        $impacted[$id] = ['id' => $id, 'name' => $name, 'class_name' => $className, 'via' => $via];
    };

    // Prepared once, reused per root.
    $childStmt = $conn->prepare(
        "SELECT o.id, o.name, c.name AS class_name
           FROM cmdb_objects o JOIN cmdb_classes c ON c.id = o.class_id
          WHERE o.parent_id = ?"
    );
    $propRefStmt = $conn->prepare(
        "SELECT o.id, o.name, c.name AS class_name
           FROM custom_field_values op
           JOIN cmdb_objects o ON o.id = op.entity_id
           JOIN cmdb_classes c ON c.id = o.class_id
          WHERE op.entity_type = 'cmdb_object' AND op.value_ref_id = ?"
    );
    $relRefStmt = $conn->prepare(
        "SELECT o.id, o.name, c.name AS class_name
           FROM cmdb_object_relationships r
           JOIN cmdb_objects o ON o.id = r.from_object_id
           JOIN cmdb_classes c ON c.id = o.class_id
          WHERE r.to_object_id = ?"
    );

    $globalSeen = $rootSet;   // guards the descendant walk against cross-root revisits
    $hops = 0;
    foreach ($rootIds as $root) {
        // Descendants (hierarchy), iterative, shared cap across all roots.
        $stack = [$root];
        while ($stack && $hops < 2000) {
            $cur = array_pop($stack);
            $childStmt->execute([$cur]);
            foreach ($childStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cid = (int)$row['id'];
                if (isset($globalSeen[$cid])) continue;
                $globalSeen[$cid] = true;
                $addImpact($cid, $row['name'], $row['class_name'], 'hierarchy');
                $stack[] = $cid;
            }
            $hops++;
        }

        // object_ref property references.
        $propRefStmt->execute([$root]);
        foreach ($propRefStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $addImpact((int)$row['id'], $row['name'], $row['class_name'], 'property');
        }

        // Incoming relationships ("depends on this").
        $relRefStmt->execute([$root]);
        foreach ($relRefStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $addImpact((int)$row['id'], $row['name'], $row['class_name'], 'relationship');
        }
    }

    $impactedList = array_values($impacted);
    $impactedCount = count($impactedList);

    $suggested = null;
    $suggestedLabel = null;
    if (count($rootIds) > 0) {
        $suggested = change_impact_to_score($impactedCount);
        $labels = [1 => 'Very Low', 2 => 'Low', 3 => 'Medium', 4 => 'High', 5 => 'Very High'];
        $suggestedLabel = $labels[$suggested] ?? null;
    }

    echo json_encode([
        'success'                => true,
        'linked_count'           => count($rootIds),
        'impacted_count'         => $impactedCount,
        'suggested_impact'       => $suggested,
        'suggested_impact_label' => $suggestedLabel,
        'impacted'               => array_slice($impactedList, 0, 50),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
