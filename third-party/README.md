# Third-party source code

This repository includes Windows binaries from **smartmontools 7.5**: `smartctl.exe` and `drivedb.h`, in `ssd-health/bin/` and `glpi-agent-addon/windows/`. They were taken unmodified from the official `smartmontools-7.5.win32-setup.exe` installer.

smartmontools is licensed under the GNU GPL, version 2 or later. The license text is next to each copy, as `smartmontools-COPYING.txt`. Its complete corresponding source code is here:

| File | From | SHA-256 |
|---|---|---|
| `smartmontools-7.5.tar.gz` | <https://github.com/smartmontools/smartmontools/releases/tag/RELEASE_7_5> | `690b83ca331378da9ea0d9d61008c4b22dde391387b9bbad7f29387f2595f76e` |

Checksums of the bundled binaries, which match the official installer:

| File | SHA-256 |
|---|---|
| `smartctl.exe` (64-bit) | `b5db94e5082c042be44994b7a4fa8f7b5c8e713b2ab1c9a560d8f7a7995ea27d` |
| `drivedb.h` | `dd39c6a520d38895da61923fe26fe7c9c5eb3f42325f2e4477a06ed7a61966d0` |
