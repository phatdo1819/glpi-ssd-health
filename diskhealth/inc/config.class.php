<?php

/**
 * Disk health plugin for GLPI
 *
 * @license   MIT
 */

/**
 * Plugin settings: health thresholds used to compute disk statuses
 */
class PluginDiskhealthConfig
{
    public const CONTEXT = 'plugin:diskhealth';

    public const DEFAULTS = [
        'warn_percent' => 30,
        'crit_percent' => 10,
    ];

    /** @var array{warn_percent: int, crit_percent: int}|null */
    private static ?array $thresholds = null;

    /**
     * @return array{warn_percent: int, crit_percent: int}
     */
    public static function getThresholds(): array
    {
        // Read once per request: inventories save several disks in a row
        if (self::$thresholds === null) {
            $values = Config::getConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS));

            self::$thresholds = [];
            foreach (self::DEFAULTS as $name => $default) {
                self::$thresholds[$name] = isset($values[$name]) && is_numeric($values[$name]) ? (int) $values[$name] : $default;
            }
        }

        return self::$thresholds;
    }

    /**
     * Save thresholds from the configuration form and refresh stored statuses
     */
    public static function update(array $input): bool
    {
        $warn = filter_var($input['warn_percent'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
        $crit = filter_var($input['crit_percent'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);

        if ($warn === false || $crit === false || $crit > $warn) {
            Session::addMessageAfterRedirect(
                __('Thresholds must be between 0 and 100, and "replace now" must not be higher than "replace soon".', 'diskhealth'),
                false,
                ERROR
            );
            return false;
        }

        Config::setConfigurationValues(self::CONTEXT, [
            'warn_percent' => $warn,
            'crit_percent' => $crit,
        ]);
        self::$thresholds = null;
        PluginDiskhealthDisk::recomputeAll();

        Session::addMessageAfterRedirect(__('Thresholds saved and disk statuses updated.', 'diskhealth'));
        return true;
    }

    public static function showForm(): void
    {
        $thresholds = self::getThresholds();

        echo '<form method="post" action="">';
        echo '<div class="center spaced">';
        echo '<table class="tab_cadre_fixe">';
        echo '<tr><th colspan="2">' . htmlspecialchars(__('Disk health settings', 'diskhealth')) . '</th></tr>';

        $fields = [
            'warn_percent' => __('Replace soon when health is at or below (%)', 'diskhealth'),
            'crit_percent' => __('Replace now when health is at or below (%)', 'diskhealth'),
        ];
        foreach ($fields as $name => $label) {
            echo '<tr class="tab_bg_1">';
            echo '<td><label for="diskhealth_' . $name . '">' . htmlspecialchars($label) . '</label></td>';
            echo '<td><input type="number" class="form-control" style="max-width: 8em;" min="0" max="100" step="1"'
                . ' id="diskhealth_' . $name . '" name="' . $name . '" value="' . (int) $thresholds[$name] . '"></td>';
            echo '</tr>';
        }

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
