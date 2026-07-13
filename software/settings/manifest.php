<?php
/**
 * Software — settings manifest. See includes/capabilities.php.
 *
 * One tab, and it MINTS CREDENTIALS: the API keys that software-inventory agents present
 * when they push data in. Whoever can generate one can feed the inventory; whoever can
 * revoke one can stop it dead.
 *
 * That is nothing like being able to browse the software list, which is what module access
 * gives you — which is exactly why it is split out, even though it leaves the module with a
 * single capability.
 */
require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'software',
    'label'  => 'Software',
    'umbrella' => [
        'cap'       => Cap::SOFTWARE_MANAGE,
        'grant'     => 'Manage everything in Software settings',
        'sensitive' => true,
    ],
    'tabs' => [
        [
            'id'        => 'api-keys',
            'cap'       => Cap::SOFTWARE_API_KEYS,
            'label_key' => 'software.settings.tab_api_keys',
            'grant'     => 'Create and revoke the API keys inventory agents use',
            'sensitive' => true,
        ],
    ],
];
