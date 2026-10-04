# Disk health plugin for GLPI

This plugin shows SSD health in GLPI and tells you which drives to replace. It stores the SMART data that GLPI Agent sends with each inventory and shows it in two places:

- **On each computer:** a **Disk health** tab listing each drive with its health %, status, problems, power-on hours, data written and temperature. The tab badge counts drives to replace.
- **Across the fleet:** **Assets > Disk health**, a list of every drive, worst first. You can filter it (for example "Health below 30" or "Status is Replace now") and export it to CSV, PDF or spreadsheet with GLPI's standard export button.

It needs agents running the SSD health module (`../glpi-agent-addon`). Drives from agents without it show "No SMART data reported".

## Requirements

- GLPI 10.0.x or 11.0.x. Tested on 10.0.28 and 11.0.11 with PHP 8.3 and MariaDB 11.4.
- GLPI's native inventory in use. The agent must report to GLPI, not to the old FusionInventory plugin.
- In **Administration > Inventory**, the "Drives" and "Hard drives" components must be imported. Both are on by default.

## Install

1. Copy the `diskhealth` folder into `glpi/plugins/` on the GLPI server. Then open **Setup > Plugins**, click **Install**, then **Enable**.
2. Alternatively, from the GLPI folder on the command line:

   ```sh
   # GLPI 11
   php bin/console plugin:install --username=glpi diskhealth
   php bin/console plugin:activate diskhealth
   # GLPI 10
   php bin/console glpi:plugin:install --username=glpi diskhealth
   php bin/console glpi:plugin:activate diskhealth
   ```

Data appears as each agent sends its next inventory. Run `glpi-agent --force` on a PC to send one now.

## Statuses

| Status | When |
|---|---|
| **Replace now** | Health is 10% or less, the SMART self-check failed, the NVMe drive reports a critical warning (spare space exhausted, reliability degraded or read-only), or a SATA attribute is below its failure threshold |
| **Replace soon** | Health is 30% or less, or the drive has pending or uncorrectable sectors, or NVMe media errors |
| **Unknown** | The SSD reports no wear counter. Check it with the vendor's tool or CrystalDiskInfo. |
| **OK** | Nothing wrong found |
| **Virtual disk** | Virtual machine disk; its health is meaningless |

To change the two thresholds, go to **Setup > Plugins > Disk health** (the settings icon). Saving updates every drive's status straight away.

## Rights

Anyone who can view computers can see the tab and the list. Entity restrictions apply to the list.

## How it works

On each inventory, GLPI adds or updates every hard drive with all the fields the agent sent. The plugin hooks into that update (`pre_item_update` and `item_add` on `Item_DeviceHardDrive`), reads the `smart_*` fields, and saves one row per drive in `glpi_plugin_diskhealth_disks`. It works out the status at that moment.

When GLPI removes a drive or a computer, its row is removed too. A daily task, **diskhealthcleanup**, removes any rows that were left behind.

## When GLPI is upgraded

- **Within 10.0.x or 11.0.x:** nothing to do.
- **A newer branch (11.1, 12…):** GLPI turns off plugins that don't declare support for the new version, so this plugin stops until it's updated. Your data stays in its table. Test the plugin on the new version first, then raise `PLUGIN_DISKHEALTH_MAX_GLPI` in `setup.php` and enable it again.

## License

GPL-3.0-or-later, the same as GLPI (see `LICENSE`).

## Uninstall

**Setup > Plugins > Uninstall** removes the table, the settings, the list layout and the cleanup task.
