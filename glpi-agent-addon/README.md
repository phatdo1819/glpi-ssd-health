# GLPI Agent: SSD health module

This module makes GLPI Agent report SSD health with every inventory, the same idea as CrystalDiskInfo's "Health %": remaining rated life, SMART status and error counters. It also reports the volumes on each disk, as drive letters and labels on Windows (`C:, D: (Data)`) and mount points on Linux (`/, /home`), so you know which disk to replace. It adds the data to the hard drives the agent already reports. The **Disk health** GLPI plugin (`../diskhealth`) stores and displays it.

| File | What it is |
|---|---|
| `SmartHealth.pm` | The agent module. Works on Windows, Linux and macOS; volumes are reported on Windows and Linux. |
| `windows\Install-SmartHealth.ps1` | Windows install script: adds the module and smartctl to the installed agent |
| `linux/install-smarthealth.sh` | Linux install script: adds the module and installs smartmontools if needed |
| `windows\smartctl.exe`, `windows\drivedb.h` | smartmontools 7.5, 64-bit, and its drive database. Checksums match the official release. |
| `LICENSE-glpi-agent.txt` | License of the module: GPL-2.0-or-later, the same as GLPI Agent |
| `windows\smartmontools-COPYING.txt` | smartmontools license: GPL-2.0-or-later |
| [`../glpi-agent-smarthealth.patch`](../glpi-agent-smarthealth.patch) | The same change as a patch to the agent source, for building your own installer (option B) |

There are two ways to deploy it: add it to your existing agents (option A, quicker) or build a custom agent installer (option B).

## Option A: add to your existing agents (no rebuild)

Tested with GLPI Agent 1.19 and 1.20, the current release, including an upgrade from 1.19 to 1.20 with the files in place. No agent setting changes are needed. The module is picked up at the next inventory, without restarting the service.

The result is the same as the custom installer (option B): the same module and smartctl. The agent itself stays the official one, signed by Teclib, so a new agent release needs no rebuild.

### Windows

There are two ways to add the files: Group Policy file copies, or the install script. Both give the same result.

#### With Group Policy file copies

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
   - **Action:** **Replace** for `SmartHealth.pm`. Replace overwrites the file at every policy refresh, so a new version of the module put on the share reaches every PC. The file is under 20 KB.
   - **Action:** **Update** for `smartctl.exe` and `drivedb.h`. Update copies a file only when it's missing and never overwrites an existing one, which avoids re-sending 1.4 MB every 90 minutes. To roll out a new smartctl later, switch these two items to Replace for a day.

#### With the install script

`windows\Install-SmartHealth.ps1` copies the same files, plus the smartmontools license, into the agent folder, wherever the agent is installed. Keep it in this folder: it takes `SmartHealth.pm` from the folder above. In an **Administrator** command prompt:

```bat
powershell.exe -NoProfile -ExecutionPolicy Bypass -File windows\Install-SmartHealth.ps1
```

- It ends with a quick check that shows the SMART data and volumes found on each disk.
- You can run it again at any time: files already up to date are left alone.
- `-Uninstall` removes the files. If the agent itself was uninstalled first, `-Uninstall` removes the files left behind.
- On a PC with the custom installer (option B), it does nothing, as that installer already includes the files.

To run it on every PC at startup through Group Policy, instead of the file copies:

1. Put this folder on a share that **Domain Computers** can read.
2. In a GPO linked to your computers' OU, go to **Computer Configuration > Policies > Windows Settings > Scripts (Startup/Shutdown) > Startup**. Use the **Scripts** tab rather than the PowerShell Scripts tab, so the execution policy doesn't block the unsigned script.
3. Add:
   - **Script name:** `powershell.exe`
   - **Script parameters:** `-NoProfile -ExecutionPolicy Bypass -File \\fileserver\SsdHealth$\glpi-agent-addon\windows\Install-SmartHealth.ps1 -SkipCheck -LogPath C:\Windows\Temp\SmartHealth.log`

The script then runs as SYSTEM at each startup and only copies what changed. Other deployment tools, such as Intune or PDQ Deploy, can run the same command.

### Linux

Run the install script as root. It downloads the module itself, so it doesn't need the rest of this folder:

```sh
curl -fsSL https://raw.githubusercontent.com/phatdo1819/glpi-ssd-health/main/glpi-agent-addon/linux/install-smarthealth.sh | sudo sh
```

Or, with this folder copied to the PC: `sudo sh linux/install-smarthealth.sh`.

The script:

1. finds GLPI Agent, installed from its `.deb` or `.rpm` packages or its Linux installer;
2. downloads `SmartHealth.pm` from this repository with curl or wget, and checks that it is the module and compiles. Without internet access, it uses the `SmartHealth.pm` of this folder instead;
3. installs smartmontools with apt, dnf, yum or zypper if `smartctl` is missing, and checks it's version 7.0 or later;
4. copies the module to `/usr/share/glpi-agent/lib/GLPI/Agent/Task/Inventory/Generic/Storages/`, replacing a broken copy, and removes other copies such as `Smarthealth.pm`. A `SmartHealth.pm` saved from a github.com page is the web page, not the module, and the agent can't load it;
5. runs a quick inventory and lists every disk with its health, SMART status and volumes. When no disk has SMART data, it also shows the devices smartctl finds and what the module logged, to explain why.

Disks are found by smartctl, whatever their names: `/dev/sda` for SATA, `/dev/nvme0` for NVMe (reported in GLPI as `nvme0n1`). Partitions such as `/dev/nvme0n1p2` are shown as volumes of their disk.

Run it again to update the module. Options, added after `sh linux/install-smarthealth.sh`, or after `sudo sh -s --` when piped from curl:

| Option | What it does |
|---|---|
| `--check` | Only shows the disks and SMART data the agent finds |
| `--local` | Uses the `SmartHealth.pm` of this folder, without downloading |
| `--uninstall` | Removes the module and leaves smartmontools |

To download the module from your own server instead of GitHub, set `SMARTHEALTH_URL`, for example `sudo SMARTHEALTH_URL=https://intranet.example/SmartHealth.pm sh install-smarthealth.sh`.

For many PCs, run the script through your usual tool, such as Ansible, or over SSH, for example:

```sh
for pc in pc1 pc2 pc3; do
    ssh "$pc" 'curl -fsSL https://raw.githubusercontent.com/phatdo1819/glpi-ssd-health/main/glpi-agent-addon/linux/install-smarthealth.sh | sudo sh'
done
```

To do it by hand instead: install smartmontools 7.0 or later, then copy `SmartHealth.pm` to the folder above.

The agent's snap package is read-only and can't take the module.

### Check it on one PC

In an **Administrator** command prompt:

```bat
"C:\Program Files\GLPI-Agent\glpi-inventory.bat" --partial storage,storage_health
```

On Linux, run `glpi-inventory --partial storage,storage_health` as root.

In the JSON output, your SSDs should now have fields such as `"smart_health": 93` and `"smart_status": "PASSED"`. To send an inventory to GLPI straight away instead of waiting for the next scheduled one, run `glpi-agent --force`.

## Option B: your own agent installer

**The installer for 1.20 is ready:** `GLPI-Agent-1.20-smarthealth-1.1-x64.msi` on the [Releases page](https://github.com/phatdo1819/glpi-ssd-health/releases/latest). It has this add-on's module 1.1, with the volumes. On the test VM, the agent from the unpacked MSI reported the same SMART data and volumes as option A. It hasn't been installed on a PC yet. The first build, `GLPI-Agent-1.20-smarthealth-x64.msi` on the 1.0.0 and 1.1.0 releases, has no volumes.

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
- Programs and Features lists it as **GLPI Agent 1.20 (git05c29b27)**, and the agent reports its version as `1.20-git05c29b27`. The part after `git` is the fork commit it was built from.
- It replaces any installed GLPI Agent, official or custom, the same way the official MSI does.
- It keeps the installed agent's settings, such as the server and tag, unless you pass new ones or `CONFIG=reset`. That's the official installer's behavior, unchanged.
- It takes the same install options as the official MSI, for example `SERVER=...`.

On Linux, use option A. The fork also builds Linux packages, but they carry a different version number from the official ones, so package managers would mix them up with official updates.

## When GLPI Agent is upgraded

**Option A: nothing to redo.**

- The agent installer keeps these files during an upgrade. It only removes the files it installed itself. Tested on Windows from 1.19 to 1.20: all four files stayed unchanged and SMART data kept working.
- On Linux, package upgrades leave the module in place, as no package owns that file. Tested on Debian from 1.20 to 1.19 and back to 1.20.
- Moving a PC from the custom installer (option B) to the official one removes SSD health, as those files belonged to the custom installer. The Group Policy file copies or the startup script add them back at the next refresh or startup, or run the install script once.
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

- **Windows with Group Policy file copies:** put the new `SmartHealth.pm` on the share. The Replace item copies it at the next policy refresh, and the agent uses it at its next inventory. No restart is needed.
- **Windows with the install script:** put the new add-on folder on the share, or run the script from it. It copies only what changed.
- **Linux:** run the install script from the new folder.

## Licenses

| Part | License | Included |
|---|---|---|
| `SmartHealth.pm`, the patch | GPL-2.0-or-later, like GLPI Agent | `LICENSE-glpi-agent.txt` |
| smartctl, drivedb.h | GPL-2.0-or-later (smartmontools) | `windows\smartmontools-COPYING.txt`, and the source code in `third-party/` at the repository root. The installer build (option B) also puts the license next to smartctl.exe. |
| `windows\Install-SmartHealth.ps1`, `linux/install-smarthealth.sh` | MIT | `LICENSE` at the repository root |
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
| `smart_volumes` | Volumes on the disk: drive letters and labels on Windows, such as `C:, D: (Data)`; mount points and labels on Linux, such as `/boot/efi, /` |

How the health % is chosen, in order:

1. NVMe "Percentage Used".
2. The standard SATA device statistic "Percentage Used Endurance Indicator".
3. Vendor attributes named like `SSD_Life_Left`, `Percent_Lifetime_Remain`, `Media_Wearout_Indicator` or `Wear_Leveling_Count`.
4. The SAS endurance indicator.

CrystalDiskInfo shows a % only for SSDs it has a rule for, and just "Good" for the others. The module can still find a % for many of those: the standard indicator in step 2 is the drive's own wear counter, and smartmontools' drive database names the vendor attributes of thousands of models. GLPI shows the source under each drive's %, so you can see which one was used.

Disks that smartctl can't read are skipped, and their inventory is unchanged.
