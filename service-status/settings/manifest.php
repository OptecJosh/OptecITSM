<?php
/**
 * Service Status — settings manifest. See includes/capabilities.php.
 *
 * Raising and updating an INCIDENT is the everyday job and stays on plain module access.
 * These tabs define the services whose status is tracked, and the vocabulary incidents are
 * described with.
 */
require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'service-status',
    'label'  => 'Service status',
    'umbrella' => [
        'cap'   => Cap::SERVICE_STATUS_MANAGE,
        'grant' => 'Manage everything in Service status settings',
    ],
    'tabs' => [
        [
            'id'        => 'services',
            'cap'       => Cap::SERVICE_STATUS_SERVICES,
            'label_key' => 'service-status.settings.tab_services',
            'grant'     => 'Manage the services whose status is tracked',
        ],
        [
            'id'        => 'statuses',
            'cap'       => Cap::SERVICE_STATUS_STATUSES,
            'label_key' => 'service-status.settings.tab_statuses',
            'grant'     => 'Manage incident statuses',
        ],
        [
            'id'        => 'impacts',
            'cap'       => Cap::SERVICE_STATUS_IMPACTS,
            'label_key' => 'service-status.settings.tab_impacts',
            'grant'     => 'Manage impact levels',
        ],
    ],
];
