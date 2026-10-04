<?php

/**
 * Disk health plugin for GLPI
 *
 * List of all disks with their health, worst first
 *
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 */

include('../../../inc/includes.php');

Session::checkRight('computer', READ);

Html::header(PluginDiskhealthDisk::getTypeName(), $_SERVER['PHP_SELF'], 'assets', PluginDiskhealthDisk::class);

Search::show(PluginDiskhealthDisk::class);

Html::footer();
