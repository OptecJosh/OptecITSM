<?php
/**
 * Asset Management — settings manifest.
 *
 * The single declaration point for this module's settings tabs. The tab bar in
 * index.php is rendered from this list, and each tab's panel is emitted only if the
 * analyst holds its capability. See includes/settings_manifest.php.
 *
 * 'cap' => null means "a personal preference, not administration" — always visible,
 * never gated. Only the left-panel display setting qualifies.
 *
 * 'setting_keys' is documentation of which system_settings keys the tab writes. The
 * ENFORCEMENT for those lives in includes/settings_keys.php, because they are written
 * through the shared save_system_settings.php endpoint rather than one of this
 * module's own. Keep the two in step: a key here but not there is not actually guarded.
 */

require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'assets',
    'tabs'   => [
        [
            'id'        => 'asset-types',
            'cap'       => Cap::ASSETS_TYPES,
            'label_key' => 'asset-management.settings.tab_asset_types',
        ],
        [
            'id'        => 'asset-statuses',
            'cap'       => Cap::ASSETS_STATUSES,
            'label_key' => 'asset-management.settings.tab_asset_statuses',
        ],
        [
            'id'        => 'locations',
            'cap'       => Cap::ASSETS_LOCATIONS,
            'label_key' => 'asset-management.settings.tab_locations',
        ],
        [
            'id'        => 'suppliers',
            'cap'       => Cap::ASSETS_SUPPLIERS,
            'label_key' => 'asset-management.settings.tab_suppliers',
        ],
        [
            'id'           => 'warranty',
            'cap'          => Cap::ASSETS_WARRANTY,
            'label_key'    => 'asset-management.settings.tab_warranty',
            'setting_keys' => ['asset_warranty_surface', 'asset_warranty_days'],
        ],
        [
            'id'           => 'vcenter',
            'cap'          => Cap::ASSETS_VCENTER,
            'label_key'    => 'asset-management.settings.tab_vcenter',
            'sensitive'    => true,
            'setting_keys' => ['vcenter_server', 'vcenter_user', 'vcenter_password'],
        ],
        [
            'id'           => 'intune',
            'cap'          => Cap::ASSETS_INTUNE,
            'label_key'    => 'asset-management.settings.tab_intune',
            'sensitive'    => true,
            'setting_keys' => ['intune_tenant_id', 'intune_client_id', 'intune_client_secret', 'intune_verify_ssl', 'intune_app_batch_size'],
        ],
        [
            // A per-analyst display preference, not administration. Everyone with the
            // module sees it; there is nothing here to grant.
            'id'        => 'left-panel',
            'cap'       => null,
            'label_key' => 'common.left_panel.tab',
        ],
    ],
];
