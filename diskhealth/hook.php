<?php

/**
 * Disk health plugin for GLPI
 *
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 */

function plugin_diskhealth_install()
{
    /** @var DBmysql $DB */
    global $DB;

    $table = PluginDiskhealthDisk::getTable();

    if (!$DB->tableExists($table)) {
        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();

        $DB->doQuery(
            "CREATE TABLE `$table` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `items_deviceharddrives_id` int {$sign} NOT NULL DEFAULT '0',
                `itemtype` varchar(100) NOT NULL DEFAULT '',
                `items_id` int {$sign} NOT NULL DEFAULT '0',
                `computers_id` int {$sign} NOT NULL DEFAULT '0',
                `entities_id` int {$sign} NOT NULL DEFAULT '0',
                `is_recursive` tinyint NOT NULL DEFAULT '0',
                `model` varchar(255) DEFAULT NULL,
                `serial` varchar(255) DEFAULT NULL,
                `drive_type` varchar(10) DEFAULT NULL,
                `health` tinyint unsigned DEFAULT NULL,
                `health_source` varchar(100) DEFAULT NULL,
                `smart_status` varchar(10) DEFAULT NULL,
                `status` tinyint NOT NULL DEFAULT '3',
                `problems` text,
                `power_on_hours` int unsigned DEFAULT NULL,
                `written_tb` decimal(12,2) DEFAULT NULL,
                `temperature` smallint DEFAULT NULL,
                `critical_warning` smallint unsigned DEFAULT NULL,
                `media_errors` bigint unsigned DEFAULT NULL,
                `reallocated_sectors` bigint unsigned DEFAULT NULL,
                `pending_sectors` bigint unsigned DEFAULT NULL,
                `uncorrectable_sectors` bigint unsigned DEFAULT NULL,
                `failing_attributes` varchar(255) DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `items_deviceharddrives_id` (`items_deviceharddrives_id`),
                KEY `item` (`itemtype`, `items_id`),
                KEY `computers_id` (`computers_id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `status` (`status`),
                KEY `health` (`health`),
                KEY `date_mod` (`date_mod`),
                KEY `date_creation` (`date_creation`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC"
        );
    }

    // Default thresholds, keeping existing values on upgrade
    $current = Config::getConfigurationValues(PluginDiskhealthConfig::CONTEXT);
    $missing = array_diff_key(PluginDiskhealthConfig::DEFAULTS, $current);
    if (count($missing)) {
        Config::setConfigurationValues(PluginDiskhealthConfig::CONTEXT, $missing);
    }

    // Default columns of the disk list (column 1, the drive model, is always shown first)
    if (countElementsInTable('glpi_displaypreferences', ['itemtype' => PluginDiskhealthDisk::class, 'users_id' => 0]) === 0) {
        foreach ([3, 4, 12, 5, 6, 7, 8, 9, 14] as $rank => $num) {
            $DB->insert('glpi_displaypreferences', [
                'itemtype' => PluginDiskhealthDisk::class,
                'num'      => $num,
                'rank'     => $rank + 1,
                'users_id' => 0,
            ]);
        }
    }

    // Task names are global in GLPI CLI: core already has "cleanorphans" tasks
    CronTask::register(PluginDiskhealthDisk::class, 'diskhealthcleanup', DAY_TIMESTAMP, [
        'comment' => __('Remove disk health data of deleted hard drives', 'diskhealth'),
    ]);

    return true;
}

function plugin_diskhealth_uninstall()
{
    /** @var DBmysql $DB */
    global $DB;

    $DB->doQuery('DROP TABLE IF EXISTS `' . PluginDiskhealthDisk::getTable() . '`');

    Config::deleteConfigurationValues(PluginDiskhealthConfig::CONTEXT, array_keys(PluginDiskhealthConfig::DEFAULTS));

    $DB->delete('glpi_displaypreferences', ['itemtype' => PluginDiskhealthDisk::class]);
    $DB->delete('glpi_savedsearches', ['itemtype' => PluginDiskhealthDisk::class]);

    return true;
}
