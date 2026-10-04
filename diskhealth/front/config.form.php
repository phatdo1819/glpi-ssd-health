<?php

/**
 * Disk health plugin for GLPI
 *
 * Settings page: health thresholds
 *
 * @license   MIT
 */

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

if (isset($_POST['update'])) {
    PluginDiskhealthConfig::update($_POST);
    Html::back();
}

Html::header(PluginDiskhealthDisk::getTypeName(), $_SERVER['PHP_SELF'], 'config', 'plugins');

PluginDiskhealthConfig::showForm();

Html::footer();
