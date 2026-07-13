<?php
/**
 * Forms — settings manifest. See includes/capabilities.php.
 *
 * Designing FORMS and reading submissions is the everyday job and stays on plain module
 * access. The AI tab uses the shared AI settings panel (namespace forms_ai).
 */
require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'forms',
    'label'  => 'Forms',
    'umbrella' => [
        'cap'       => Cap::FORMS_MANAGE,
        'grant'     => 'Manage everything in Forms settings',
        'sensitive' => true,   // implies the AI credentials
    ],
    'tabs' => [
        [
            'id'           => 'layout',
            'cap'          => Cap::FORMS_LAYOUT,
            'label_key'    => 'forms.settings.tab_layout',
            'grant'        => 'Configure the form layout',
            'setting_keys' => ['forms_logo_alignment'],
        ],
        [
            'id'           => 'ai',
            'cap'          => Cap::FORMS_AI,
            'label_key'    => 'forms.settings.tab_ai',
            'grant'        => 'Configure the Forms AI provider, including its API key',
            'sensitive'    => true,
            'setting_keys' => ['forms_ai_provider', 'forms_ai_model', 'forms_ai_api_key', 'forms_ai_verify_ssl'],
        ],
    ],
];
