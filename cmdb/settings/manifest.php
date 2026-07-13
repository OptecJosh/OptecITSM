<?php
/**
 * CMDB — settings manifest.
 *
 * THE single declaration of this module's settings tabs, and therefore of its
 * capabilities. See includes/capabilities.php.
 *
 * The **Classes** tab is the CMDB's schema: what kinds of configuration item exist and
 * what properties they carry. Changing it reshapes every object in the database, which is
 * a much heavier act than it looks — and it is reachable from the object page too, where
 * you can add an option to a property inline. Same act, different route, so it needs the
 * same permission (`api/cmdb/save_class_property.php`). This is the same call made for the
 * Kanban board's columns in Tasks.
 *
 * The **AI** tab uses the shared AI settings panel, so its provider and API key are
 * authorised by namespace against the keys declared below (see includes/settings_keys.php
 * and api/system/ai/*).
 */

require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'cmdb',
    'label'  => 'CMDB',

    'umbrella' => [
        'cap'       => Cap::CMDB_MANAGE,
        'grant'     => 'Manage everything in CMDB settings',
        'sensitive' => true,   // implies the AI credentials
    ],

    'tabs' => [
        [
            // The CI schema: classes and their properties. Also governs adding a property
            // option inline from the object page — the same change, reached another way.
            'id'        => 'classes',
            'cap'       => Cap::CMDB_CLASSES,
            'label_key' => 'cmdb.settings.tab_classes',
            'grant'     => 'Manage CI classes and their properties (this is the CMDB\'s schema)',
        ],
        [
            'id'        => 'relationship-types',
            'cap'       => Cap::CMDB_RELATIONSHIP_TYPES,
            'label_key' => 'cmdb.settings.tab_rel_types',
            'grant'     => 'Manage relationship types',
        ],
        [
            'id'           => 'ai',
            'cap'          => Cap::CMDB_AI,
            'label_key'    => 'cmdb.settings.tab_ai',
            'grant'        => 'Configure the CMDB AI provider, including its API key',
            'sensitive'    => true,
            'setting_keys' => [
                'cmdb_ai_provider', 'cmdb_ai_model', 'cmdb_ai_api_key', 'cmdb_ai_verify_ssl',
                'cmdb_ai_custom_instructions',
            ],
        ],
        [
            'id'        => 'left-panel',
            'cap'       => null,
            'label_key' => 'common.left_panel.tab',
        ],
    ],
];
