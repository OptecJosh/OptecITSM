<?php
/**
 * Change Management — settings manifest.
 *
 * THE single declaration of this module's settings tabs, and therefore of its
 * capabilities. See includes/capabilities.php.
 *
 * Nothing here reaches credentials, money or email, so no tab is marked sensitive — but
 * the split still earns its keep: the **Fields** tab decides which fields appear on every
 * change record and whether they are required, which is a far bigger lever over how the
 * team works than adding a value to the impacts list.
 *
 * Note what is NOT here. **CAB membership** is managed from the change record itself, not
 * from settings, and is reachable through the REST API — so it stays on plain module
 * access. Approving a change is the job, not the configuration.
 */

require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'changes',
    'label'  => 'Change management',

    'umbrella' => [
        'cap'   => Cap::CHANGES_MANAGE,
        'grant' => 'Manage everything in Change management settings',
    ],

    'tabs' => [
        [
            // Which fields appear on a change, in what order, and which are required.
            'id'        => 'fields',
            'cap'       => Cap::CHANGES_FIELDS,
            'label_key' => 'change-management.settings.tab_fields',
            'grant'     => 'Manage the fields on a change, and how they are laid out',
        ],
        [
            'id'        => 'statuses',
            'cap'       => Cap::CHANGES_STATUSES,
            'label_key' => 'change-management.settings.tab_statuses',
            'grant'     => 'Manage change statuses',
        ],
        [
            'id'        => 'priorities',
            'cap'       => Cap::CHANGES_PRIORITIES,
            'label_key' => 'change-management.settings.tab_priorities',
            'grant'     => 'Manage change priorities',
        ],
        [
            'id'        => 'types',
            'cap'       => Cap::CHANGES_TYPES,
            'label_key' => 'change-management.settings.tab_types',
            'grant'     => 'Manage change types',
        ],
        [
            'id'        => 'impacts',
            'cap'       => Cap::CHANGES_IMPACTS,
            'label_key' => 'change-management.settings.tab_impacts',
            'grant'     => 'Manage change impacts',
        ],
        [
            // A per-analyst display preference. Not administration; nothing to grant.
            'id'        => 'left-panel',
            'cap'       => null,
            'label_key' => 'common.left_panel.tab',
        ],
    ],
];
