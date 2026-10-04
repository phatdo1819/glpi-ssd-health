# GLPI Agent: SSD health module

This module makes GLPI Agent report SSD health with every inventory, the same idea as CrystalDiskInfo's "Health %": remaining rated life, SMART status and error counters. It adds the data to the hard drives the agent already reports. The **Disk health** GLPI plugin (`../diskhealth`) stores and displays it.

| File | What it is |
|---|---|
| `SmartHealth.pm` | The agent module. Works on Windows, Linux and macOS. |
| `windows\smartctl.exe`, `windows\drivedb.h` | smartmontools 7.5, 64-bit, and its drive database. Checksums match the official release. |
| `LICENSE-glpi-agent.txt` | License of the module: GPL-2.0-or-later, the same as GLPI Agent |
| `windows\smartmontools-COPYING.txt` | smartmontools license: GPL-2.0-or-later |
| [`../glpi-agent-smarthealth.patch`](../glpi-agent-smarthealth.patch) | The same change as a patch to the agent source, for building your own installer (option B) |

There are two ways to deploy it: add it to your existing agents (option A, quicker) or build a custom agent installer (option B).

## Option A: add to your existing agents (no rebuild)

Tested with GLPI Agent 1.20, the current release. No agent setting changes are needed. The module is picked up at the next inventory, without restarting the service.

### Windows

Copy three files into the agent folder (default `C:\Program Files\GLPI-Agent`):

| Copy | To |
|---|---|
| `SmartHealth.pm` | `C:\Program Files\GLPI-Agent\perl\agent\GLPI\Agent\Task\Inventory\Generic\Storages\SmartHealth.pm` |
| `windows\smartctl.exe` | `C:\Program Files\GLPI-Agent\perl\bin\smartctl.exe` |
| `windows\drivedb.h` | `C:\Program Files\GLPI-Agent\perl\bin\drivedb.h` |

With Group Policy:

1. Put this folder on a share that **Domain Computers** can read, for example `\\fileserver\SsdHealth$\glpi-agent-addon`.
2. In a GPO linked to your computers' OU, go to **Computer Configuration > Preferences > Windows Settings > Files**.
3. Create one **New > File** item per row of the table above:
   - **Source file:** the share path
   - **Destination file:** the full path from the table
   - **Action:** **Replace** for `SmartHealth.pm`. Replace overwrites the file at every policy refresh, so a new version of the module put on the share reaches every PC. The file is only 13 KB.
   - **Action:** **Update** for `smartctl.exe` and `drivedb.h`. Update copies a file only when it's missing and never overwrites an existing one, which avoids re-sending 1.4 MB every 90 minutes. To roll out a new smartctl later, switch these two items to Replace for a day.

### Linux

1. Install smartmontools 7.0 or later: `apt install smartmontools` or `dnf install smartmontools`.
2. Copy the module:

   ```sh
   cp SmartHealth.pm /usr/share/glpi-agent/lib/GLPI/Agent/Task/Inventory/Generic/Storages/
   ```

The snap package of the agent is read-only, so it needs option B.

### Check it on one PC

In an **Administrator** command prompt:

```bat
"C:\Program Files\GLPI-Agent\glpi-inventory.bat" --partial storage,storage_health
```

On Linux, run `glpi-inventory --partial storage,storage_health` as root.

In the JSON output, your SSDs should now have fields such as `"smart_health": 93` and `"smart_status": "PASSED"`. To send an inventory to GLPI straight away instead of waiting for the next scheduled one, run `glpi-agent --force`.

## Option B: your own agent installer

**The installer for 1.20 is ready:** `GLPI-Agent-1.20-smarthealth-x64.msi` on the [Releases page](https://github.com/phatdo1819/glpi-ssd-health/releases/latest). On the test VM, the agent from the unpacked MSI reported the same SMART data as option A. It hasn't been installed on a PC yet.

It's built from your public fork: <https://github.com/phatdo1819/glpi-agent>, branch **`smart-health`**. That branch is GLPI Agent 1.20 plus this change, which:

- adds the module and its tests;
- registers the new fields in the agent;
- bundles smartctl in the MSI, with its license and source code.

Every push to that branch builds the installers again with GitHub Actions. To get a new build:

1. Open **Actions > GLPI Agent Packaging** on the fork and pick the run.
2. Download the **Windows-Build-x64** artifact. It contains the MSI.

GitHub deletes artifacts after 90 days, so keep your own copy of each MSI you deploy, for example on a release here.

About the MSI:

- It's unsigned, unlike the official MSI, which Teclib signs. That's fine for Group Policy software installation, but Windows SmartScreen warns if you run it by hand. If your PCs block unsigned installers, allow this file by its hash or sign it with your own code-signing certificate.
- Programs and Features lists it as **GLPI Agent 1.20 (git07921671)**, and the agent reports its version as `1.20-git07921671`.
- It replaces any installed GLPI Agent, official or custom, the same way the official MSI does.
- It keeps the installed agent's settings, such as the server and tag, unless you pass new ones or `CONFIG=reset`. That's the official installer's behavior, unchanged.
- It takes the same install options as the official MSI, for example `SERVER=...`.

## When GLPI Agent is upgraded

**Option A: nothing to redo.**

- The agent installer keeps these files during an upgrade. It only removes the files it installed itself. If a reinstall does remove them, Group Policy copies them back.
- The module only uses long-standing parts of the agent: the list of disks, running a command, and reading JSON.
- It only waits for the storage modules that actually exist in the installed agent. A future release that renames or removes one of them therefore can't block the inventory.
- The agent runs each module separately. If a future version ever breaks this one, inventories carry on, just without SMART data.

After each agent upgrade, run the check command above on one PC to confirm `smart_health` still appears.

**Option B: redo the build for every release.** This recurring work is why option A is recommended. For example, when 1.21 comes out:

```sh
gh auth setup-git    # once, lets git use your GitHub CLI login
git clone https://github.com/phatdo1819/glpi-agent.git && cd glpi-agent
git fetch https://github.com/glpi-project/glpi-agent.git tag 1.21 --no-tags
git switch -c smart-health-1.21 1.21
git apply <path to this repository>/glpi-agent-smarthealth.patch
git add -A && git commit -m "Add SMART health reporting for storages"
git push -u origin smart-health-1.21
```

The push starts the build. Download the MSI from the Actions run as above.

## When this module is updated

Put the new `SmartHealth.pm` on the share. The Replace item copies it at the next policy refresh, and the agent uses it at its next inventory. No restart is needed.

## Licenses

| Part | License | Included |
|---|---|---|
| `SmartHealth.pm`, the patch | GPL-2.0-or-later, like GLPI Agent | `LICENSE-glpi-agent.txt` |
| smartctl, drivedb.h | GPL-2.0-or-later (smartmontools) | `windows\smartmontools-COPYING.txt`, and the source code in `third-party/` at the repository root. The installer build (option B) also puts the license next to smartctl.exe. |
| Disk health GLPI plugin | MIT | `diskhealth\LICENSE` |

Using and deploying these inside your organization carries no obligations. If you give the agent or smartctl to another organization, for example as a service provider deploying to client PCs, the GPL requires you to also offer them the source code. That means:

- `SmartHealth.pm` itself, which is already source;
- smartmontools 7.5 source: `third-party/smartmontools-7.5.tar.gz` in this repository, also on the Releases page.

## Turning it off

Add `no-category = storage_health` to the agent configuration. Disk inventory itself keeps working.

## What the agent sends

These fields are added to each disk in the `storages` section of the inventory. A field is left out when the drive doesn't report it.

| Field | Meaning |
|---|---|
| `smart_health` | Remaining rated life in %, 0–100 (SSDs) |
| `smart_health_source` | Where the % comes from, for example `NVMe Percentage Used`, `Device Statistics (Percentage Used)` or `Attribute 233 Media_Wearout_Indicator` |
| `smart_status` | `PASSED` or `FAILED` (the drive's own SMART self-assessment) |
| `smart_type` | `SSD` or `HDD` |
| `smart_power_on_hours` | Power-on hours |
| `smart_temperature` | Current temperature in °C |
| `smart_written` | Total data written, in MB |
| `smart_critical_warning` | NVMe critical warning bits |
| `smart_media_errors` | NVMe media and data integrity errors |
| `smart_reallocated_sectors`, `smart_pending_sectors`, `smart_uncorrectable_sectors` | SATA attributes 5, 197 and 198 |
| `smart_failing_attributes` | SATA attributes currently below their failure threshold |

How the health % is chosen, in order:

1. NVMe "Percentage Used".
2. The standard SATA device statistic "Percentage Used Endurance Indicator".
3. Vendor attributes named like `SSD_Life_Left`, `Percent_Lifetime_Remain`, `Media_Wearout_Indicator` or `Wear_Leveling_Count`.
4. The SAS endurance indicator.

Disks that smartctl can't read are skipped, and their inventory is unchanged.
