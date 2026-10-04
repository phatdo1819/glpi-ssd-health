# SSD health in GLPI: overview and test plan

See how much life each SSD has left, like CrystalDiskInfo's Health %, for every PC in GLPI, and get a list of drives to replace.

| Folder / file | What it is | Goes on |
|---|---|---|
| [`ssd-health/`](ssd-health/) | Standalone script that writes a CSV report through Group Policy. It works without GLPI. | PCs, through a GPO scheduled task |
| [`glpi-agent-addon/`](glpi-agent-addon/) | GLPI Agent module plus smartctl, to add to your existing agents (option A) | Each PC, by copying 3 files through Group Policy |
| [`diskhealth/`](diskhealth/) | GLPI plugin | GLPI server, under `glpi/plugins/` |
| [`glpi-agent-smarthealth.patch`](glpi-agent-smarthealth.patch) | The agent change as a patch, for rebuilding the custom agent when a new agent version comes out | Nothing |
| [`screenshots/`](screenshots/) | What the plugin looks like in GLPI 11 and 10 | Nothing |

- **Ready-to-use packages** (plugin archives and zipped folders) are on the [Releases page](https://github.com/phatdo1819/glpi-ssd-health/releases/latest).
- **The custom agent installer (option B)** is built from the fork [phatdo1819/glpi-agent](https://github.com/phatdo1819/glpi-agent), branch `smart-health`: GLPI Agent 1.20 plus this change.

How the pieces fit together:

1. On each PC, the agent module runs smartctl during the normal inventory.
2. It adds `smart_*` fields to each disk in the inventory.
3. GLPI accepts these extra fields as they are; nothing in GLPI itself needs changing.
4. The plugin saves the fields and shows each drive's health on the computer page and in the fleet list.

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
4. **Report back** for each drive: the model, CrystalDiskInfo's %, GLPI's %, and any drive showing **Unknown** or **No SMART data**.

After that, roll out the agent files with the Group Policy steps in [`glpi-agent-addon/README.md`](glpi-agent-addon/README.md).

## What was tested

Everything below ran on a Windows 11 test VM:

- **Agent module:** 36 unit tests pass on both agent 1.20 and the development branch. The existing inventory tests still pass.
- **Real agent run:** stock GLPI Agent 1.20 with the module ran a real inventory here and sent it to both test servers. This VM has a VMware virtual NVMe disk, which the plugin flags as a virtual disk.
- **Missing module:** the inventory still works when one of the storage modules the health module waits for is missing, as could happen in a future agent release.
- **GLPI 10.0.28 and 11.0.11** (PHP 8.3, MariaDB 11.4):
  - plugin install and uninstall
  - data from the real agent, plus seven simulated drives covering every status
  - health updates, a drive removed, and a computer purged
  - the daily cleanup task
  - the computer tab and the fleet list, including sorting, filters and CSV export
  - the settings page

What was **not** tested:

- Real physical SSDs, which is what the test plan above covers.
- Linux agents.
- Several entities and user profiles.
- A large number of PCs.
- Building the custom agent installer (option B). It's built by GitHub Actions on a fork of the agent project, which needs your GitHub account.

## Upkeep

| When | What to do |
|---|---|
| GLPI Agent is upgraded | **Option A:** nothing. The files stay in place, and Group Policy copies them back if they're removed. Check one PC with `glpi-inventory --partial storage,storage_health`. **Option B:** re-apply the patch to the new release and rebuild the MSI. |
| GLPI is upgraded within 10.0.x or 11.0.x | Nothing |
| GLPI moves to a newer branch (11.1, 12…) | Test the plugin, then raise `PLUGIN_DISKHEALTH_MAX_GLPI` in `diskhealth/setup.php` |
| The module changes | Replace `SmartHealth.pm` on the share |

## Licenses

Checked on 2026-10-04 against the license files shipped with each product.

| Software | License |
|---|---|
| GLPI 10 and 11 | GPL-3.0-or-later. This is the standard GPL, not the AGPL, so running it as an internal web application creates no obligations. |
| GLPI Agent, the module, the patch | GPL-2.0-or-later |
| smartmontools (smartctl, drivedb.h) | GPL-2.0-or-later. The Windows binary uses only Windows system DLLs; its compiler runtime is permissively licensed. |
| Disk health plugin | GPL-3.0-or-later |

**Inside the company: free to use, change and install on any number of PCs, no fees and no obligations.**

- GPLv3 §2: "You may make, run and propagate covered works that you do not convey, without conditions".
- GPLv2 §0: "The act of running the Program is not restricted".
- FSF GPL FAQ (#InternalDistribution): copies made and installed within one organization are not "distribution".

**Obligations start only when you give copies outside the company**, including to contractors working off-site and to clients. You must then provide the source code and the license texts.

**The public fork `phatdo1819/glpi-agent` counts as distribution.** Its obligations are met:

- The repository is the source code.
- The smartmontools source and license sit next to smartctl.exe in `contrib/windows/packaging/tools/`.
- Each of the five changed agent files carries a "Modified 2026-10-04" notice, as GPLv2 §2(a) requires.

The GPL covers copyright, not trademarks. Using the GLPI name internally is fine, but don't present your custom build to others as the official GLPI Agent.
