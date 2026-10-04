<?php

/**
 * Disk health plugin for GLPI
 *
 * @license   MIT
 */

/**
 * Plugin settings: health thresholds, warnings, email reminders and tickets
 */
class PluginDiskhealthConfig
{
    public const CONTEXT = 'plugin:diskhealth';

    public const TICKETS_NEVER        = 0;
    public const TICKETS_REPLACE_NOW  = 1;
    public const TICKETS_REPLACE_SOON = 2;

    public const DEFAULTS = [
        'warn_percent'      => 30,
        'crit_percent'      => 10,
        'show_warnings'     => 1,
        'alert_repeat_days' => 0,
        'ticket_mode'       => self::TICKETS_NEVER,
        'ticket_category'   => 0,
        'ticket_group'      => 0,
    ];

    /** @var array<string, int>|null */
    private static ?array $values = null;

    /**
     * @return array<string, int>
     */
    public static function getValues(): array
    {
        // Read once per request: inventories save several disks in a row
        if (self::$values === null) {
            $stored = Config::getConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS));

            self::$values = [];
            foreach (self::DEFAULTS as $name => $default) {
                self::$values[$name] = isset($stored[$name]) && is_numeric($stored[$name]) ? (int) $stored[$name] : $default;
            }
        }

        return self::$values;
    }

    /**
     * @return array{warn_percent: int, crit_percent: int}
     */
    public static function getThresholds(): array
    {
        $values = self::getValues();

        return [
            'warn_percent' => $values['warn_percent'],
            'crit_percent' => $values['crit_percent'],
        ];
    }

    /**
     * Save settings from the configuration form and refresh stored statuses
     */
    public static function update(array $input): bool
    {
        $int = static fn (string $name, int $min, int $max) => filter_var(
            $input[$name] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $min, 'max_range' => $max]]
        );

        $values = [
            'warn_percent'      => $int('warn_percent', 0, 100),
            'crit_percent'      => $int('crit_percent', 0, 100),
            'show_warnings'     => $int('show_warnings', 0, 1),
            'alert_repeat_days' => $int('alert_repeat_days', 0, 365),
            'ticket_mode'       => $int('ticket_mode', self::TICKETS_NEVER, self::TICKETS_REPLACE_SOON),
            'ticket_category'   => $int('ticket_category', 0, PHP_INT_MAX),
            'ticket_group'      => $int('ticket_group', 0, PHP_INT_MAX),
        ];

        if (in_array(false, $values, true) || $values['crit_percent'] > $values['warn_percent']) {
            Session::addMessageAfterRedirect(
                __('Settings not saved: thresholds must be between 0 and 100 with "replace now" not higher than "replace soon", and reminders between 0 and 365 days.', 'diskhealth'),
                false,
                ERROR
            );
            return false;
        }

        Config::setConfigurationValues(self::CONTEXT, $values);
        self::$values = null;
        PluginDiskhealthDisk::recomputeAll();

        Session::addMessageAfterRedirect(__('Settings saved and disk statuses updated.', 'diskhealth'));
        return true;
    }

    public static function showForm(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $values = self::getValues();

        $header = static function (string $title): void {
            echo '<tr><th colspan="2">' . htmlspecialchars($title) . '</th></tr>';
        };
        $row_start = static function (string $label, string $for = ''): void {
            echo '<tr class="tab_bg_1"><td style="width: 50%;">';
            echo $for === '' ? htmlspecialchars($label) : '<label for="' . $for . '">' . htmlspecialchars($label) . '</label>';
            echo '</td><td>';
        };
        $row_end = static function (): void {
            echo '</td></tr>';
        };
        $note = static function (string $html): void {
            echo '<tr class="tab_bg_1"><td colspan="2"><span class="text-muted">' . $html . '</span></td></tr>';
        };
        $number = static function (string $name, int $value, int $max): void {
            echo '<input type="number" class="form-control" style="max-width: 8em;" min="0" max="' . $max . '" step="1"'
                . ' id="diskhealth_' . $name . '" name="' . $name . '" value="' . $value . '">';
        };

        echo '<form method="post" action="">';
        echo '<div class="center spaced">';
        echo '<table class="tab_cadre_fixe">';

        $header(__('Thresholds', 'diskhealth'));
        $fields = [
            'warn_percent' => __('Replace soon when health is at or below (%)', 'diskhealth'),
            'crit_percent' => __('Replace now when health is at or below (%)', 'diskhealth'),
        ];
        foreach ($fields as $name => $label) {
            $row_start($label, 'diskhealth_' . $name);
            $number($name, $values[$name], 100);
            $row_end();
        }

        $header(__('Warnings', 'diskhealth'));
        $row_start(__('Show a warning on the home page, and on computers with drives to replace', 'diskhealth'));
        Dropdown::showYesNo('show_warnings', $values['show_warnings']);
        $row_end();

        $header(__('Email alerts', 'diskhealth'));
        $links = [];
        $notification = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Notification::getTable(),
            'WHERE'  => ['itemtype' => PluginDiskhealthDisk::class],
            'LIMIT'  => 1,
        ])->current();
        if ($notification) {
            $links[] = sprintf(
                '<a href="%s">%s</a>',
                htmlspecialchars(Notification::getFormURLWithID($notification['id'])),
                htmlspecialchars(__('Choose who gets the email', 'diskhealth'))
            );
        }
        $cron = new CronTask();
        if ($cron->getFromDBbyName(PluginDiskhealthDisk::class, 'diskhealthalert')) {
            $links[] = sprintf(
                '<a href="%s">%s</a>',
                htmlspecialchars($cron->getLinkURL()),
                htmlspecialchars(__('Change when it runs', 'diskhealth'))
            );
        }
        $note(
            htmlspecialchars(__('Once a day, GLPI emails the drives that newly need replacing, one email per entity. By default the email goes to the administrator address set in Setup > Notifications, where email notifications must be turned on.', 'diskhealth'))
            . (count($links) ? '<br>' . implode(' · ', $links) : '')
        );
        $row_start(__('Remind again every (days) while a drive still needs replacing, 0 for never', 'diskhealth'), 'diskhealth_alert_repeat_days');
        $number('alert_repeat_days', $values['alert_repeat_days'], 365);
        $row_end();

        $header(__('Tickets', 'diskhealth'));
        $row_start(__('Create a ticket for each drive that needs replacing', 'diskhealth'));
        Dropdown::showFromArray('ticket_mode', [
            self::TICKETS_NEVER        => __('Never', 'diskhealth'),
            self::TICKETS_REPLACE_NOW  => __('When it needs replacing now', 'diskhealth'),
            self::TICKETS_REPLACE_SOON => __('When it needs replacing soon or now', 'diskhealth'),
        ], ['value' => $values['ticket_mode']]);
        $row_end();
        $row_start(__('Ticket category', 'diskhealth'));
        // Tickets are created as incidents: GLPI clears a category not allowed for incidents
        ITILCategory::dropdown(['name' => 'ticket_category', 'value' => $values['ticket_category'], 'entity' => 0, 'entity_sons' => true, 'condition' => ['is_incident' => 1]]);
        $row_end();
        $row_start(__('Assign tickets to the group', 'diskhealth'));
        Group::dropdown(['name' => 'ticket_group', 'value' => $values['ticket_group'], 'entity' => 0, 'entity_sons' => true, 'condition' => ['is_assign' => 1]]);
        $row_end();
        $note(htmlspecialchars(__('Tickets are created by the same daily action, in the computer\'s entity and linked to it. A drive gets one ticket while it needs replacing, even if the ticket is closed. If the drive is fine again and fails later, it gets a new one. Ticket rules apply as usual.', 'diskhealth')));

        echo '<tr class="tab_bg_2"><td colspan="2" class="center">';
        echo '<button type="submit" name="update" value="1" class="btn btn-primary">' . htmlspecialchars(_x('button', 'Save')) . '</button>';
        echo '</td></tr>';
        echo '</table>';
        echo '<p>' . sprintf(
            '<a href="%s">%s</a>',
            htmlspecialchars(PluginDiskhealthDisk::getSearchURL()),
            htmlspecialchars(__('See all disks', 'diskhealth'))
        ) . '</p>';
        echo '</div>';
        Html::closeForm();
    }
}
