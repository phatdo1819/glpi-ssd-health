<?php

/**
 * Disk health plugin for GLPI
 *
 * @license   MIT
 */

/**
 * Tells admins about drives to replace: warnings in GLPI, dashboard cards, email alerts and tickets
 */
class PluginDiskhealthAlert
{
    /** Statuses that need action, worst first */
    public const ALERT_STATUSES = [
        PluginDiskhealthDisk::STATUS_REPLACE_NOW,
        PluginDiskhealthDisk::STATUS_REPLACE_SOON,
    ];

    /**
     * Hook display_central: warning at the top of the home page
     */
    public static function showCentralWarning(): void
    {
        if (!self::canSeeWarnings()) {
            return;
        }

        $counts = self::countByStatus(self::ALERT_STATUSES);
        $now    = $counts[PluginDiskhealthDisk::STATUS_REPLACE_NOW];
        $soon   = $counts[PluginDiskhealthDisk::STATUS_REPLACE_SOON];
        if ($now + $soon === 0) {
            return;
        }

        $parts = [];
        if ($now > 0) {
            $parts[] = sprintf(_n('%d drive to replace now', '%d drives to replace now', $now, 'diskhealth'), $now);
        }
        if ($soon > 0) {
            $parts[] = sprintf(_n('%d drive to replace soon', '%d drives to replace soon', $soon, 'diskhealth'), $soon);
        }

        // The hook runs inside a table
        echo '<tr><td>';
        self::showAlertBox(
            $now > 0 ? PluginDiskhealthDisk::STATUS_REPLACE_NOW : PluginDiskhealthDisk::STATUS_REPLACE_SOON,
            sprintf('%s: %s.', __('Disk health', 'diskhealth'), implode(', ', $parts)),
            [],
            self::getListUrl(self::ALERT_STATUSES),
            __('See the drives', 'diskhealth')
        );
        echo '</td></tr>';
    }

    /**
     * Hook pre_show_item: warning above a computer's main tab
     *
     * @param mixed $params ['item' => CommonGLPI, 'options' => array]
     */
    public static function showItemWarning($params): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $item = is_array($params) ? ($params['item'] ?? null) : null;
        if (!($item instanceof Computer) || $item->isNewItem() || !self::canSeeWarnings()) {
            return;
        }

        $iterator = $DB->request([
            'SELECT' => ['model', 'serial', 'volumes', 'status', 'problems'],
            'FROM'   => PluginDiskhealthDisk::getTable(),
            'WHERE'  => [
                'itemtype' => Computer::class,
                'items_id' => $item->getID(),
                'status'   => self::ALERT_STATUSES,
            ],
            'ORDER'  => ['status', 'health'],
        ]);
        if (!count($iterator)) {
            return;
        }

        $labels = PluginDiskhealthDisk::getStatusLabels();
        $worst  = PluginDiskhealthDisk::STATUS_REPLACE_SOON;
        $lines  = [];
        foreach ($iterator as $row) {
            $worst = min($worst, (int) $row['status']);
            $drive = ($row['model'] ?? __('Unknown model', 'diskhealth'))
                . ($row['serial'] === null ? '' : ' (' . $row['serial'] . ')');
            if (!empty($row['volumes'])) {
                // "C:, D: (Data) on Samsung SSD 870 EVO (S6PW...)"
                $drive = sprintf(__('%1$s on %2$s', 'diskhealth'), $row['volumes'], $drive);
            }
            $lines[] = sprintf('%s: %s. %s', $drive, $labels[(int) $row['status']], $row['problems']);
        }

        self::showAlertBox(
            $worst,
            sprintf(_n('This computer has %d drive to replace.', 'This computer has %d drives to replace.', count($lines), 'diskhealth'), count($lines)),
            $lines,
            $item->getLinkURL() . '&forcetab=' . rawurlencode(PluginDiskhealthDisk::class . '$1'),
            __('See the Disk health tab', 'diskhealth')
        );
    }

    /**
     * Hook dashboard_cards: cards admins can add to GLPI dashboards
     *
     * @param mixed $cards cards added by other plugins
     */
    public static function getDashboardCards($cards = null): array
    {
        $cards = is_array($cards) ? $cards : [];

        $cards['plugin_diskhealth_status'] = [
            'widgettype' => ['summaryNumbers', 'multipleNumber', 'pie', 'donut', 'halfpie', 'halfdonut', 'bar', 'hbar'],
            'label'      => __('Drives by disk health status', 'diskhealth'),
            'group'      => __('Assets'),
            'provider'   => self::class . '::getStatusCardData',
        ];
        $cards['plugin_diskhealth_replace_now'] = [
            'widgettype' => ['bigNumber'],
            'label'      => __('Drives to replace now', 'diskhealth'),
            'group'      => __('Assets'),
            'provider'   => self::class . '::getReplaceNowCardData',
        ];

        return $cards;
    }

    public static function getStatusCardData(array $params = []): array
    {
        $data = [];
        if (PluginDiskhealthDisk::canView()) {
            $labels = PluginDiskhealthDisk::getStatusLabels();
            foreach (self::countByStatus(array_keys($labels)) as $status => $count) {
                $data[] = [
                    'number' => $count,
                    'label'  => $labels[$status],
                    'url'    => self::getListUrl([$status]),
                ];
            }
        }

        return [
            'data'  => $data,
            'label' => $params['label'] ?? __('Drives by disk health status', 'diskhealth'),
            'icon'  => PluginDiskhealthDisk::getIcon(),
        ];
    }

    public static function getReplaceNowCardData(array $params = []): array
    {
        $status = PluginDiskhealthDisk::STATUS_REPLACE_NOW;

        return [
            'number' => PluginDiskhealthDisk::canView() ? self::countByStatus([$status])[$status] : 0,
            'url'    => self::getListUrl([$status]),
            'label'  => $params['label'] ?? __('Drives to replace now', 'diskhealth'),
            'icon'   => PluginDiskhealthDisk::getIcon(),
        ];
    }

    /**
     * Number of drives per status, in the current user's active entities
     *
     * @param int[] $statuses
     *
     * @return array<int, int>
     */
    public static function countByStatus(array $statuses): array
    {
        $table  = PluginDiskhealthDisk::getTable();
        $counts = [];
        foreach ($statuses as $status) {
            $counts[$status] = countElementsInTable(
                $table,
                ['status' => $status] + getEntitiesRestrictCriteria($table, '', '', true)
            );
        }

        return $counts;
    }

    /**
     * Link to the disk list filtered on the given statuses
     *
     * @param int[] $statuses
     * @param bool  $absolute full URL with host, for emails
     */
    public static function getListUrl(array $statuses, bool $absolute = false): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $criteria = [];
        foreach (array_values($statuses) as $i => $status) {
            $criteria[] = ($i > 0 ? ['link' => 'OR'] : []) + [
                'field'      => 6,
                'searchtype' => 'equals',
                'value'      => $status,
            ];
        }

        $url = $absolute
            ? rtrim((string) $CFG_GLPI['url_base'], '/') . PluginDiskhealthDisk::getSearchURL(false)
            : PluginDiskhealthDisk::getSearchURL();

        return $url . '?' . Toolbox::append_params(['criteria' => $criteria, 'reset' => 'reset']);
    }

    /**
     * Automatic action: email the drives that newly need replacing, and create tickets for them
     */
    public static function cronAlert(CronTask $task): int
    {
        /**
         * @var array $CFG_GLPI
         * @var DBmysql $DB
         */
        global $CFG_GLPI, $DB;

        $config = PluginDiskhealthConfig::getValues();
        $now    = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        // Drives that are fine again: forget their last alert and ticket, so that a new problem
        // is emailed and gets a ticket again
        $DB->update(
            PluginDiskhealthDisk::getTable(),
            ['alerted_status' => null, 'date_alert' => null, 'tickets_id' => 0],
            [
                'NOT' => ['status' => self::ALERT_STATUSES],
                'OR'  => [
                    ['NOT' => ['alerted_status' => null]],
                    ['tickets_id' => ['>', 0]],
                ],
            ]
        );

        $drives = self::getDrivesToReplace();
        $done   = 0;

        if ($CFG_GLPI['use_notifications']) {
            $done += self::sendAlerts($task, $drives, $config['alert_repeat_days'], $now);
        }
        if ($config['ticket_mode'] !== PluginDiskhealthConfig::TICKETS_NEVER) {
            $done += self::createTickets($task, $drives, $config);
        }

        return $done > 0 ? 1 : 0;
    }

    /**
     * Drives needing replacement on computers that are not in the trash, worst first
     */
    private static function getDrivesToReplace(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $table    = PluginDiskhealthDisk::getTable();
        $iterator = $DB->request([
            'SELECT'     => [
                $table . '.*',
                'glpi_computers.name AS computer_name',
                'glpi_computers.entities_id AS computer_entities_id',
            ],
            'FROM'       => $table,
            'INNER JOIN' => [
                'glpi_computers' => [
                    'ON' => [
                        $table           => 'computers_id',
                        'glpi_computers' => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                $table . '.status'           => self::ALERT_STATUSES,
                'glpi_computers.is_deleted'  => 0,
                'glpi_computers.is_template' => 0,
            ],
            'ORDER'      => [$table . '.status', $table . '.health', 'glpi_computers.name'],
        ]);

        $drives = [];
        foreach ($iterator as $row) {
            $row['computer_name'] = self::fromDb($row['computer_name']);
            $drives[]             = $row;
        }

        return $drives;
    }

    /**
     * Raise one notification per entity, for drives never alerted, worse since their last alert,
     * or due for a reminder
     */
    private static function sendAlerts(CronTask $task, array $drives, int $repeat_days, string $now): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $remind_before = $repeat_days > 0 ? date('Y-m-d H:i:s', strtotime($now) - $repeat_days * DAY_TIMESTAMP) : null;

        $by_entity = [];
        foreach ($drives as $drive) {
            $alerted = $drive['alerted_status'] === null ? null : (int) $drive['alerted_status'];
            $due     = $alerted === null
                || (int) $drive['status'] < $alerted
                || ($remind_before !== null && $drive['date_alert'] !== null && $drive['date_alert'] < $remind_before);
            if ($due) {
                $by_entity[(int) $drive['computer_entities_id']][] = $drive;
            }
        }

        $sent = 0;
        foreach ($by_entity as $entities_id => $entity_drives) {
            $entity_name = self::fromDb(Dropdown::getDropdownName('glpi_entities', $entities_id));
            $options     = ['entities_id' => $entities_id, 'items' => $entity_drives];
            if (!NotificationEvent::raiseEvent('alert', new PluginDiskhealthDisk(), $options)) {
                $task->log(sprintf(__('%s: the disk health alert could not be sent', 'diskhealth'), $entity_name));
                continue;
            }

            foreach ($entity_drives as $drive) {
                $DB->update(
                    PluginDiskhealthDisk::getTable(),
                    ['alerted_status' => (int) $drive['status'], 'date_alert' => $now],
                    ['id' => $drive['id']]
                );
            }
            $count = count($entity_drives);
            $task->log(sprintf(_n('%1$s: alert sent for %2$d drive', '%1$s: alert sent for %2$d drives', $count, 'diskhealth'), $entity_name, $count));
            $task->addVolume($count);
            $sent += $count;
        }

        return $sent;
    }

    /**
     * Create one ticket per drive needing replacement, linked to its computer
     */
    private static function createTickets(CronTask $task, array $drives, array $config): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $statuses = $config['ticket_mode'] === PluginDiskhealthConfig::TICKETS_REPLACE_SOON
            ? self::ALERT_STATUSES
            : [PluginDiskhealthDisk::STATUS_REPLACE_NOW];

        $created = 0;
        foreach ($drives as $drive) {
            if (!in_array((int) $drive['status'], $statuses, true)) {
                continue;
            }

            // One ticket per problem: none while the drive's ticket exists, even if closed
            $ticket = new Ticket();
            if ((int) $drive['tickets_id'] > 0 && $ticket->getFromDB((int) $drive['tickets_id'])) {
                continue;
            }

            $model = $drive['model'] ?? __('Unknown model', 'diskhealth');
            $input = [
                'entities_id'  => (int) $drive['computer_entities_id'],
                'name'         => sprintf(__('Replace the disk %1$s of %2$s', 'diskhealth'), $model, $drive['computer_name']),
                'content'      => self::getTicketContent($drive),
                'type'         => Ticket::INCIDENT_TYPE,
                'urgency'      => (int) $drive['status'] === PluginDiskhealthDisk::STATUS_REPLACE_NOW ? 4 : 3,
                'items_id'     => [Computer::class => [(int) $drive['computers_id']]],
                '_auto_import' => true,
            ];
            if ($config['ticket_category'] > 0) {
                $input['itilcategories_id'] = $config['ticket_category'];
            }
            if ($config['ticket_group'] > 0) {
                $input['_groups_id_assign'] = $config['ticket_group'];
            }

            $tickets_id = $ticket->add(self::prepareInputForGlpi($input));
            if (!$tickets_id) {
                $task->log(sprintf(__('Ticket creation failed for the disk %1$s of %2$s', 'diskhealth'), $model, $drive['computer_name']));
                continue;
            }

            $DB->update(PluginDiskhealthDisk::getTable(), ['tickets_id' => $tickets_id], ['id' => $drive['id']]);
            $task->log(sprintf(__('Ticket %1$d created for the disk %2$s of %3$s', 'diskhealth'), $tickets_id, $model, $drive['computer_name']));
            $task->addVolume(1);
            $created++;
        }

        return $created;
    }

    private static function getTicketContent(array $drive): string
    {
        $labels = PluginDiskhealthDisk::getStatusLabels();
        $rows   = [
            Computer::getTypeName(1)           => $drive['computer_name'],
            __('Hard drive', 'diskhealth')     => $drive['model'],
            __('Serial number')                => $drive['serial'],
            __('Volumes', 'diskhealth')        => $drive['volumes'],
            __('Health', 'diskhealth')         => $drive['health'] === null ? '' : $drive['health'] . '%',
            __('Status')                       => $labels[(int) $drive['status']] ?? '',
            __('Problems', 'diskhealth')       => $drive['problems'],
            __('Power on hours', 'diskhealth') => $drive['power_on_hours'] === null ? '' : number_format((int) $drive['power_on_hours'], 0, '.', ' '),
            __('Last update')                  => Html::convDateTime($drive['date_mod']),
        ];

        $html = '<p>' . htmlspecialchars(__('The Disk health plugin found a drive that needs replacing.', 'diskhealth')) . '</p><ul>';
        foreach ($rows as $label => $value) {
            if ($value !== null && $value !== '') {
                $html .= '<li><strong>' . htmlspecialchars($label) . ':</strong> ' . htmlspecialchars((string) $value) . '</li>';
            }
        }

        return $html . '</ul>';
    }

    /**
     * GLPI 10 expects input escaped and HTML-encoded, as it receives it from forms.
     * GLPI 11 escapes values itself.
     */
    public static function prepareInputForGlpi(array $input): array
    {
        if (version_compare(GLPI_VERSION, '11.0', '<')) {
            return Glpi\Toolbox\Sanitizer::sanitize($input);
        }

        return $input;
    }

    /**
     * Plain text of a value read from a GLPI table: GLPI 10 stores text HTML-encoded
     */
    private static function fromDb($value): string
    {
        $value = (string) $value;
        if (version_compare(GLPI_VERSION, '11.0', '<')) {
            $value = Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars($value);
        }

        return $value;
    }

    private static function canSeeWarnings(): bool
    {
        return PluginDiskhealthConfig::getValues()['show_warnings'] === 1
            && Session::getCurrentInterface() === 'central'
            && PluginDiskhealthDisk::canView();
    }

    /**
     * @param string[] $lines
     */
    private static function showAlertBox(int $status, string $title, array $lines, string $url, string $link_label): void
    {
        $class = $status === PluginDiskhealthDisk::STATUS_REPLACE_NOW ? 'alert-danger' : 'alert-warning';

        echo '<div class="alert ' . $class . ' d-flex align-items-start mb-3" role="alert">';
        echo '<i class="' . htmlspecialchars(PluginDiskhealthDisk::getIcon()) . ' fs-2 me-2"></i>';
        echo '<div><strong>' . htmlspecialchars($title) . '</strong>';
        if (count($lines)) {
            echo '<ul class="mb-1">';
            foreach ($lines as $line) {
                echo '<li>' . htmlspecialchars($line) . '</li>';
            }
            echo '</ul>';
        } else {
            echo ' ';
        }
        echo '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($link_label) . '</a>';
        echo '</div></div>';
    }
}
