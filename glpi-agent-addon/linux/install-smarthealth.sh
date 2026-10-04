#!/bin/sh
# SPDX-License-Identifier: MIT
#
# Adds the SSD health module to GLPI Agent on Linux and installs smartmontools if needed.
#
#   sudo sh install-smarthealth.sh              install or update the module
#   sudo sh install-smarthealth.sh --uninstall  remove the module (smartmontools stays)
#
# Works with GLPI Agent installed from its .deb or .rpm packages or its Linux installer.
# The snap package is read-only and can't take extra modules.

set -eu

AGENT_LIB=/usr/share/glpi-agent/lib
MODULE_DIR="$AGENT_LIB/GLPI/Agent/Task/Inventory/Generic/Storages"
SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)

fail() {
    echo "Error: $*" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || fail "run this script as root, for example with sudo"

if [ ! -f "$AGENT_LIB/GLPI/Agent.pm" ]; then
    if [ -d /snap/glpi-agent ]; then
        fail "the snap package of GLPI Agent is read-only. Install the agent from its .deb or .rpm package or its Linux installer instead."
    fi
    fail "GLPI Agent not found in $AGENT_LIB. Install it first."
fi

if [ "${1:-}" = "--uninstall" ]; then
    rm -f "$MODULE_DIR/SmartHealth.pm"
    echo "SSD health module removed. Disks are reported without SMART data from the next inventory."
    exit 0
fi

# The module sits next to this script, or one folder up as in the add-on folder
MODULE="$SCRIPT_DIR/SmartHealth.pm"
[ -f "$MODULE" ] || MODULE="$SCRIPT_DIR/../SmartHealth.pm"
[ -f "$MODULE" ] || fail "SmartHealth.pm not found next to this script or in its parent folder"

if ! command -v smartctl > /dev/null 2>&1; then
    echo "Installing smartmontools..."
    if command -v apt-get > /dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq smartmontools > /dev/null
    elif command -v dnf > /dev/null 2>&1; then
        dnf install -y -q smartmontools > /dev/null
    elif command -v yum > /dev/null 2>&1; then
        yum install -y -q smartmontools > /dev/null
    elif command -v zypper > /dev/null 2>&1; then
        zypper --non-interactive --quiet install smartmontools > /dev/null
    else
        fail "no known package manager found: install smartmontools 7.0 or later, then run this script again"
    fi
fi

# JSON output, which the module reads, came with smartmontools 7.0
version=$(smartctl --version | sed -n 's/^smartctl \([0-9][0-9]*\)\.\([0-9][0-9]*\).*/\1 \2/p' | head -n 1)
major=${version%% *}
[ -n "$major" ] && [ "$major" -ge 7 ] || fail "smartmontools 7.0 or later is needed, found: $(smartctl --version | head -n 1)"

install -m 0644 "$MODULE" "$MODULE_DIR/SmartHealth.pm"
echo "SSD health module installed in $MODULE_DIR"

# Quick check: disks with SMART data in a partial inventory
if command -v glpi-inventory > /dev/null 2>&1; then
    count=$(glpi-inventory --partial storage,storage_health --json 2> /dev/null | grep -c '"smart_' || true)
    if [ "$count" -gt 0 ]; then
        echo "Check: SMART data found. It reaches GLPI with the next inventory, or now with: glpi-agent --force"
    else
        echo "Check: no SMART data found. Virtual machine disks and some USB or RAID disks report none."
    fi
fi
