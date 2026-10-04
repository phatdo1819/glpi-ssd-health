<?php

/**
 * Disk health plugin for GLPI
 *
 * @license   MIT
 */

function plugin_diskhealth_install()
{
    /** @var DBmysql $DB */
    global $DB;

    $table     = PluginDiskhealthDisk::getTable();
    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists($table)) {
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
                `volumes` varchar(255) DEFAULT NULL,
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
                `alerted_status` tinyint DEFAULT NULL,
                `date_alert` timestamp NULL DEFAULT NULL,
                `tickets_id` int {$sign} NOT NULL DEFAULT '0',
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
                KEY `tickets_id` (`tickets_id`),
                KEY `date_mod` (`date_mod`),
                KEY `date_creation` (`date_creation`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC"
        );
    }

    // Upgrades: alert and ticket tracking (1.1.0), volumes on each disk (1.2.0)
    $new_fields = [
        'alerted_status' => 'tinyint DEFAULT NULL AFTER `failing_attributes`',
        'date_alert'     => 'timestamp NULL DEFAULT NULL AFTER `alerted_status`',
        'tickets_id'     => "int {$sign} NOT NULL DEFAULT '0' AFTER `date_alert`",
        'volumes'        => 'varchar(255) DEFAULT NULL AFTER `serial`',
    ];
    foreach ($new_fields as $field => $definition) {
        if (!$DB->fieldExists($table, $field)) {
            $DB->doQuery("ALTER TABLE `$table` ADD `$field` $definition");
        }
    }
    if (!isIndex($table, 'tickets_id')) {
        $DB->doQuery("ALTER TABLE `$table` ADD KEY `tickets_id` (`tickets_id`)");
    }

    // Default settings, keeping existing values on upgrade
    $current = Config::getConfigurationValues(PluginDiskhealthConfig::CONTEXT);
    $missing = array_diff_key(PluginDiskhealthConfig::DEFAULTS, $current);
    if (count($missing)) {
        Config::setConfigurationValues(PluginDiskhealthConfig::CONTEXT, $missing);
    }

    // Default columns of the disk list (column 1, the drive model, is always shown first).
    // Columns left as installed by 1.0.0 or 1.1.0 get the Volumes column too.
    $columns = [];
    foreach (
        $DB->request([
            'SELECT' => ['num'],
            'FROM'   => 'glpi_displaypreferences',
            'WHERE'  => ['itemtype' => PluginDiskhealthDisk::class, 'users_id' => 0],
            'ORDER'  => ['rank'],
        ]) as $row
    ) {
        $columns[] = (int) $row['num'];
    }
    if ($columns === [] || $columns === [3, 4, 12, 5, 6, 7, 8, 9, 14]) {
        $DB->delete('glpi_displaypreferences', ['itemtype' => PluginDiskhealthDisk::class, 'users_id' => 0]);
        foreach ([3, 20, 4, 12, 5, 6, 7, 8, 9, 14] as $rank => $num) {
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
    CronTask::register(PluginDiskhealthDisk::class, 'diskhealthalert', DAY_TIMESTAMP, [
        'comment' => __('Email the drives that need replacing, and create tickets for them', 'diskhealth'),
    ]);

    plugin_diskhealth_install_notification();
    plugin_diskhealth_update_notification_template();

    return true;
}

/**
 * Default content of the email alert template
 *
 * @return array{text: string, html: string}
 */
function plugin_diskhealth_get_template_content(): array
{
    $text = <<<TEXT
##diskhealth.action## - ##diskhealth.entity##

##FOREACHdisks##
##disk.status##: ##disk.computer## - ##disk.model## (##lang.disk.serial##: ##disk.serial##)
##lang.disk.volumes##: ##disk.volumes##
##lang.disk.health##: ##disk.health##. ##disk.problems##
##disk.computerurl##

##ENDFOREACHdisks##
##lang.diskhealth.url##: ##diskhealth.url##
TEXT;

    $html = <<<HTML
<p><strong>##diskhealth.action## - ##diskhealth.entity##</strong></p>
<p>##FOREACHdisks##</p>
<p><strong>##disk.status##</strong>: <a href="##disk.computerurl##">##disk.computer##</a> - ##disk.model## (##lang.disk.serial##: ##disk.serial##)<br />##lang.disk.volumes##: ##disk.volumes##<br />##lang.disk.health##: ##disk.health##. ##disk.problems##</p>
<p>##ENDFOREACHdisks##</p>
<p><a href="##diskhealth.url##">##lang.diskhealth.url##</a></p>
HTML;

    return ['text' => $text, 'html' => $html];
}

/**
 * Templates installed by 1.1.0 and never edited get the volumes added in 1.2.0
 */
function plugin_diskhealth_update_notification_template(): void
{
    /** @var DBmysql $DB */
    global $DB;

    $text_110 = <<<TEXT
##diskhealth.action## - ##diskhealth.entity##

##FOREACHdisks##
##disk.status##: ##disk.computer## - ##disk.model## (##lang.disk.serial##: ##disk.serial##)
##lang.disk.health##: ##disk.health##. ##disk.problems##
##disk.computerurl##

##ENDFOREACHdisks##
##lang.diskhealth.url##: ##diskhealth.url##
TEXT;

    $iterator = $DB->request([
        'SELECT'     => ['glpi_notificationtemplatetranslations.id', 'glpi_notificationtemplatetranslations.content_text'],
        'FROM'       => 'glpi_notificationtemplatetranslations',
        'INNER JOIN' => [
            'glpi_notificationtemplates' => [
                'ON' => [
                    'glpi_notificationtemplatetranslations' => 'notificationtemplates_id',
                    'glpi_notificationtemplates'            => 'id',
                ],
            ],
        ],
        'WHERE'      => ['glpi_notificationtemplates.itemtype' => PluginDiskhealthDisk::class],
    ]);

    $content     = plugin_diskhealth_get_template_content();
    $translation = new NotificationTemplateTranslation();
    foreach ($iterator as $row) {
        // Installs from a Windows git checkout have CRLF line endings
        if (str_replace("\r", '', (string) $row['content_text']) === $text_110) {
            $translation->update(PluginDiskhealthAlert::prepareInputForGlpi([
                'id'           => $row['id'],
                'content_text' => $content['text'],
                'content_html' => $content['html'],
            ]));
        }
    }
}

/**
 * Email alert: template, and a notification sent to GLPI's administrator email address
 */
function plugin_diskhealth_install_notification(): void
{
    $itemtype = PluginDiskhealthDisk::class;
    if (countElementsInTable(Notification::getTable(), ['itemtype' => $itemtype]) > 0) {
        return;
    }

    $template     = new NotificationTemplate();
    $templates_id = $template->add(PluginDiskhealthAlert::prepareInputForGlpi([
        'name'     => 'Disk health alert',
        'itemtype' => $itemtype,
    ]));
    if (!$templates_id) {
        return;
    }

    $content     = plugin_diskhealth_get_template_content();
    $translation = new NotificationTemplateTranslation();
    $translation->add(PluginDiskhealthAlert::prepareInputForGlpi([
        'notificationtemplates_id' => $templates_id,
        'language'                 => '',
        'subject'                  => '##diskhealth.action## (##diskhealth.count##) - ##diskhealth.entity##',
        'content_text'             => $content['text'],
        'content_html'             => $content['html'],
    ]));

    $notification     = new Notification();
    $notifications_id = $notification->add(PluginDiskhealthAlert::prepareInputForGlpi([
        'name'         => 'Disk health: drives to replace',
        'entities_id'  => 0,
        'is_recursive' => 1,
        'is_active'    => 1,
        'itemtype'     => $itemtype,
        'event'        => 'alert',
    ]));
    if (!$notifications_id) {
        return;
    }

    $link = new Notification_NotificationTemplate();
    $link->add([
        'notifications_id'         => $notifications_id,
        'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
        'notificationtemplates_id' => $templates_id,
    ]);

    $target = new NotificationTarget();
    $target->add([
        'notifications_id' => $notifications_id,
        'type'             => Notification::USER_TYPE,
        'items_id'         => Notification::GLOBAL_ADMINISTRATOR,
    ]);
}

function plugin_diskhealth_uninstall()
{
    /** @var DBmysql $DB */
    global $DB;

    $DB->doQuery('DROP TABLE IF EXISTS `' . PluginDiskhealthDisk::getTable() . '`');

    Config::deleteConfigurationValues(PluginDiskhealthConfig::CONTEXT, array_keys(PluginDiskhealthConfig::DEFAULTS));

    $DB->delete('glpi_displaypreferences', ['itemtype' => PluginDiskhealthDisk::class]);
    $DB->delete('glpi_savedsearches', ['itemtype' => PluginDiskhealthDisk::class]);

    // Cards added to dashboards would show as broken without the plugin
    $DB->delete('glpi_dashboards_items', ['card_id' => ['LIKE', 'plugin_diskhealth_%']]);

    // Purging also removes the notification's targets and the template's translations
    $notification = new Notification();
    $notification->deleteByCriteria(['itemtype' => PluginDiskhealthDisk::class], true);
    $template = new NotificationTemplate();
    $template->deleteByCriteria(['itemtype' => PluginDiskhealthDisk::class], true);
    $DB->delete('glpi_queuednotifications', ['itemtype' => PluginDiskhealthDisk::class]);

    return true;
}
