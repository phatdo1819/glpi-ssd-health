<?php

/**
 * Disk health plugin for GLPI
 *
 * Stores the SMART data that GLPI Agent reports for each hard drive (SSD remaining
 * life, SMART status, error counters) and shows which drives need replacing.
 *
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 */

define('PLUGIN_DISKHEALTH_VERSION', '1.0.0');
define('PLUGIN_DISKHEALTH_MIN_GLPI', '10.0.0');
define('PLUGIN_DISKHEALTH_MAX_GLPI', '11.0.99');

function plugin_version_diskhealth()
{
    return [
        'name'         => 'Disk health',
        'version'      => PLUGIN_DISKHEALTH_VERSION,
        'author'       => '',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_DISKHEALTH_MIN_GLPI,
                'max' => PLUGIN_DISKHEALTH_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_init_diskhealth()
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['diskhealth'] = true;

    Plugin::registerClass(PluginDiskhealthDisk::class, ['addtabon' => ['Computer']]);

    // Inventory adds or updates every hard drive with all the fields sent by the
    // agent, including the SMART_* ones; pre_item_update runs even when nothing
    // else changed on the drive.
    $PLUGIN_HOOKS['item_add']['diskhealth'] = [
        'Item_DeviceHardDrive' => [PluginDiskhealthDisk::class, 'saveFromInventory'],
    ];
    $PLUGIN_HOOKS['pre_item_update']['diskhealth'] = [
        'Item_DeviceHardDrive' => [PluginDiskhealthDisk::class, 'saveFromInventory'],
    ];
    $PLUGIN_HOOKS['item_purge']['diskhealth'] = [
        'Item_DeviceHardDrive' => [PluginDiskhealthDisk::class, 'cleanForItemDevice'],
        'Computer'             => [PluginDiskhealthDisk::class, 'cleanForItem'],
    ];

    // Menu entry is only displayed to users who can view computers (see canView())
    $PLUGIN_HOOKS['menu_toadd']['diskhealth'] = ['assets' => PluginDiskhealthDisk::class];
    $PLUGIN_HOOKS['config_page']['diskhealth'] = 'front/config.form.php';
}

function plugin_diskhealth_check_prerequisites()
{
    return true;
}

function plugin_diskhealth_check_config($verbose = false)
{
    return true;
}
