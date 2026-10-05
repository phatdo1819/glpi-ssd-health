#!/bin/sh
# SPDX-License-Identifier: MIT
#
# Adds the SSD health module to GLPI Agent on Linux and installs smartmontools if needed.
# The script downloads the module, so it can run on its own:
#
#   curl -fsSL https://raw.githubusercontent.com/phatdo1819/glpi-ssd-health/main/glpi-agent-addon/linux/install-smarthealth.sh | sudo sh
#
#   sudo sh install-smarthealth.sh              install or update the module (downloads it)
#   sudo sh install-smarthealth.sh --local      use SmartHealth.pm from the add-on folder, no download
#   sudo sh install-smarthealth.sh --check      only show the SMART data the agent finds
#   sudo sh install-smarthealth.sh --uninstall  remove the module (smartmontools stays)
#
# With curl | sudo sh, pass options after "-s --", for example: ... | sudo sh -s -- --check
# Set SMARTHEALTH_URL to download the module from somewhere else, such as an internal server:
#   sudo SMARTHEALTH_URL=https://intranet.example/SmartHealth.pm sh install-smarthealth.sh
#
# Works with GLPI Agent installed from its .deb or .rpm packages or its Linux installer.
# The snap package is read-only and can't take extra modules.

set -eu

MODULE_URL=${SMARTHEALTH_URL:-https://raw.githubusercontent.com/phatdo1819/glpi-ssd-health/main/glpi-agent-addon/SmartHealth.pm}
AGENT_LIB=/usr/share/glpi-agent/lib
MODULE_DIR="$AGENT_LIB/GLPI/Agent/Task/Inventory/Generic/Storages"
MODULE_PACKAGE='GLPI::Agent::Task::Inventory::Generic::Storages::SmartHealth'

# smartctl is in /usr/sbin, which isn't always in PATH
PATH="$PATH:/usr/sbin:/sbin"

fail() {
    echo "Error: $*" >&2
    exit 1
}

action=install
case "${1:-}" in
    "") ;;
    --local) action=local ;;
    --check) action=check ;;
    --uninstall) action=uninstall ;;
    *) fail "unknown option: $1 (use --local, --check or --uninstall)" ;;
esac

[ "$(id -u)" -eq 0 ] || fail "run this script as root, for example with sudo"

if [ ! -f "$AGENT_LIB/GLPI/Agent.pm" ]; then
    if [ -d /snap/glpi-agent ]; then
        fail "the snap package of GLPI Agent is read-only. Install the agent from its .deb or .rpm package or its Linux installer instead."
    fi
    fail "GLPI Agent not found in $AGENT_LIB. Install it first."
fi

if [ "$action" = uninstall ]; then
    rm -f "$MODULE_DIR/SmartHealth.pm"
    echo "SSD health module removed. Disks are reported without SMART data from the next inventory."
    exit 0
fi

TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT

# A module that isn't the SSD health module, or doesn't compile, would break
# the agent's whole inventory: never install one
module_ok() {
    head -n 1 "$1" | grep -q "^package $MODULE_PACKAGE;" || return 1
    if command -v perl > /dev/null 2>&1; then
        perl -I"$AGENT_LIB" -c "$1" > /dev/null 2>&1 || return 1
    fi
}

download() {
    if command -v curl > /dev/null 2>&1; then
        curl -fsSL --retry 2 --connect-timeout 20 -o "$2" "$1"
    elif command -v wget > /dev/null 2>&1; then
        wget -q --tries=2 --timeout=20 -O "$2" "$1"
    else
        echo "neither curl nor wget is installed" >&2
        return 1
    fi
}

# The module next to this script, or one folder up as in the add-on folder.
# Nothing when the script is piped from curl.
local_module() {
    [ -f "$0" ] || return 1
    script_dir=$(cd "$(dirname "$0")" && pwd)
    for file in "$script_dir/SmartHealth.pm" "$script_dir/../SmartHealth.pm"; do
        if [ -f "$file" ]; then
            echo "$file"
            return 0
        fi
    done
    return 1
}

# Writes the path of the module to install
get_module() {
    if [ "$action" = local ]; then
        file=$(local_module) || fail "SmartHealth.pm not found next to this script or in its parent folder"
        module_ok "$file" || fail "$file isn't a working SSD health module"
        echo "$file"
        return
    fi

    echo "Downloading the SSD health module from $MODULE_URL" >&2
    file="$TMP_DIR/SmartHealth.pm"
    if download "$MODULE_URL" "$file" && module_ok "$file"; then
        echo "$file"
        return
    fi

    # Offline PCs can still use the copy from the add-on folder
    if local=$(local_module) && module_ok "$local"; then
        echo "Download failed: using $local instead" >&2
        echo "$local"
        return
    fi
    fail "could not download a working SSD health module from $MODULE_URL. Check the internet access of this PC, or copy the whole glpi-agent-addon folder here and run: sudo sh linux/install-smarthealth.sh --local"
}

install_smartmontools() {
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
}

# Every disk the agent reports, with its SMART data and volumes
show_check() {
    if ! command -v glpi-inventory > /dev/null 2>&1; then
        echo "Check skipped: glpi-inventory not found"
        return
    fi
    if ! glpi-inventory --partial storage,storage_health --json --debug --debug \
            > "$TMP_DIR/inventory.json" 2> "$TMP_DIR/inventory.log"; then
        echo "Check skipped: the quick inventory failed:"
        tail -n 5 "$TMP_DIR/inventory.log" | sed 's/^/  /'
        return
    fi

    # The number of disks with SMART data goes to a file, the report to the screen
    if ! perl -MJSON::PP -e '
        my ($count_file) = @ARGV;
        local $/;
        my $data = eval { decode_json(<STDIN>) } or exit 1;
        my $storages = $data->{content}->{storages} || [];
        my $found = 0;
        print @{$storages} ? "Check: disks reported by the agent:\n" : "Check: the agent reported no disks\n";
        foreach my $disk (sort { ($a->{name} // "") cmp ($b->{name} // "") } @{$storages}) {
            my $name = join(" ", grep { defined && length } $disk->{name}, $disk->{model});
            my @facts;
            if (defined($disk->{smart_health}) || defined($disk->{smart_status})) {
                $found++;
                push @facts, "health " . (defined($disk->{smart_health}) ? "$disk->{smart_health}%" : "no wear counter");
                push @facts, "SMART $disk->{smart_status}" if defined($disk->{smart_status});
                push @facts, "volumes $disk->{smart_volumes}" if defined($disk->{smart_volumes});
            } else {
                push @facts, "no SMART data";
            }
            print "  $name: ", join(", ", @facts), "\n";
        }
        open(my $fh, ">", $count_file) or exit 1;
        print $fh "$found\n";
    ' "$TMP_DIR/found" < "$TMP_DIR/inventory.json"; then
        echo "Check skipped: could not read the quick inventory"
        return
    fi
    found=$(cat "$TMP_DIR/found")

    if [ "${found:-0}" -gt 0 ]; then
        echo "SMART data reaches GLPI with the next inventory, or now with: glpi-agent --force"
        return
    fi

    # Explain why: what smartctl sees and what the module said about each device
    echo "No SMART data found. Virtual machine disks and some USB or RAID disks report none."
    echo "Devices smartctl finds:"
    smartctl --scan-open 2>&1 | sed 's/^/  /'
    if grep -q 'SmartHealth\|SMART health' "$TMP_DIR/inventory.log"; then
        echo "What the SSD health module logged:"
        grep 'SmartHealth\|SMART health' "$TMP_DIR/inventory.log" | tail -n 20 | sed 's/^/  /'
    fi
}

if [ "$action" = check ]; then
    show_check
    exit 0
fi

module=$(get_module)
install_smartmontools

if cmp -s "$module" "$MODULE_DIR/SmartHealth.pm"; then
    echo "SSD health module already up to date in $MODULE_DIR"
else
    install -m 0644 "$module" "$MODULE_DIR/SmartHealth.pm"
    echo "SSD health module installed in $MODULE_DIR"
fi

show_check
