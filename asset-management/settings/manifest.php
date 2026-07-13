<?php
/**
 * Asset Management — settings manifest.
 *
 * THE single declaration of this module's settings tabs, and therefore of its
 * capabilities. Everything else is derived from this file:
 *
 *   - the tab bar on the settings page (only the tabs you may see are rendered);
 *   - the capabilities offered on System → Roles, with their descriptions and badges;
 *   - who may write each of the system_settings keys listed below.
 *
 * See includes/capabilities.php for how the derivation works, and
 * includes/settings_manifest.php for the renderer.
 *
 * Per tab:
 *   'id'           the tab's DOM id (its panel is <id>-tab)
 *   'cap'          the Cap:: constant required to see and use it.
 *                  null = a personal preference, not administration: always visible,
 *                  never gated, contributes no capability.
 *   'label_key'    i18n key for the tab's NAME on the tab bar
 *   'grant'        plain-English description of the PERMISSION, shown in System → Roles
 *   'sensitive'    true if it reaches credentials, email, money or the audit trail —
 *                  badged in the Roles picker so granting it makes you think
 *   'setting_keys' the system_settings keys this tab writes through the shared
 *                  save_system_settings.php endpoint (enforced in settings_keys.php,
 *                  which derives from this list)
 */

require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'assets',
    'label'  => 'Asset management',

    // One tick that satisfies every capability below — the ordinary "Asset
    // Administrator" role. Sensitive, because it implies vCenter and Intune.
    'umbrella' => [
        'cap'       => Cap::ASSETS_MANAGE,
        'grant'     => 'Manage everything in Asset management settings',
        'sensitive' => true,
    ],

    'tabs' => [
        [
            'id'        => 'asset-types',
            'cap'       => Cap::ASSETS_TYPES,
            'label_key' => 'asset-management.settings.tab_asset_types',
            'grant'     => 'Manage asset types',
        ],
        [
            'id'        => 'asset-statuses',
            'cap'       => Cap::ASSETS_STATUSES,
            'label_key' => 'asset-management.settings.tab_asset_statuses',
            'grant'     => 'Manage asset statuses',
        ],
        [
            'id'        => 'locations',
            'cap'       => Cap::ASSETS_LOCATIONS,
            'label_key' => 'asset-management.settings.tab_locations',
            'grant'     => 'Manage locations',
        ],
        [
            'id'        => 'suppliers',
            'cap'       => Cap::ASSETS_SUPPLIERS,
            'label_key' => 'asset-management.settings.tab_suppliers',
            'grant'     => 'Manage suppliers',
        ],
        [
            'id'           => 'warranty',
            'cap'          => Cap::ASSETS_WARRANTY,
            'label_key'    => 'asset-management.settings.tab_warranty',
            'grant'        => 'Configure warranty expiry surfacing',
            'setting_keys' => ['asset_warranty_surface', 'asset_warranty_days'],
        ],
        [
            'id'           => 'vcenter',
            'cap'          => Cap::ASSETS_VCENTER,
            'label_key'    => 'asset-management.settings.tab_vcenter',
            'grant'        => 'Configure the vCenter connection, including its credentials',
            'sensitive'    => true,
            'setting_keys' => ['vcenter_server', 'vcenter_user', 'vcenter_password'],
        ],
        [
            'id'           => 'intune',
            'cap'          => Cap::ASSETS_INTUNE,
            'label_key'    => 'asset-management.settings.tab_intune',
            'grant'        => 'Configure the Intune connection and run syncs, including its credentials',
            'sensitive'    => true,
            'setting_keys' => ['intune_tenant_id', 'intune_client_id', 'intune_client_secret', 'intune_verify_ssl', 'intune_app_batch_size'],
        ],
        [
            // A per-analyst display preference — where your sidebar sits. Not
            // administration, so there is nothing here to grant and nobody to gate.
            'id'        => 'left-panel',
            'cap'       => null,
            'label_key' => 'common.left_panel.tab',
        ],
    ],
];
