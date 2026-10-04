<?php

/**
 * Disk health plugin for GLPI
 *
 * @license   MIT
 */

// GLPI 11 only uses getDefaultSearchRequest() from classes implementing this interface,
// which does not exist in GLPI 10
if (interface_exists('Glpi\Search\DefaultSearchRequestInterface')) {
    abstract class PluginDiskhealthDiskBase extends CommonDBTM implements Glpi\Search\DefaultSearchRequestInterface
    {
    }
} else {
    abstract class PluginDiskhealthDiskBase extends CommonDBTM
    {
    }
}

/**
 * SMART data of one hard drive, as reported by GLPI Agent in SMART_* STORAGES fields.
 * There is one row per Item_DeviceHardDrive.
 */
class PluginDiskhealthDisk extends PluginDiskhealthDiskBase
{
    public static $rightname = 'computer';

    public $dohistory = false;

    public const STATUS_REPLACE_NOW  = 1;
    public const STATUS_REPLACE_SOON = 2;
    public const STATUS_UNKNOWN      = 3;
    public const STATUS_OK           = 4;
    public const STATUS_VIRTUAL      = 5;

    private const VIRTUAL_MODEL_PATTERN = '/virtual|vmware|vbox|qemu/i';

    // NVMe critical warning bits meaning the drive must be replaced:
    // spare below threshold, reliability degraded, read-only
    private const NVME_REPLACE_BITS = 0x0D;

    public static function getTypeName($nb = 0)
    {
        return __('Disk health', 'diskhealth');
    }

    public static function getIcon()
    {
        return 'ti ti-heartbeat';
    }

    public static function getNameField()
    {
        return 'model';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function getStatusLabels(): array
    {
        return [
            self::STATUS_REPLACE_NOW  => __('Replace now', 'diskhealth'),
            self::STATUS_REPLACE_SOON => __('Replace soon', 'diskhealth'),
            self::STATUS_UNKNOWN      => __('Unknown', 'diskhealth'),
            self::STATUS_OK           => __('OK', 'diskhealth'),
            self::STATUS_VIRTUAL      => __('Virtual disk', 'diskhealth'),
        ];
    }

    public static function getStatusColor(int $status): string
    {
        return [
            self::STATUS_REPLACE_NOW  => '#d63939',
            self::STATUS_REPLACE_SOON => '#f76707',
            self::STATUS_UNKNOWN      => '#667382',
            self::STATUS_OK           => '#2fb344',
            self::STATUS_VIRTUAL      => '#9aa0ac',
        ][$status] ?? '#667382';
    }

    public static function getStatusBadge(int $status): string
    {
        $labels = self::getStatusLabels();

        return sprintf(
            '<span class="badge" style="background-color: %s; color: #fff;">%s</span>',
            self::getStatusColor($status),
            htmlspecialchars($labels[$status] ?? (string) $status)
        );
    }

    /**
     * Hook for Item_DeviceHardDrive add and pre-update: store the SMART_* fields sent by the agent
     */
    public static function saveFromInventory(CommonDBTM $item): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $input = is_array($item->input) ? $item->input : [];

        // Only inventory updates from an agent reporting SMART data
        if (!array_key_exists('smart_health', $input) && !array_key_exists('smart_status', $input)) {
            return;
        }

        $id = (int) ($item->fields['id'] ?? $input['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $itemtype = (string) ($input['itemtype'] ?? $item->fields['itemtype'] ?? '');
        $items_id = (int) ($input['items_id'] ?? $item->fields['items_id'] ?? 0);
        $written  = self::cleanInt($input['smart_written'] ?? null, 0, PHP_INT_MAX);

        $data = [
            'items_deviceharddrives_id' => $id,
            'itemtype'                  => self::cleanString($itemtype, 100) ?? '',
            'items_id'                  => $items_id,
            'computers_id'              => $itemtype === 'Computer' ? $items_id : 0,
            'entities_id'               => (int) ($item->fields['entities_id'] ?? 0),
            'is_recursive'              => (int) ($item->fields['is_recursive'] ?? 0),
            'model'                     => self::cleanString($input['model'] ?? $input['designation'] ?? null, 255),
            'serial'                    => self::cleanString($input['serial'] ?? $item->fields['serial'] ?? null, 255),
            'volumes'                   => self::cleanString($input['smart_volumes'] ?? null, 255),
            'drive_type'                => self::cleanEnum($input['smart_type'] ?? null, ['SSD', 'HDD']),
            'health'                    => self::cleanInt($input['smart_health'] ?? null, 0, 100),
            'health_source'             => self::cleanString($input['smart_health_source'] ?? null, 100),
            'smart_status'              => self::cleanEnum($input['smart_status'] ?? null, ['PASSED', 'FAILED']),
            'power_on_hours'            => self::cleanInt($input['smart_power_on_hours'] ?? null, 0, 4294967295),
            'written_tb'                => $written === null ? null : round($written / 1000000, 2),
            'temperature'               => self::cleanInt($input['smart_temperature'] ?? null, 0, 200),
            'critical_warning'          => self::cleanInt($input['smart_critical_warning'] ?? null, 0, 255),
            'media_errors'              => self::cleanInt($input['smart_media_errors'] ?? null, 0, PHP_INT_MAX),
            'reallocated_sectors'       => self::cleanInt($input['smart_reallocated_sectors'] ?? null, 0, PHP_INT_MAX),
            'pending_sectors'           => self::cleanInt($input['smart_pending_sectors'] ?? null, 0, PHP_INT_MAX),
            'uncorrectable_sectors'     => self::cleanInt($input['smart_uncorrectable_sectors'] ?? null, 0, PHP_INT_MAX),
            'failing_attributes'        => self::cleanString($input['smart_failing_attributes'] ?? null, 255),
            'date_mod'                  => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ];

        [$data['status'], $data['problems']] = self::computeStatus($data);

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['items_deviceharddrives_id' => $id],
            'LIMIT'  => 1,
        ])->current();

        if ($existing) {
            $DB->update(self::getTable(), $data, ['id' => $existing['id']]);
        } else {
            $data['date_creation'] = $data['date_mod'];
            $DB->insert(self::getTable(), $data);
        }
    }

    /**
     * Status and problems summary, using the thresholds from the plugin configuration
     *
     * @return array{0: int, 1: string}
     */
    public static function computeStatus(array $data, ?array $thresholds = null): array
    {
        $thresholds = $thresholds ?? PluginDiskhealthConfig::getThresholds();

        if (preg_match(self::VIRTUAL_MODEL_PATTERN, (string) ($data['model'] ?? ''))) {
            return [self::STATUS_VIRTUAL, ''];
        }

        $replace_now  = [];
        $replace_soon = [];
        $notes        = [];

        if (($data['smart_status'] ?? null) === 'FAILED') {
            $replace_now[] = __('SMART overall health failed', 'diskhealth');
        }

        $warning = (int) ($data['critical_warning'] ?? 0);
        if ($warning & 0x01) {
            $replace_now[] = __('NVMe spare space below threshold', 'diskhealth');
        }
        if ($warning & 0x04) {
            $replace_now[] = __('NVMe reliability degraded', 'diskhealth');
        }
        if ($warning & 0x08) {
            $replace_now[] = __('NVMe drive is read-only', 'diskhealth');
        }
        if ($warning & 0x02) {
            $notes[] = __('NVMe temperature warning', 'diskhealth');
        }
        if ($warning & 0x10) {
            $notes[] = __('NVMe volatile memory backup failed', 'diskhealth');
        }

        if (!empty($data['failing_attributes'])) {
            $replace_now[] = sprintf(__('Failing attributes: %s', 'diskhealth'), $data['failing_attributes']);
        }

        $counters = [
            'media_errors'          => __('%d media errors', 'diskhealth'),
            'pending_sectors'       => __('%d pending sectors', 'diskhealth'),
            'uncorrectable_sectors' => __('%d uncorrectable sectors', 'diskhealth'),
        ];
        foreach ($counters as $field => $message) {
            $count = (int) ($data[$field] ?? 0);
            if ($count > 0) {
                $replace_soon[] = sprintf($message, $count);
            }
        }
        $count = (int) ($data['reallocated_sectors'] ?? 0);
        if ($count > 0) {
            $notes[] = sprintf(__('%d reallocated sectors', 'diskhealth'), $count);
        }

        // Values read back from database are strings
        $health = isset($data['health']) && $data['health'] !== '' ? (int) $data['health'] : null;
        if ($health !== null && $health <= $thresholds['warn_percent']) {
            $life_left = sprintf(__('Only %d%% of rated life left', 'diskhealth'), $health);
            if ($health <= $thresholds['crit_percent']) {
                array_unshift($replace_now, $life_left);
            } else {
                array_unshift($replace_soon, $life_left);
            }
        }

        if (count($replace_now)) {
            $status = self::STATUS_REPLACE_NOW;
        } elseif (count($replace_soon)) {
            $status = self::STATUS_REPLACE_SOON;
        } elseif ($health === null && ($data['drive_type'] ?? null) !== 'HDD') {
            $status = self::STATUS_UNKNOWN;
            $notes[] = __('Drive reports no wear counter: check it with the vendor tool', 'diskhealth');
        } else {
            $status = self::STATUS_OK;
        }

        return [$status, implode('; ', array_merge($replace_now, $replace_soon, $notes))];
    }

    /**
     * Recompute stored statuses, after thresholds changed
     */
    public static function recomputeAll(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $thresholds = PluginDiskhealthConfig::getThresholds();
        foreach ($DB->request(['FROM' => self::getTable()]) as $row) {
            [$status, $problems] = self::computeStatus($row, $thresholds);
            if ((int) $row['status'] !== $status || (string) $row['problems'] !== $problems) {
                $DB->update(self::getTable(), ['status' => $status, 'problems' => $problems], ['id' => $row['id']]);
            }
        }
    }

    public static function cleanForItemDevice(CommonDBTM $item): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->delete(self::getTable(), ['items_deviceharddrives_id' => $item->getID()]);
    }

    public static function cleanForItem(CommonDBTM $item): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->delete(self::getTable(), ['itemtype' => $item->getType(), 'items_id' => $item->getID()]);
    }

    public static function cronInfo($name)
    {
        if ($name === 'diskhealthcleanup') {
            return ['description' => __('Remove disk health data of deleted hard drives', 'diskhealth')];
        }
        if ($name === 'diskhealthalert') {
            return ['description' => __('Email the drives that need replacing, and create tickets for them', 'diskhealth')];
        }
        return [];
    }

    public static function cronDiskhealthalert(CronTask $task): int
    {
        return PluginDiskhealthAlert::cronAlert($task);
    }

    /**
     * Remove rows whose hard drive no longer exists, in case it was removed without hooks
     */
    public static function cronDiskhealthcleanup(CronTask $task): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $orphans = [];
        $iterator = $DB->request([
            'SELECT'    => [self::getTable() . '.id'],
            'FROM'      => self::getTable(),
            'LEFT JOIN' => [
                'glpi_items_deviceharddrives' => [
                    'ON' => [
                        self::getTable()              => 'items_deviceharddrives_id',
                        'glpi_items_deviceharddrives' => 'id',
                    ],
                ],
            ],
            'WHERE'     => ['glpi_items_deviceharddrives.id' => null],
        ]);
        foreach ($iterator as $row) {
            $orphans[] = $row['id'];
        }

        if (count($orphans)) {
            $DB->delete(self::getTable(), ['id' => $orphans]);
        }
        $task->addVolume(count($orphans));

        return 1;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof CommonDBTM) || $item->isNewItem() || !self::canView()) {
            return '';
        }

        $nb = 0;
        if ($_SESSION['glpishow_count_on_tabs'] ?? false) {
            $nb = countElementsInTable(self::getTable(), [
                'itemtype' => $item->getType(),
                'items_id' => $item->getID(),
                'status'   => [self::STATUS_REPLACE_NOW, self::STATUS_REPLACE_SOON],
            ]);
        }

        return self::createTabEntry(self::getTypeName(), $nb);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof CommonDBTM) {
            self::showForItem($item);
        }
        return true;
    }

    public static function showForItem(CommonDBTM $item): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'SELECT'    => [
                'glpi_items_deviceharddrives.id AS item_device_id',
                'glpi_items_deviceharddrives.serial AS device_serial',
                'glpi_deviceharddrives.designation',
                self::getTable() . '.*',
            ],
            'FROM'      => 'glpi_items_deviceharddrives',
            'INNER JOIN' => [
                'glpi_deviceharddrives' => [
                    'ON' => [
                        'glpi_items_deviceharddrives' => 'deviceharddrives_id',
                        'glpi_deviceharddrives'       => 'id',
                    ],
                ],
            ],
            'LEFT JOIN' => [
                self::getTable() => [
                    'ON' => [
                        'glpi_items_deviceharddrives' => 'id',
                        self::getTable()              => 'items_deviceharddrives_id',
                    ],
                ],
            ],
            'WHERE'     => [
                'glpi_items_deviceharddrives.itemtype'   => $item->getType(),
                'glpi_items_deviceharddrives.items_id'   => $item->getID(),
                'glpi_items_deviceharddrives.is_deleted' => 0,
            ],
            'ORDER'     => ['glpi_items_deviceharddrives.id'],
        ]);

        $thresholds = PluginDiskhealthConfig::getThresholds();
        $headers = [
            __('Hard drive', 'diskhealth'),
            __('Serial number'),
            __('Type'),
            __('Health', 'diskhealth'),
            __('Status'),
            __('Problems', 'diskhealth'),
            __('Power on hours', 'diskhealth'),
            __('Data written', 'diskhealth'),
            __('Temperature', 'diskhealth'),
        ];
        $nowrap = '<td style="white-space: nowrap;">';

        echo '<div class="spaced table-responsive">';
        echo '<table class="tab_cadre_fixehov">';
        echo '<tr class="noHover"><th colspan="' . count($headers) . '">' . htmlspecialchars(self::getTypeName()) . '</th></tr>';

        if (!count($iterator)) {
            echo '<tr class="tab_bg_1"><td class="center" colspan="' . count($headers) . '">'
                . htmlspecialchars(__('No hard drive', 'diskhealth')) . '</td></tr>';
            echo '</table></div>';
            return;
        }

        echo '<tr>';
        foreach ($headers as $header) {
            echo '<th>' . htmlspecialchars($header) . '</th>';
        }
        echo '</tr>';

        $last_update = null;
        foreach ($iterator as $row) {
            $model  = $row['model'] ?? $row['designation'];
            $serial = $row['serial'] ?? $row['device_serial'];

            echo '<tr class="tab_bg_1">';
            echo '<td>' . htmlspecialchars((string) $model);
            if (!empty($row['volumes'])) {
                echo '<br><small class="text-muted">' . htmlspecialchars($row['volumes']) . '</small>';
            }
            echo '</td>';
            echo '<td>' . htmlspecialchars((string) $serial) . '</td>';

            if ($row['id'] === null) {
                echo '<td colspan="' . (count($headers) - 2) . '"><i>'
                    . htmlspecialchars(__('No SMART data reported for this drive. The agent needs the disk health module and smartctl.', 'diskhealth'))
                    . '</i></td>';
                echo '</tr>';
                continue;
            }

            if ($last_update === null || $row['date_mod'] > $last_update) {
                $last_update = $row['date_mod'];
            }

            echo '<td>' . htmlspecialchars((string) $row['drive_type']) . '</td>';
            echo '<td>' . self::getHealthBar($row['health'] === null ? null : (int) $row['health'], (string) $row['health_source']);
            if ($row['health'] !== null && !empty($row['health_source'])) {
                echo '<small class="text-muted">' . htmlspecialchars($row['health_source']) . '</small>';
            }
            echo '</td>';
            echo $nowrap . self::getStatusBadge((int) $row['status']);
            if ((int) $row['tickets_id'] > 0) {
                echo '<br><a href="' . htmlspecialchars(Ticket::getFormURLWithID((int) $row['tickets_id'])) . '">'
                    . htmlspecialchars(sprintf('%s %d', Ticket::getTypeName(1), $row['tickets_id'])) . '</a>';
            }
            echo '</td>';
            echo '<td>' . htmlspecialchars((string) $row['problems']) . '</td>';
            echo $nowrap . ($row['power_on_hours'] === null ? '' : htmlspecialchars(number_format((int) $row['power_on_hours'], 0, '.', ' '))) . '</td>';
            echo $nowrap . ($row['written_tb'] === null ? '' : htmlspecialchars(sprintf(__('%s TB', 'diskhealth'), $row['written_tb']))) . '</td>';
            echo $nowrap . ($row['temperature'] === null ? '' : htmlspecialchars($row['temperature'] . ' °C')) . '</td>';
            echo '</tr>';
        }

        echo '</table>';
        echo '<p class="center">';
        if ($last_update !== null) {
            echo htmlspecialchars(sprintf(__('Last update from the agent: %s', 'diskhealth'), Html::convDateTime($last_update))) . '<br>';
        }
        echo htmlspecialchars(sprintf(
            __('Health is the remaining rated life of the SSD, like CrystalDiskInfo. Replace soon at %1$d%% or less, replace now at %2$d%% or less.', 'diskhealth'),
            $thresholds['warn_percent'],
            $thresholds['crit_percent']
        ));
        echo '</p>';
        echo '</div>';
    }

    public static function getHealthBar(?int $health, string $source = ''): string
    {
        if ($health === null) {
            return '';
        }

        $thresholds = PluginDiskhealthConfig::getThresholds();
        if ($health <= $thresholds['crit_percent']) {
            $color = self::getStatusColor(self::STATUS_REPLACE_NOW);
        } elseif ($health <= $thresholds['warn_percent']) {
            $color = self::getStatusColor(self::STATUS_REPLACE_SOON);
        } else {
            $color = self::getStatusColor(self::STATUS_OK);
        }

        return sprintf(
            '<div style="display: flex; align-items: center; gap: 6px; min-width: 120px;" title="%s">'
            . '<div style="flex: 1; height: 10px; background: #e6e7e9; border-radius: 5px; overflow: hidden;">'
            . '<div style="width: %d%%; height: 100%%; background: %s;"></div></div>'
            . '<span>%d%%</span></div>',
            htmlspecialchars($source),
            $health,
            $color,
            $health
        );
    }

    public static function getDefaultSearchRequest(): array
    {
        return [
            'sort'  => [6, 5],
            'order' => ['ASC', 'ASC'],
        ];
    }

    public function rawSearchOptions()
    {
        $table = self::getTable();
        $tab   = [];

        $tab[] = [
            'id'   => 'common',
            'name' => self::getTypeName(),
        ];

        $tab[] = [
            'id'            => '1',
            'table'         => $table,
            'field'         => 'model',
            'name'          => __('Hard drive', 'diskhealth'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '2',
            'table'         => $table,
            'field'         => 'id',
            'name'          => __('ID'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '3',
            'table'         => 'glpi_computers',
            'field'         => 'name',
            'name'          => Computer::getTypeName(1),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '4',
            'table'         => $table,
            'field'         => 'serial',
            'name'          => __('Serial number'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '5',
            'table'         => $table,
            'field'         => 'health',
            'name'          => __('Health (%)', 'diskhealth'),
            'datatype'      => 'number',
            'min'           => 0,
            'max'           => 100,
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '6',
            'table'         => $table,
            'field'         => 'status',
            'name'          => __('Status'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '7',
            'table'         => $table,
            'field'         => 'problems',
            'name'          => __('Problems', 'diskhealth'),
            'datatype'      => 'text',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '8',
            'table'         => $table,
            'field'         => 'power_on_hours',
            'name'          => __('Power on hours', 'diskhealth'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '9',
            'table'         => $table,
            'field'         => 'written_tb',
            'name'          => __('Data written (TB)', 'diskhealth'),
            'datatype'      => 'decimal',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '10',
            'table'         => $table,
            'field'         => 'temperature',
            'name'          => __('Temperature (°C)', 'diskhealth'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '11',
            'table'         => $table,
            'field'         => 'smart_status',
            'name'          => __('SMART status', 'diskhealth'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '12',
            'table'         => $table,
            'field'         => 'drive_type',
            'name'          => __('Type'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '13',
            'table'         => $table,
            'field'         => 'health_source',
            'name'          => __('Health source', 'diskhealth'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '14',
            'table'         => $table,
            'field'         => 'date_mod',
            'name'          => __('Last update'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '15',
            'table'         => $table,
            'field'         => 'reallocated_sectors',
            'name'          => __('Reallocated sectors', 'diskhealth'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '16',
            'table'         => $table,
            'field'         => 'pending_sectors',
            'name'          => __('Pending sectors', 'diskhealth'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '17',
            'table'         => $table,
            'field'         => 'uncorrectable_sectors',
            'name'          => __('Uncorrectable sectors', 'diskhealth'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '18',
            'table'         => $table,
            'field'         => 'media_errors',
            'name'          => __('Media errors', 'diskhealth'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '19',
            'table'         => 'glpi_tickets',
            'field'         => 'name',
            'name'          => Ticket::getTypeName(1),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '20',
            'table'         => $table,
            'field'         => 'volumes',
            'name'          => __('Volumes', 'diskhealth'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '80',
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'name'          => Entity::getTypeName(1),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            return self::getStatusBadge((int) $values[$field]);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            $options['display'] = false;
            $options['value']   = $values[$field];
            return Dropdown::showFromArray($name, self::getStatusLabels(), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    private static function cleanInt($value, int $min, int $max): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $value = (int) $value;
        return $value < $min || $value > $max ? null : $value;
    }

    private static function cleanString($value, int $length): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null;
        }
        // Keep only characters found in models, serials and SMART attribute names, so
        // values are safe to store and display on any GLPI version
        $value = trim(preg_replace('/[^\p{L}\p{N} _.,:()\/+%#@-]/u', '', (string) $value) ?? '');
        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    private static function cleanEnum($value, array $allowed): ?string
    {
        $value = strtoupper(trim((string) $value));
        return in_array($value, $allowed, true) ? $value : null;
    }
}
