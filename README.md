# SSD health in GLPI: overview and test plan

See how much life each SSD has left, like CrystalDiskInfo's Health %, for every PC in GLPI, and get a list of drives to replace.

| Folder / file | What it is | Goes on |
|---|---|---|
| [`ssd-health/`](ssd-health/) | Standalone script that writes a CSV report through Group Policy. It works without GLPI. | PCs, through a GPO scheduled task |
| [`glpi-agent-addon/`](glpi-agent-addon/) | GLPI Agent module plus smartctl, to add to your existing agents (option A) | Each PC, by copying 3 files through Group Policy |
| [`diskhealth/`](diskhealth/) | GLPI plugin: each drive's health, a fleet list, and alerts for admins | GLPI server, under `glpi/plugins/` |
| [`glpi-agent-smarthealth.patch`](glpi-agent-smarthealth.patch) | The agent change as a patch, for rebuilding the custom agent when a new agent version comes out | Nothing |
| [`screenshots/`](screenshots/) | What the plugin looks like in GLPI 11 and 10 | Nothing |

- **Ready-to-use packages** (plugin archives and zipped folders) are on the [Releases page](https://github.com/phatdo1819/glpi-ssd-health/releases/latest).
- **The custom agent installer (option B)**, `GLPI-Agent-1.20-smarthealth-x64.msi`, is on the same page. It's GLPI Agent 1.20 plus this change, built from the fork [phatdo1819/glpi-agent](https://github.com/phatdo1819/glpi-agent), branch `smart-health`. The fork's [Releases page](https://github.com/phatdo1819/glpi-agent/releases/latest) has the same file.

How the pieces fit together:

1. On each PC, the agent module runs smartctl during the normal inventory.
2. It adds `smart_*` fields to each disk in the inventory.
3. GLPI accepts these extra fields as they are; nothing in GLPI itself needs changing.
4. The plugin saves the fields and shows each drive's health on the computer page and in the fleet list.
5. It tells admins about drives to replace in four ways:
   - warnings on the home page and on the computer's page
   - a daily email
   - dashboard cards
   - optionally, a ticket per drive

## Test plan

1. **GLPI server.** Install the plugin: copy `diskhealth` into `glpi/plugins/`, then **Setup > Plugins > Install > Enable**. Nothing shows yet.
2. **One or two test PCs with real SSDs**, ideally different brands and including at least one SATA SSD:
   1. Copy the three files from `glpi-agent-addon` by hand. The paths are in its README.
   2. In an Administrator command prompt, run:

      ```bat
      "C:\Program Files\GLPI-Agent\glpi-inventory.bat" --partial storage,storage_health
      ```

      Check that each SSD now has `smart_health`.
   3. Open CrystalDiskInfo on the same PC and compare its Health % with `smart_health`.
   4. Send an inventory now: open <http://127.0.0.1:62354/now> on that PC, or run `"C:\Program Files\GLPI-Agent\glpi-agent.bat" --force`.
3. **GLPI.** Open the PC and check its **Disk health** tab. Then check **Assets > Disk health**.
4. **Alerts.** Healthy drives trigger no alerts, so to see them, set "Replace soon" to 100 in **Setup > Plugins > Disk health**. Every SSD that reports its wear then counts as "replace soon". Then check:
   1. **Warnings:** the home page and the PC's page show a warning.
   2. **Email:** turn on email notifications in GLPI (see [`diskhealth/README.md`](diskhealth/README.md#email-alerts)), then run **Setup > Automatic actions > diskhealthalert > Execute**. The administrator address gets the email.
   3. **Tickets**, if you want them: set them to "When it needs replacing soon or now", then run the action again.

   Set "Replace soon" back to 30 afterwards.
5. **Report back** for each drive: the model, CrystalDiskInfo's %, GLPI's %, and any drive showing **Unknown** or **No SMART data**.

After that, roll out the agent files with the Group Policy steps in [`glpi-agent-addon/README.md`](glpi-agent-addon/README.md).

## What was tested

Everything below ran on a Windows 11 test VM:

- **Agent module:** 36 unit tests pass on both agent 1.20 and the development branch. The existing inventory tests still pass.
- **Real agent run:** stock GLPI Agent 1.20 with the module ran a real inventory here and sent it to both test servers. This VM has a VMware virtual NVMe disk, which the plugin flags as a virtual disk.
- **Missing module:** the inventory still works when one of the storage modules the health module waits for is missing, as could happen in a future agent release.
- **Custom agent installer (option B):** GitHub Actions ran the agent's tests and built the installers for Windows, Linux and macOS without errors. The Windows MSI contains the module, smartctl 7.5 and drivedb.h, and has the official upgrade code. The agent from the unpacked MSI reported the same SMART data as option A.
- **GLPI 10.0.28 and 11.0.11** (PHP 8.3, MariaDB 11.4):
  - plugin install and uninstall
  - data from the real agent, plus seven simulated drives covering every status
  - health updates, a drive removed, and a computer purged
  - the daily cleanup task
  - the computer tab and the fleet list, including sorting, filters and CSV export
  - the settings page
  - upgrading the plugin from 1.0.0 to 1.1.0, keeping its data
- **Alerts (1.1.0)**, on both GLPI versions:
  - the home page and computer warnings, and turning them off
  - both dashboard cards
  - email alerts: first alert, no repeat, reminders, a drive getting worse, and a drive back to OK then failing again
  - tickets, one per drive, with category, group and linked computer
  - uninstall removing the email notification, its template and the dashboard cards
  - emails were checked in GLPI's outgoing queue; no mail server was used

What was **not** tested:

- Real physical SSDs, which is what the test plan above covers.
- Linux agents.
- Several entities and user profiles.
- A large number of PCs.
- Installing the custom agent installer on a PC. It was only unpacked here.
- Sending the alert emails through a real mail server.

## Upkeep

| When | What to do |
|---|---|
| GLPI Agent is upgraded | **Option A:** nothing. The files stay in place, and Group Policy copies them back if they're removed. Check one PC with `glpi-inventory --partial storage,storage_health`. **Option B:** re-apply the patch to the new release and rebuild the MSI. |
| GLPI is upgraded within 10.0.x or 11.0.x | Nothing |
| GLPI moves to a newer branch (11.1, 12…) | Test the plugin, then raise `PLUGIN_DISKHEALTH_MAX_GLPI` in `diskhealth/setup.php` |
| The module changes | Replace `SmartHealth.pm` on the share |

## License

This repository is licensed **MIT** (see [`LICENSE`](LICENSE)), except:

- `glpi-agent-addon/SmartHealth.pm` and `glpi-agent-smarthealth.patch`: GPL-2.0-or-later, like GLPI Agent. See `glpi-agent-addon/LICENSE-glpi-agent.txt`.
- `smartctl.exe` and `drivedb.h`: smartmontools 7.5, GPL-2.0-or-later, included unmodified. Their license text sits next to each copy, and their source code is in [`third-party/`](third-party/).

You're welcome to use, change and share all of it under those licenses. smartctl is a separate program that the scripts and the agent run, so shipping it next to MIT code is allowed: the GPL calls this "mere aggregation". It only requires smartctl's own license and source to travel with it.

## Using it in your organization

Checked on 2026-10-04 against the license files shipped with each product.

| Software | License |
|---|---|
| GLPI 10 and 11 | GPL-3.0-or-later. This is the standard GPL, not the AGPL, so running it as an internal web application creates no obligations. |
| GLPI Agent, the module, the patch | GPL-2.0-or-later |
| smartmontools (smartctl, drivedb.h) | GPL-2.0-or-later. The Windows binary uses only Windows system DLLs; its compiler runtime is permissively licensed. |
| Disk health plugin, report scripts | MIT |

**Inside an organization: free to use, change and install on any number of PCs, no fees and no obligations.**

- GPLv3 §2: "You may make, run and propagate covered works that you do not convey, without conditions".
- GPLv2 §0: "The act of running the Program is not restricted".
- FSF GPL FAQ (#InternalDistribution): copies made and installed within one organization are not "distribution".

**Obligations start only when you give copies outside your organization**, including to contractors working off-site and to clients. You must then provide the source code and the license texts.

Both public repositories count as distribution, and meet those conditions:

- **This repository** contains the source code, every license text, and the smartmontools source in `third-party/`. The Releases page also offers that source archive.
- **The agent fork [`phatdo1819/glpi-agent`](https://github.com/phatdo1819/glpi-agent)** contains the source code, and the smartmontools source and license next to smartctl.exe in `contrib/windows/packaging/tools/`. Each of its five changed agent files carries a "Modified 2026-10-04" notice, as GPLv2 §2(a) requires. It's also the source code of the custom agent installer on the Releases page, so keep the fork for as long as the installer is published.

The GPL covers copyright, not trademarks. Using the GLPI name internally is fine, but don't present your custom build to others as the official GLPI Agent.
