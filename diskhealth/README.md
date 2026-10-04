# Disk health plugin for GLPI

This plugin shows SSD health in GLPI and tells you which drives to replace. It stores the SMART data that GLPI Agent sends with each inventory.

Where you see it:

- **On each computer:** a **Disk health** tab lists each drive with the volumes on it (drive letters on Windows, mount points on Linux), its health % and where that % comes from, status, problems, power-on hours, data written and temperature. The tab badge counts drives to replace, and a warning sits at the top of the computer's page while a drive needs replacing.
- **Across the fleet:** **Assets > Disk health** lists every drive with its computer and volumes, worst first. You can filter it (for example "Health below 30" or "Status is Replace now") and export it to CSV, PDF or spreadsheet with GLPI's standard export button.

How it tells admins:

- **Home page:** a warning such as "Disk health: 4 drives to replace now, 1 drive to replace soon", linking to those drives.
- **Email:** once a day, an email lists the drives that newly need replacing.
- **Dashboard cards:** "Drives to replace now" and "Drives by disk health status", for any GLPI dashboard.
- **Tickets (optional, off by default):** one ticket per drive to replace, linked to its computer.

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

**Upgrading from 1.0.0 or 1.1.0:** replace the `diskhealth` folder. GLPI then turns the plugin off and lists it as "To update": in **Setup > Plugins**, click **Upgrade**, then **Enable**. On the command line, add `--force` to the install command. Your data and settings are kept. If you haven't edited the alert email template, it gets the new volumes line. Volumes appear once a PC runs the updated agent module (add-on 1.1.0 or the matching agent installer) and sends its next inventory.

## Statuses

| Status | When |
|---|---|
| **Replace now** | Health is 10% or less, the SMART self-check failed, the NVMe drive reports a critical warning (spare space exhausted, reliability degraded or read-only), or a SATA attribute is below its failure threshold |
| **Replace soon** | Health is 30% or less, or the drive has pending or uncorrectable sectors, or NVMe media errors |
| **Unknown** | The SSD reports no wear counter. Check it with the vendor's tool or CrystalDiskInfo. |
| **OK** | Nothing wrong found |
| **Virtual disk** | Virtual machine disk; its health is meaningless |

## Settings

Open **Setup > Plugins > Disk health** (the settings icon). Saving updates every drive's status straight away.

| Setting | Default |
|---|---|
| Replace soon / replace now thresholds | 30% / 10% |
| Warnings on the home page and on computers | On |
| Email reminder for drives still needing replacement | Never: one email per problem |
| Tickets | Never |

## Email alerts

Once a day, the **diskhealthalert** automatic action sends one email per entity, listing the drives that newly need replacing: drives never alerted before, and drives that got worse (from "replace soon" to "replace now"). A drive that's fine again, for example after you raise a threshold, is alerted again if it goes bad later. With a reminder set, drives that still need replacing are emailed again every so many days.

To receive the emails:

1. In **Setup > Notifications**, turn on **Enable notifications** and **Enable email notifications**. GLPI 10 calls them **Enable followup** and **Enable followups via email**.
2. In **Setup > Notifications > Email notifications configuration** (GLPI 10: **Email followups configuration**), set the **Administrator email address** and how GLPI sends mail. The alert goes to that address.
3. To email other people too, open **Setup > Notifications > Notifications > Disk health: drives to replace > Recipients**. You can add a profile, a group or users, as with any GLPI notification. The settings page links there.

To send the alert now instead of waiting a day, open **Setup > Automatic actions > diskhealthalert** and click **Execute**. You can change the email's wording in **Setup > Notifications > Notification templates > Disk health alert**.

## Tickets

Set **Create a ticket for each drive that needs replacing** to "When it needs replacing now" or "When it needs replacing soon or now". The same daily action then creates one incident per drive:

- in the computer's entity, linked to the computer;
- titled "Replace the disk *model* of *computer*", with the drive's details;
- with high urgency for "replace now" and medium for "replace soon";
- with the category and assigned group from the settings, if set. Ticket business rules apply as usual.

A drive gets one ticket while it needs replacing, even if you close the ticket. If the drive is fine again (for example after you raise a threshold) and fails later, it gets a new ticket. The **Disk health** tab shows a link to each drive's ticket, and the drive list has a **Ticket** column you can add.

## Dashboard cards

To add a card, open a dashboard (for example the home page's **Dashboard** tab) and click the edit (pencil) icon. Then click **+** in an empty space and pick one of the cards. Both cards link to the matching drives.

- **Drives to replace now:** one big number.
- **Drives by disk health status:** a count per status, as a pie, bars or numbers.

## Rights

Anyone who can view computers can see the tab, the list, the warnings and the cards. Entity restrictions apply. The settings page needs the right to update the general setup.

## How it works

On each inventory, GLPI adds or updates every hard drive with all the fields the agent sent. The plugin hooks into that update (`pre_item_update` and `item_add` on `Item_DeviceHardDrive`), reads the `smart_*` fields, and saves one row per drive in `glpi_plugin_diskhealth_disks`. It works out the status at that moment.

When GLPI removes a drive or a computer, its row is removed too. A daily task, **diskhealthcleanup**, removes any rows that were left behind.

The **diskhealthalert** task records, on each drive's row, which status was last emailed and when, and the ticket it created. It clears them once the drive is fine again. Computers in the trash are left out of emails and tickets.

## When GLPI is upgraded

- **Within 10.0.x or 11.0.x:** nothing to do.
- **A newer branch (11.1, 12…):** GLPI turns off plugins that don't declare support for the new version, so this plugin stops until it's updated. Your data stays in its table. Test the plugin on the new version first, then raise `PLUGIN_DISKHEALTH_MAX_GLPI` in `setup.php` and enable it again.

## License

MIT (see `LICENSE`). MIT is compatible with GLPI's GPL-3.0-or-later license.

## Uninstall

**Setup > Plugins > Uninstall** removes the table, the settings, the list layout, the email notification and its template, the plugin's cards on dashboards, and both automatic actions. Tickets it created stay in GLPI.
