# SSD Health Report

Collects SSD health (% of rated life left, the same idea as CrystalDiskInfo's "Health") from every Windows PC through Group Policy, then combines the results into one list of drives to replace.

| File | Runs on | What it does |
|---|---|---|
| `bin\Get-SsdHealth.ps1` | Each PC, as SYSTEM | Reads every disk with smartctl and writes `<COMPUTERNAME>.csv` to the share |
| `bin\smartctl.exe`, `bin\drivedb.h` | Each PC | smartmontools 7.5, 64-bit, plus its drive database |
| `Merge-SsdHealthReports.ps1` | Your admin PC | Combines all CSVs into `SsdHealth-Summary.csv`, worst drives first |

## 1. About smartctl

`bin\smartctl.exe` and `bin\drivedb.h` were taken from the official `smartmontools-7.5.win32-setup.exe` at <https://github.com/smartmontools/smartmontools/releases/tag/RELEASE_7_5>. Its checksums matched the release (smartctl.exe SHA-256 `b5db94e5082c042be44994b7a4fa8f7b5c8e713b2ab1c9a560d8f7a7995ea27d`).

To get them yourself, open that installer with 7-Zip (right-click > 7-Zip > Open archive) and copy `bin\smartctl.exe` and `bin\drivedb.h`. Despite the "win32" name, it contains the 64-bit build. For 32-bit Windows, use the files in `bin32\` instead.

## 2. Create the share

Example: `\\fileserver\SsdHealth$` (the `$` hides it from network browsing).

```
SsdHealth$\
  bin\       copy everything from this folder's bin\
  reports\   each PC writes <COMPUTERNAME>.csv here
```

The task runs as SYSTEM, which reaches the network as the PC's computer account. Set NTFS permissions:

| Folder | Domain Computers | IT admins |
|---|---|---|
| `bin` | Read & execute | Full control |
| `reports` | Modify | Full control |

Share permissions: Authenticated Users = Change, Administrators = Full Control. The NTFS permissions above do the real restriction.

## 3. Test on one PC first

On a PC with a real SSD, in PowerShell **as Administrator**:

```powershell
powershell -ExecutionPolicy Bypass -File \\fileserver\SsdHealth$\bin\Get-SsdHealth.ps1 |
    Format-Table Disk, Model, HealthPercent, HealthSource, Status, Problems -AutoSize
```

Compare `HealthPercent` with CrystalDiskInfo on the same PC. Do this once for each SSD brand you have.

## 4. Group Policy

1. In Group Policy Management, create a GPO named **SSD Health Report** and link it to the OU that holds your computers.
2. Edit it: **Computer Configuration > Preferences > Control Panel Settings > Scheduled Tasks**, then right-click > **New > Scheduled Task (At least Windows 7)**.

| Tab | Setting |
|---|---|
| General | Action: **Update**. Name: `SSD Health Report`. Click **Change User or Group**, type `SYSTEM`, click **Check Names** and **OK**. Tick **Run with highest privileges**. Configure for: **Windows 10** (covers Windows 11 too). |
| Triggers | New > **On a schedule**, **Daily**, 10:00 AM. Under Advanced, tick **Delay task for up to (random delay): 1 hour** so PCs don't all hit the share at once. |
| Actions | New > **Start a program**. Program/script: `powershell.exe`. Add arguments: see the block after this table. |
| Conditions | **Untick** "Start the task only if the computer is on AC power" so laptops report too. |
| Settings | Tick **Run task as soon as possible after a scheduled start is missed**. Tick **Stop the task if it runs longer than: 30 minutes**. |

Arguments for the Actions tab:

```
-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "\\fileserver\SsdHealth$\bin\Get-SsdHealth.ps1" -ReportPath "\\fileserver\SsdHealth$\reports"
```

3. On a test PC, run `gpupdate /force`, open Task Scheduler, right-click **SSD Health Report** > **Run**, and check that `<PCNAME>.csv` appears in `reports`.

To change the thresholds, add for example `-WarnPercent 40 -CritPercent 15` to the end of the arguments.

## 5. Read the results

On your admin PC:

```powershell
.\Merge-SsdHealthReports.ps1 -ReportPath \\fileserver\SsdHealth$\reports
```

This prints a count per status and the drives to replace, and writes `SsdHealth-Summary.csv` (open it in Excel).

| Status | Meaning | What to do |
|---|---|---|
| `REPLACE NOW` | SMART failed, NVMe critical warning, failing SMART attribute, or health at or below 10% | Back up and replace first |
| `REPLACE SOON` | Health at or below 30%, pending or uncorrectable sectors, or NVMe media errors | Plan the replacement |
| `ERROR` | smartctl couldn't read the disk; the reason is in `Problems` | Check the PC |
| `UNKNOWN` | SSD that reports no wear counter | Check it with CrystalDiskInfo or the vendor's tool |
| `OK` | Nothing wrong found | Nothing |
| `VIRTUAL` | Virtual machine disk | Ignore |

Other useful columns:

- `HealthSource` shows where the % came from: the NVMe or SATA standard counter, or a vendor attribute.
- `WrittenTB` is the total data written; compare it with the drive's rated TBW.
- `ReportAgeDays` shows how old that PC's last report is. A large number means the PC stopped reporting.

## Limits

- Health % measures **wear**, like CrystalDiskInfo. An SSD can still die suddenly from a controller fault with no warning, so keep backups.
- Disks behind a hardware RAID controller, and some USB enclosures, may show `ERROR` or not appear.
- A PC that is off or away from the network writes its report the next time it can reach the share. A PC that never ran the task won't appear at all; compare the list with Active Directory.
- If your Group Policy forces the PowerShell execution policy to `AllSigned`, `-ExecutionPolicy Bypass` is ignored and you need to sign the script.
- The scripts in this folder are licensed GPL-3.0-or-later; see `LICENSE` at the repository root. smartctl and drivedb.h come from smartmontools, licensed GPL-2.0-or-later: the license text is in `bin\smartmontools-COPYING.txt`, and the source code is in `third-party/` at the repository root. Running all of this inside your company carries no obligations.
